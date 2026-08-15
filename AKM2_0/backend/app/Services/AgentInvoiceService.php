<?php

namespace App\Services;

use App\Models\AgentInvoice;
use App\Models\AgentInvoiceCustomer;
use App\Models\User;
use App\Support\AgentProgramme;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AgentInvoiceService
 * ---------------------------------------------------------------------------
 * Builds the weekly referral invoice for every team and solo agent.
 *
 * An "owner" is whoever the invoice is addressed to: a team, or an agent who
 * belongs to no team. Agents are grouped by their `agent_id`, which is the team
 * they belong to on the `agents` table — an agent with none is billed alone.
 * A team therefore gets ONE invoice covering every agent in it, never one
 * invoice per member.
 *
 * A referral is billable when it was installed inside the billing week, on or
 * after the agent programme start date, and has not already been billed to this
 * owner. That last check is the important one, and it is enforced twice:
 *
 *   1. Customers already on an invoice for this owner are excluded up front.
 *   2. A unique key on (owner_key, application_id) refuses the write anyway.
 *
 * The second is what makes a repeated run safe. Two runs racing, a retry after
 * a timeout, or somebody running the command by hand on a Monday morning all
 * end the same way: one invoice, one row per customer.
 *
 * Everything for one owner is written inside a transaction, so a failure part
 * way through leaves no invoice rather than an invoice missing its customers.
 * One owner failing never stops the rest of the run.
 */
class AgentInvoiceService
{
    private string $logName = 'Agent_Invoices';

    /**
     * The seven days a run bills: the week immediately before the run itself.
     *
     * A run on Monday 17 August bills 10 August 00:00:00 to 16 August 23:59:59.
     * The day of the run is never included — work completed during it belongs to
     * the following week's invoice.
     *
     * Rolling rather than calendar-aligned, so the rule holds whatever day the
     * run happens on: a catch-up run on Wednesday 19 August bills the 12th to the
     * 18th, not the part-finished week the 19th sits in. Consecutive weekly runs
     * therefore produce windows that abut exactly — no day is billed twice and
     * none is skipped.
     *
     * Dates come from Carbon, which Laravel has already set to the app timezone
     * (config/app.php — Asia/Manila), so the boundaries are local midnights
     * regardless of what the server's own clock is set to.
     *
     * @param  Carbon|null  $asOf  treat this as the generation date; defaults to now
     * @return array{0: Carbon, 1: Carbon}  [periodStart, periodEnd]
     */
    public function periodFor(?Carbon $asOf = null): array
    {
        $generatedOn = ($asOf ? $asOf->copy() : Carbon::now())->startOfDay();

        return [
            $generatedOn->copy()->subDays(7)->startOfDay(),
            $generatedOn->copy()->subDay()->endOfDay(),
        ];
    }

    /**
     * Generate invoices for one billing week.
     *
     * @param  Carbon|null  $asOf  treat this as the generation date, billing the
     *                             seven days before it; defaults to now.
     * @return array  summary counters for the run
     */
    public function generateForWeek(?Carbon $asOf = null, ?int $onlyOwnerAgentId = null): array
    {
        // One derivation, shared with the command's dry run, so what a dry run
        // reports can never differ from what a real run bills.
        [$periodStart, $periodEnd] = $this->periodFor($asOf);

        $summary = [
            'period_start'      => $periodStart->format('Y-m-d'),
            'period_end'        => $periodEnd->format('Y-m-d'),
            'owners_evaluated'  => 0,
            'invoices_created'  => 0,
            'invoices_skipped'  => 0,   // already invoiced for this week
            'owners_no_work'    => 0,   // nothing billable
            'customers_billed'  => 0,
            'customers_skipped' => 0,   // already billed to this owner
            'amount_invoiced'   => 0.0,
            'pdfs_written'      => 0,
            'pdf_failures'      => 0,
            'errors'            => 0,
        ];

        $this->log("=== AGENT INVOICE RUN — week {$summary['period_start']} to {$summary['period_end']} ===");

        try {
            $owners = $this->resolveOwners($onlyOwnerAgentId);
        } catch (Throwable $e) {
            $summary['errors']++;
            $this->log('[FATAL] Could not list agents: ' . $e->getMessage());
            Log::channel('single')->error('[AGENT INVOICES] Could not list agents: ' . $e->getMessage());
            return $summary;
        }

        $summary['owners_evaluated'] = count($owners);
        $this->log('Owners to evaluate: ' . count($owners));

        foreach ($owners as $owner) {
            try {
                $this->generateForOwner($owner, $periodStart, $periodEnd, $summary);
            } catch (Throwable $e) {
                // One owner's failure must never stop the rest of the run.
                $summary['errors']++;
                $this->log("[ERROR] {$owner['owner_key']}: " . $e->getMessage());
                Log::channel('single')->error("[AGENT INVOICES] {$owner['owner_key']}: " . $e->getMessage(), [
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        $this->log(sprintf(
            '=== DONE — evaluated %d, created %d, already invoiced %d, nothing to bill %d, customers billed %d, already billed %d, amount %s, pdfs %d (failed %d), errors %d ===',
            $summary['owners_evaluated'],
            $summary['invoices_created'],
            $summary['invoices_skipped'],
            $summary['owners_no_work'],
            $summary['customers_billed'],
            $summary['customers_skipped'],
            number_format($summary['amount_invoiced'], 2),
            $summary['pdfs_written'],
            $summary['pdf_failures'],
            $summary['errors']
        ));

        return $summary;
    }

    /**
     * Everyone an invoice can be addressed to.
     *
     * Teams first — every agent holding the same `agent_id` is one owner — then
     * each agent with no team as an owner of their own.
     *
     * @return array<int, array{owner_key: string, type: string, team_id: ?int,
     *                          team_name: ?string, agent_id: ?int,
     *                          agent_name: ?string, members: array, organization_id: ?int}>
     */
    public function resolveOwners(?int $onlyAgentId = null): array
    {
        $query = DB::table('users as u')
            ->leftJoin('agent_balance as ab', 'ab.agent_id', '=', 'u.id')
            ->leftJoin('agents as a', 'a.id', '=', 'u.agent_id')
            // An agent is someone who holds an agent balance — the same
            // definition the incentive and achievement crons use, so the three
            // never disagree about who is an agent.
            ->whereNotNull('ab.agent_id')
            ->select(
                'u.id as user_id',
                'u.first_name',
                'u.middle_initial',
                'u.last_name',
                'u.email_address',
                'u.agent_id as team_id',
                'u.organization_id',
                'a.team_name',
                'ab.incentives_value',
                'ab.commission'
            );

        if ($onlyAgentId !== null) {
            $query->where('u.id', $onlyAgentId);
        }

        $rows = $query->orderBy('u.id')->get();

        $owners = [];

        foreach ($rows as $row) {
            $member = [
                'user_id'         => (int) $row->user_id,
                'name'            => $this->fullName($row),
                'email'           => trim((string) ($row->email_address ?? '')),
                'unit_price'      => $row->incentives_value !== null && (float) $row->incentives_value > 0
                    ? (float) $row->incentives_value
                    : (float) config('agent_invoices.unit_price', 100),
                // The agent's own commission rate, read the same way the payout
                // screens read it. Each referral on the invoice earns this, so a
                // team whose members are on different rates still bills each
                // customer at the rate of whoever brought them in.
                'commission_rate' => (float) ($row->commission ?? 0),
                'organization_id' => $row->organization_id !== null ? (int) $row->organization_id : null,
            ];

            $teamId = $row->team_id !== null && $row->team_id !== '' ? (int) $row->team_id : null;

            if ($teamId !== null) {
                $key = AgentInvoice::ownerKeyForTeam($teamId);

                if (!isset($owners[$key])) {
                    $owners[$key] = [
                        'owner_key'       => $key,
                        'type'            => AgentInvoice::TYPE_TEAM,
                        'team_id'         => $teamId,
                        'team_name'       => $row->team_name ?: ('Team ' . $teamId),
                        'agent_id'        => null,
                        'agent_name'      => null,
                        'members'         => [],
                        'organization_id' => $member['organization_id'],
                    ];
                }

                $owners[$key]['members'][] = $member;
                continue;
            }

            $key = AgentInvoice::ownerKeyForAgent($member['user_id']);
            $owners[$key] = [
                'owner_key'       => $key,
                'type'            => AgentInvoice::TYPE_SOLO,
                'team_id'         => null,
                'team_name'       => null,
                'agent_id'        => $member['user_id'],
                'agent_name'      => $member['name'],
                'members'         => [$member],
                'organization_id' => $member['organization_id'],
            ];
        }

        return array_values($owners);
    }

    /** Build and store one owner's invoice for the week. */
    private function generateForOwner(array $owner, Carbon $periodStart, Carbon $periodEnd, array &$summary): void
    {
        $ownerKey = $owner['owner_key'];
        $label    = $owner['type'] === AgentInvoice::TYPE_TEAM
            ? "team \"{$owner['team_name']}\" (" . count($owner['members']) . ' agent(s))'
            : "agent \"{$owner['agent_name']}\"";

        $this->log("[{$ownerKey}] {$label}");

        // Already invoiced for this week — a re-run, not a fault.
        $existing = AgentInvoice::where('owner_key', $ownerKey)
            ->whereDate('period_start', $periodStart->format('Y-m-d'))
            ->first();

        if ($existing) {
            $summary['invoices_skipped']++;
            $this->log("  [SKIP] already invoiced for this week as {$existing->invoice_number}");
            return;
        }

        $billable = $this->billableCustomers($owner, $periodStart, $periodEnd, $summary);

        if ($billable === []) {
            $summary['owners_no_work']++;
            $this->log('  [SKIP] nothing billable this week');
            return;
        }

        $unitPrice = (float) ($owner['members'][0]['unit_price'] ?? config('agent_invoices.unit_price', 100));
        $count     = count($billable);

        $totalAmount     = round($count * $unitPrice, 2);
        $installationFee = (float) config('agent_invoices.installation_fee', 0);

        // Commission is earned per referral, at the rate of the agent who
        // brought that customer in — so it is the sum across the invoice, not a
        // fixed figure. On a single-rate team this is simply
        // customers x rate; where members are on different rates, each
        // customer still counts at their own agent's.
        $commission = round(array_sum(array_map(
            fn ($c) => (float) $c['commission_rate'],
            $billable
        )), 2);

        // Matches the reference document: the installation fee is stated on the
        // invoice but does not form part of what is owed.
        $subtotal = round($totalAmount + $commission, 2);

        $invoice = null;

        try {
            DB::transaction(function () use (
                $owner, $ownerKey, $periodStart, $periodEnd, $billable, $unitPrice,
                $count, $totalAmount, $installationFee, $commission, $subtotal, &$invoice
            ) {
                $invoice = AgentInvoice::create([
                    'invoice_number'   => $this->nextInvoiceNumber(),
                    'invoice_type'     => $owner['type'],
                    'owner_key'        => $ownerKey,
                    'team_id'          => $owner['team_id'],
                    'agent_id'         => $owner['agent_id'],
                    'team_name'        => $owner['team_name'],
                    'agent_name'       => $owner['agent_name'],
                    'period_start'     => $periodStart->format('Y-m-d'),
                    'period_end'       => $periodEnd->format('Y-m-d'),
                    'invoice_date'     => Carbon::now()->format('Y-m-d'),
                    'total_customers'  => $count,
                    'unit_price'       => $unitPrice,
                    'installation_fee' => $installationFee,
                    'total_amount'     => $totalAmount,
                    'commission'       => $commission,
                    'subtotal'         => $subtotal,
                    'status'           => AgentInvoice::STATUS_GENERATED,
                    'organization_id'  => $owner['organization_id'],
                    'created_by'       => 'System',
                    'updated_by'       => 'System',
                ]);

                $rows = [];
                $now  = Carbon::now();

                foreach ($billable as $c) {
                    $rows[] = [
                        'agent_invoice_id'     => $invoice->id,
                        'application_id'       => $c['application_id'],
                        'job_order_id'         => $c['job_order_id'],
                        'owner_key'            => $ownerKey,
                        'customer_name'        => $c['customer_name'],
                        'referred_by_agent_id' => $c['referred_by_agent_id'],
                        'referred_by_name'     => $c['referred_by_name'],
                        'referred_by_raw'      => $c['referred_by_raw'],
                        'installed_date'       => $c['installed_date'],
                        'unit_price'           => $unitPrice,
                        'quantity'             => 1,
                        'total'                => $unitPrice,
                        'created_at'           => $now,
                        'updated_at'           => $now,
                    ];
                }

                // The unique key on (owner_key, application_id) refuses any
                // customer already billed to this owner. If that fires, the
                // whole invoice rolls back rather than being issued short.
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('agent_invoice_customers')->insert($chunk);
                }
            });
        } catch (QueryException $e) {
            // Another run got there first, or a customer slipped in between the
            // read and the write. Either way this owner is already covered.
            $summary['invoices_skipped']++;
            $this->log('  [SKIP] refused by the database (already invoiced): ' . $e->getMessage());
            return;
        }

        $summary['invoices_created']++;
        $summary['customers_billed'] += $count;
        $summary['amount_invoiced']  += $subtotal;

        $this->log("  [OK] {$invoice->invoice_number} — {$count} customer(s), subtotal " . number_format($subtotal, 2));

        // The PDF is written outside the transaction: a file that fails to
        // write must not undo a correctly recorded invoice. It can be produced
        // again on demand from the stored rows.
        $this->writePdf($invoice, $summary);
    }

    /**
     * The customers this owner can bill for this week.
     *
     * Excludes anyone already invoiced to this owner, whatever their install
     * date now says — the record of what has been billed is what counts, not
     * the date on the job order.
     */
    private function billableCustomers(array $owner, Carbon $periodStart, Carbon $periodEnd, array &$summary): array
    {
        $alreadyBilled = AgentInvoiceCustomer::where('owner_key', $owner['owner_key'])
            ->pluck('application_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $billedIndex = array_flip($alreadyBilled);

        $startDate = AgentProgramme::startDate();
        $completedAt = AgentProgramme::onboardedAtSql('jo');

        $query = DB::table('job_orders as jo')
            ->join('applications as a', 'jo.application_id', '=', 'a.id')
            ->whereIn(DB::raw('LOWER(TRIM(jo.onsite_status))'), ['done', 'completed'])
            ->whereNotNull('a.referred_by')
            // Inclusive of both ends, to the second: periodFor() hands over the
            // start at 00:00:00 and the end at 23:59:59, so the bounds are taken
            // from it rather than re-stated here where they could drift.
            ->whereRaw("{$completedAt} >= ?", [$periodStart->format('Y-m-d H:i:s')])
            ->whereRaw("{$completedAt} <= ?", [$periodEnd->format('Y-m-d H:i:s')]);

        // Never bill for work done before the agent programme began.
        if ($startDate !== null) {
            $query->whereRaw("{$completedAt} >= ?", [$startDate->format('Y-m-d H:i:s')]);
        }

        $rows = $query->select(
            'jo.id as job_order_id',
            'a.id as application_id',
            'a.first_name',
            'a.middle_initial',
            'a.last_name',
            'a.referred_by',
            DB::raw("{$completedAt} as installed_at")
        )->orderBy('jo.id')->get();

        $billable = [];
        $seen     = [];

        foreach ($rows as $row) {
            $applicationId = (int) $row->application_id;

            // Two installed job orders against one application would otherwise
            // bill the same customer twice on the same invoice.
            if (isset($seen[$applicationId])) {
                continue;
            }

            // Which agent in this owner referred them, if any.
            $member = $this->memberWhoReferred($owner['members'], (string) $row->referred_by);
            if ($member === null) {
                continue;
            }

            if (isset($billedIndex[$applicationId])) {
                $summary['customers_skipped']++;
                continue;
            }

            $seen[$applicationId] = true;

            $billable[] = [
                'application_id'       => $applicationId,
                'job_order_id'         => (int) $row->job_order_id,
                'customer_name'        => $this->fullName($row),
                'referred_by_agent_id' => $member['user_id'],
                'referred_by_name'     => $member['name'],
                'referred_by_raw'      => (string) $row->referred_by,
                'installed_date'       => $row->installed_at ? Carbon::parse($row->installed_at)->format('Y-m-d') : null,
                // Carried so the invoice total is the sum of what each referral
                // actually earns, rather than one rate applied to all of them.
                'commission_rate'      => (float) ($member['commission_rate'] ?? 0),
            ];
        }

        return $billable;
    }

    /**
     * Which agent of this owner a "Referred By" value belongs to.
     *
     * Uses the same tolerant match the rest of the agent module uses, so a
     * referral written with a middle name still reaches the right agent — and
     * on a team invoice each customer stays attached to whoever brought them in.
     */
    private function memberWhoReferred(array $members, string $referredBy): ?array
    {
        foreach ($members as $member) {
            if (AgentProgramme::referralBelongsToAgent($referredBy, $member['name'], $member['email'])) {
                return $member;
            }
        }

        return null;
    }

    /**
     * The next invoice number.
     *
     * Taken from the highest number ever issued rather than a count of rows, so
     * deleting an invoice cannot hand its number to a later one. The unique key
     * on the column is the backstop if two runs reach here together.
     */
    public function nextInvoiceNumber(): string
    {
        $prefix  = (string) config('agent_invoices.number_prefix', 'ATSS-AGT');
        $padding = (int) config('agent_invoices.number_padding', 6);

        $last = AgentInvoice::where('invoice_number', 'like', $prefix . '-%')
            ->orderByRaw('LENGTH(invoice_number) DESC, invoice_number DESC')
            ->value('invoice_number');

        $next = 1;
        if ($last !== null) {
            $tail = substr((string) $last, strlen($prefix) + 1);
            if (is_numeric($tail)) {
                $next = ((int) $tail) + 1;
            }
        }

        return $prefix . '-' . str_pad((string) $next, $padding, '0', STR_PAD_LEFT);
    }

    /** Render and store the invoice PDF, recording where it went. */
    private function writePdf(AgentInvoice $invoice, array &$summary): void
    {
        try {
            $path = app(AgentInvoicePdfService::class)->render($invoice);
            $invoice->forceFill(['pdf_path' => $path])->save();

            $summary['pdfs_written']++;
            $this->log("  [PDF] {$path}");
        } catch (Throwable $e) {
            // The invoice itself stands. The PDF can be produced again on
            // demand from the rows already recorded.
            $summary['pdf_failures']++;
            $this->log('  [PDF FAILED] ' . $e->getMessage());
            Log::channel('single')->error("[AGENT INVOICES] PDF failed for {$invoice->invoice_number}: " . $e->getMessage());
        }
    }

    /** "First M. Last" from a row carrying those columns. */
    private function fullName($row): string
    {
        $first  = trim((string) ($row->first_name ?? ''));
        $middle = trim((string) ($row->middle_initial ?? ''));
        $last   = trim((string) ($row->last_name ?? ''));

        $parts = array_filter([$first, $middle !== '' ? rtrim($middle, '.') . '.' : '', $last], fn ($p) => $p !== '');

        return trim(preg_replace('/\s+/', ' ', implode(' ', $parts)));
    }

    private function log(string $message): void
    {
        try {
            $line = '[' . Carbon::now()->format('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
            $dir  = storage_path('logs/agent-invoices');

            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            @file_put_contents($dir . '/' . $this->logName . '.log', $line, FILE_APPEND);
        } catch (Throwable $e) {
            // Logging must never be the reason a run fails.
        }
    }
}
