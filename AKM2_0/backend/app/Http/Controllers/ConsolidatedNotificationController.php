<?php

namespace App\Http\Controllers;

use App\Services\JobOrderNotificationGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * The single stream behind the header bell.
 *
 * Four sources, one shape. Every entry carries `type` and `id`, and the header
 * uses that pair to route a click straight to the record — so the ids here must
 * stay the ids of the records themselves, not of any notification row.
 *
 * Only the revert stream is access-controlled, and it is controlled here rather
 * than in the client: pending reverts disclose which accounts are contesting
 * money, which is a SuperAdmin concern.
 */
class ConsolidatedNotificationController extends Controller
{
    /** SuperAdmin. Reverts are visible to this role and no other. */
    private const SUPERADMIN_ROLE_ID = 7;

    /**
     * How much wider than $limit the job-order query reads before suppression.
     *
     * The guard removes rows after the query, so reading exactly $limit would
     * hand back a short page whenever anything was withheld. The cap stops an
     * installation whose job orders are overwhelmingly pending from turning a
     * notification poll into a large scan.
     */
    private const SUPPRESSION_OVERFETCH = 3;
    private const SUPPRESSION_OVERFETCH_CAP = 150;

    private JobOrderNotificationGuard $jobOrderGuard;

    public function __construct(JobOrderNotificationGuard $jobOrderGuard)
    {
        $this->jobOrderGuard = $jobOrderGuard;
    }

    public function index(Request $request)
    {
        try {
            $limit = (int) $request->get('limit', 15);
            if ($limit < 1) {
                $limit = 15;
            }

            $applications = $this->fetchApplications($limit);
            $jobOrders = $this->fetchCompletedJobOrders($limit);
            $serviceOrders = $this->fetchCompletedServiceOrders($limit);
            $reverts = $this->fetchPendingReverts($request, $limit);

            $all = $applications
                ->concat($jobOrders)
                ->concat($serviceOrders)
                ->concat($reverts)
                ->sortByDesc('timestamp')
                ->take($limit)
                ->values();

            return response()->json([
                'success' => true,
                'data' => $all
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch consolidated notifications', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch notifications',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Applications still awaiting a decision.
     */
    private function fetchApplications(int $limit)
    {
        return DB::table('applications')
            ->where('status', 'Pending')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($app) {
                $createdAt = Carbon::parse($app->created_at);
                return [
                    'id' => $app->id,
                    'type' => 'application',
                    'customer_name' => trim(($app->first_name ?? '') . ' ' . ($app->last_name ?? '')),
                    'plan_name' => $app->desired_plan ?? 'Unknown Plan',
                    'title' => 'New Application',
                    'message' => 'New application received',
                    'timestamp' => $createdAt->timestamp,
                    'formatted_date' => $createdAt->format('Y-m-d h:i:s A'), // e.g. 2026-02-11 05:53:42 PM
                    'raw_date' => $createdAt->toIso8601String()
                ];
            });
    }

    /**
     * Installs the field says are finished — and billing agrees are finished.
     *
     * A JO notification names the job order by number and says the work is
     * finished, so it is withheld until both halves of the job order have
     * actually landed — see JobOrderNotificationGuard.
     *
     * Over-fetched before filtering: the guard drops rows, and taking exactly
     * $limit first would leave the feed short by however many were suppressed.
     * Capped at a small multiple so a database full of pending job orders
     * cannot turn this into an unbounded read.
     */
    private function fetchCompletedJobOrders(int $limit)
    {
        $candidates = DB::table('job_orders')
            ->join('applications', 'job_orders.application_id', '=', 'applications.id')
            ->where('job_orders.onsite_status', 'Done')
            ->orderBy('job_orders.updated_at', 'desc')
            ->limit(min($limit * self::SUPPRESSION_OVERFETCH, self::SUPPRESSION_OVERFETCH_CAP))
            ->select(
                'job_orders.id',
                'job_orders.updated_at',
                'job_orders.billing_status',
                // Selected here rather than re-read by the guard so the
                // suppression check costs no extra query.
                'job_orders.onsite_status',
                'applications.first_name',
                'applications.last_name',
                'applications.desired_plan'
            )
            ->get();

        return $candidates
            ->filter(function ($job) {
                $reason = $this->jobOrderGuard->reasonFor($job->billing_status, $job->onsite_status);

                if ($reason === null) {
                    return true;
                }

                $this->jobOrderGuard->logSuppressed($job->id, $reason, [
                    'feed' => 'consolidated',
                    'billing_status' => $job->billing_status,
                    'onsite_status' => $job->onsite_status,
                ]);

                return false;
            })
            ->take($limit)
            ->map(function ($job) {
                $updatedAt = Carbon::parse($job->updated_at);
                return [
                    'id' => $job->id,
                    'type' => 'job_order_done',
                    'customer_name' => trim(($job->first_name ?? '') . ' ' . ($job->last_name ?? '')),
                    'plan_name' => $job->desired_plan ?? 'Unknown Plan',
                    'title' => 'Job Order Completed',
                    'message' => 'Onsite status marked as Done',
                    'timestamp' => $updatedAt->timestamp,
                    'formatted_date' => $updatedAt->format('Y-m-d h:i:s A'),
                    'raw_date' => $updatedAt->toIso8601String()
                ];
            })
            ->values();
    }

    /**
     * Support visits the technician has closed out.
     *
     * Service orders carry only an account number, so the subscriber's name
     * comes from billing_accounts -> customers. Left joins throughout: a visit
     * on an account that has since been removed should still be announced, just
     * without a name attached.
     */
    private function fetchCompletedServiceOrders(int $limit)
    {
        return DB::table('service_orders')
            ->leftJoin('billing_accounts', 'service_orders.account_no', '=', 'billing_accounts.account_no')
            ->leftJoin('customers', 'billing_accounts.customer_id', '=', 'customers.id')
            ->where('service_orders.visit_status', 'Done')
            ->orderBy('service_orders.updated_at', 'desc')
            ->limit($limit)
            ->select(
                'service_orders.id',
                'service_orders.updated_at',
                'service_orders.concern',
                'service_orders.account_no',
                'customers.first_name',
                'customers.last_name'
            )
            ->get()
            ->map(function ($so) {
                $updatedAt = Carbon::parse($so->updated_at);
                $name = trim(($so->first_name ?? '') . ' ' . ($so->last_name ?? ''));

                return [
                    'id' => $so->id,
                    'type' => 'service_order_done',
                    // Falling back to the account number keeps the row
                    // identifiable when the customer join finds nothing.
                    'customer_name' => $name !== '' ? $name : ($so->account_no ?? 'Unknown Customer'),
                    'plan_name' => $so->concern ?? 'Service Visit',
                    'title' => 'Service Order Completed',
                    'message' => $so->concern ? ('Visit completed: ' . $so->concern) : 'Visit status marked as Done',
                    'timestamp' => $updatedAt->timestamp,
                    'formatted_date' => $updatedAt->format('Y-m-d h:i:s A'),
                    'raw_date' => $updatedAt->toIso8601String()
                ];
            });
    }

    /**
     * Revert requests waiting on a SuperAdmin.
     *
     * Fails closed. The route this controller sits on does not force
     * authentication, so the token is resolved explicitly through the sanctum
     * guard and anything that is not a confirmed SuperAdmin — no token, an
     * expired token, any other role — gets an empty collection rather than an
     * error. A caller who should not see reverts simply never learns they exist.
     */
    private function fetchPendingReverts(Request $request, int $limit)
    {
        $user = $request->user() ?: auth('sanctum')->user();

        if (!$user || (int) ($user->role_id ?? 0) !== self::SUPERADMIN_ROLE_ID) {
            return collect();
        }

        return DB::table('transaction_revert')
            ->leftJoin('transactions', 'transaction_revert.transaction_id', '=', 'transactions.id')
            // TRIM as well as LOWER: these status columns are free text and
            // production data carries ' Pending ' as well as 'pending'. The
            // collation folds case and trailing space, but not a leading one.
            ->whereRaw('LOWER(TRIM(transaction_revert.status)) = ?', ['pending'])
            ->orderBy('transaction_revert.created_at', 'desc')
            ->limit($limit)
            ->select(
                'transaction_revert.id',
                'transaction_revert.created_at',
                'transaction_revert.reason',
                'transactions.account_no',
                'transactions.received_payment'
            )
            ->get()
            ->map(function ($revert) {
                $createdAt = Carbon::parse($revert->created_at);
                $amount = $revert->received_payment !== null
                    ? '₱' . number_format((float) $revert->received_payment, 2)
                    : null;

                return [
                    'id' => $revert->id,
                    'type' => 'transaction_revert',
                    'customer_name' => $revert->account_no ?? 'Unknown Account',
                    'plan_name' => $amount ?? 'Revert Request',
                    'title' => 'Transaction Revert Requested',
                    'message' => $revert->reason
                        ? ('Revert requested: ' . $revert->reason)
                        : 'A transaction revert is awaiting approval',
                    'timestamp' => $createdAt->timestamp,
                    'formatted_date' => $createdAt->format('Y-m-d h:i:s A'),
                    'raw_date' => $createdAt->toIso8601String()
                ];
            });
    }
}
