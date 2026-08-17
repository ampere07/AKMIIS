<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Query\Builder;

/**
 * How much work is sitting unattended behind each menu entry.
 *
 * Feeds the red pills on the sidebar and the count on the header bell. Polled
 * by every signed-in administrator, so every counter here is an indexed
 * count() — no row is ever hydrated.
 *
 * The open-task sets are defined by exclusion rather than inclusion wherever
 * the workflow allows it. Status columns in this schema are free text and have
 * accumulated spellings across releases; a counter written as "status IN (the
 * open ones)" silently drops a task the day someone adds a new intermediate
 * state, and a badge that under-reports is worse than no badge, because it is
 * trusted. Listing the *terminal* states instead means anything unrecognised
 * counts as still open — which is the safe direction to be wrong in.
 *
 * Comparisons go through LOWER(TRIM(...)). The connection collation is
 * utf8mb4_unicode_ci, which already folds case and ignores *trailing* spaces —
 * but not leading ones, and these columns are free text written by several
 * different forms that do produce ' Pending '. Matching on the raw column would
 * therefore undercount exactly the rows this badge exists to surface. The cost
 * is that these counts cannot use an index on the status column; see the note
 * on countWhere() for when that becomes worth fixing properly.
 */
class NavBadgeCountController extends Controller
{
    /** SuperAdmin sees every organization; everyone else is scoped to their own. */
    private const SUPERADMIN_ROLE_ID = 7;

    /** Service orders in any of these are closed. Anything else is open. */
    private const SERVICE_ORDER_TERMINAL = ['resolved', 'failed', 'cancelled'];

    /** Work orders in any of these are closed. Anything else is open. */
    private const WORK_ORDER_TERMINAL = ['completed', 'done', 'failed', 'cancelled'];

    /** A job order counts as needing attention when billing has not caught up with the field. */
    private const JOB_ORDER_BILLING_OPEN = ['in progress', 'inprogress'];
    private const JOB_ORDER_ONSITE_DONE = ['done', 'completed', 'finish'];

    /** Transactions still moving. */
    private const TRANSACTION_OPEN = ['pending', 'queued'];

    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            // The route is behind auth:sanctum, so this is belt-and-braces —
            // but returning zeros rather than counts is the right failure for a
            // caller we cannot identify.
            if (!$user) {
                return response()->json([
                    'success' => true,
                    'data' => $this->emptyCounts(),
                ]);
            }

            $organizationId = (int) ($user->role_id ?? 0) === self::SUPERADMIN_ROLE_ID
                ? null
                : ($user->organization_id ?? null);

            $counts = [
                'application' => $this->countApplications($organizationId),
                'job_order' => $this->countJobOrders($organizationId),
                'service_order' => $this->countServiceOrders($organizationId),
                'work_order' => $this->countWorkOrders($organizationId),
                'transaction' => $this->countTransactions($organizationId),
            ];

            $counts['total'] = array_sum($counts);

            return response()->json([
                'success' => true,
                'data' => $counts,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch navigation badge counts', [
                'error' => $e->getMessage(),
            ]);

            // A broken counter must not break the sidebar it decorates. The
            // client renders no badges on zeros, which is the correct
            // degradation: the menu still works.
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch badge counts',
                'data' => $this->emptyCounts(),
            ], 500);
        }
    }

    private function countApplications(?int $organizationId): int
    {
        return $this->scope(DB::table('applications'), $organizationId)
            ->whereRaw('LOWER(TRIM(status)) = ?', ['pending'])
            ->count();
    }

    /**
     * Installs the technician has finished but billing has not.
     *
     * Deliberately an inclusion set on both columns, unlike the others: this is
     * not "not yet closed", it is one specific hand-off gap — onsite done,
     * billing still in progress — and widening it would badge every job order
     * in the system.
     */
    private function countJobOrders(?int $organizationId): int
    {
        return $this->scope(DB::table('job_orders'), $organizationId)
            ->whereIn(DB::raw('LOWER(TRIM(billing_status))'), self::JOB_ORDER_BILLING_OPEN)
            ->whereIn(DB::raw('LOWER(TRIM(onsite_status))'), self::JOB_ORDER_ONSITE_DONE)
            ->count();
    }

    private function countServiceOrders(?int $organizationId): int
    {
        return $this->scope(DB::table('service_orders'), $organizationId)
            ->where(function (Builder $q) {
                // NULL is not caught by NOT IN, and an unset status is an open
                // ticket, so it has to be admitted explicitly.
                $q->whereNull('support_status')
                  ->orWhereNotIn(DB::raw('LOWER(TRIM(support_status))'), self::SERVICE_ORDER_TERMINAL);
            })
            ->count();
    }

    private function countWorkOrders(?int $organizationId): int
    {
        return $this->scope(DB::table('work_order'), $organizationId)
            ->where(function (Builder $q) {
                $q->whereNull('work_status')
                  ->orWhereNotIn(DB::raw('LOWER(TRIM(work_status))'), self::WORK_ORDER_TERMINAL);
            })
            ->count();
    }

    private function countTransactions(?int $organizationId): int
    {
        return $this->scope(DB::table('transactions'), $organizationId)
            ->whereIn(DB::raw('LOWER(TRIM(status))'), self::TRANSACTION_OPEN)
            ->count();
    }

    /**
     * Restrict a counter to the caller's organization.
     *
     * Note on cost: every counter above wraps its status column in
     * LOWER(TRIM(...)), which no index can serve, so each of these is a scan of
     * the rows matching the organization filter. That is acceptable while these
     * tables are in the tens of thousands and the poll is every 10 seconds. The
     * fix when it stops being acceptable is to normalise status on write — or a
     * generated column with an index on it — not to drop the TRIM, which is
     * load-bearing against real production data.
     *
     * A null id means SuperAdmin and no restriction. Rows with no organization
     * are included for a scoped caller: they predate multi-tenancy and belong
     * to the only organization those deployments have.
     */
    private function scope(Builder $query, ?int $organizationId): Builder
    {
        if ($organizationId === null) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($organizationId) {
            $q->where('organization_id', $organizationId)
              ->orWhereNull('organization_id');
        });
    }

    /**
     * @return array<string,int>
     */
    private function emptyCounts(): array
    {
        return [
            'application' => 0,
            'job_order' => 0,
            'service_order' => 0,
            'work_order' => 0,
            'transaction' => 0,
            'total' => 0,
        ];
    }
}
