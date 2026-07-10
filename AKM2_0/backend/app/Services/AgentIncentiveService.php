<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Throwable;

/**
 * AgentIncentiveService
 * ---------------------------------------------------------------------------
 * Cron logic that awards quota-based incentives to agents.
 *
 * For each agent it counts the agent's COMPLETED ("Done") Job Orders that have
 * not yet been counted, and for every full multiple of the agent's quota it
 * adds the configured `incentives_value` to the agent's `incentives` balance.
 *
 * Each full quota cycle is a "batch". An agent with 20 completed Job Orders and
 * a quota of 10 is awarded 2 batches in one run (10 Job Orders tagged to each),
 * and batch numbers keep incrementing per agent across runs (batch 1, 2, 3, …).
 * Any remainder (< quota) carries over unprocessed to the next run.
 *
 * Idempotency / no-double-pay is guaranteed two ways:
 *   1. Only Job Orders absent from `agent_incentive_history` are counted.
 *   2. Every counted Job Order is recorded in `agent_incentive_history`, which
 *      has a UNIQUE key on `job_order_id` — so even concurrent runs cannot
 *      record (and therefore cannot pay for) the same Job Order twice.
 *
 * Job Order ↔ Agent association follows the project's existing convention
 * (see CommissionController): Job Orders are linked to an agent through the
 * related application's `referred_by` full-name field. There is no agent_id on
 * job_orders today — see the note in the class docblock recommending one.
 *
 * NOTE (future improvement, intentionally NOT applied here): matching by
 * full name via `referred_by LIKE '%name%'` is inherently fragile (name
 * collisions, renames, partial matches). Adding an explicit `agent_id` column
 * to job_orders/applications and matching on it would be far more reliable.
 */
class AgentIncentiveService
{
    private string $logName = 'Agent_Incentives';

    /**
     * Process incentives for every agent.
     *
     * @return array Summary counters for the run.
     */
    public function process(): array
    {
        $summary = [
            'agents_processed'    => 0,
            'agents_awarded'      => 0,
            'incentive_awards'    => 0,   // total number of quota cycles awarded across all agents
            'amount_awarded'      => 0.0, // total currency awarded
            'job_orders_recorded' => 0,
            'skipped'             => 0,   // agents skipped (no user / not configured)
            'skipped_job_orders'  => 0,   // completed job orders skipped (already processed)
            'errors'              => 0,
        ];

        $this->writeLog("╔════════════════════════════════════════════════════════════════╗");
        $this->writeLog("║            AGENT QUOTA INCENTIVE PROCESSING START              ║");
        $this->writeLog("╚════════════════════════════════════════════════════════════════╝");
        $startTime = Carbon::now();
        $this->writeLog("Start Time: " . $startTime->format('Y-m-d H:i:s'));

        // One small query: every agent's incentive configuration.
        $balances = DB::table('agent_balance')->get();
        $total = $balances->count();
        $this->writeLog("[QUERY] Found {$total} agent balance record(s) to evaluate");
        $this->writeLog("─────────────────────────────────────────────────────────────────");

        $counter = 0;
        foreach ($balances as $balance) {
            $counter++;
            $summary['agents_processed']++;

            $this->writeLog("");
            $this->writeLog("[{$counter}/{$total}] ══════════════════════════════════════════════");

            try {
                $this->processAgent($balance, $summary, $counter, $total);
            } catch (Throwable $e) {
                // One agent's failure must never stop the rest of the run.
                $summary['errors']++;
                $this->writeLog("  [ERROR] Agent #{$balance->agent_id}: " . $e->getMessage());
                $this->writeLog("[{$counter}/{$total}] ✗ ERROR");
                Log::channel('single')->error("[{$this->logName}] Agent #{$balance->agent_id} failed: " . $e->getMessage(), [
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        $endTime = Carbon::now();
        $duration = $endTime->diffInSeconds($startTime);

        $this->writeLog("");
        $this->writeLog("╔════════════════════════════════════════════════════════════════╗");
        $this->writeLog("║            AGENT QUOTA INCENTIVE PROCESSING COMPLETE           ║");
        $this->writeLog("╚════════════════════════════════════════════════════════════════╝");
        $this->writeLog("Summary:");
        $this->writeLog("  • Agents Evaluated:    {$summary['agents_processed']}");
        $this->writeLog("  • Agents Awarded:      {$summary['agents_awarded']}");
        $this->writeLog("  • Incentive Cycles:    {$summary['incentive_awards']}");
        $this->writeLog("  • Amount Awarded:      " . number_format($summary['amount_awarded'], 2));
        $this->writeLog("  • Job Orders Recorded: {$summary['job_orders_recorded']}");
        $this->writeLog("  • Agents Skipped:      {$summary['skipped']}");
        $this->writeLog("  • Job Orders Skipped:  {$summary['skipped_job_orders']}");
        $this->writeLog("  • Errors:              {$summary['errors']}");
        $this->writeLog("  • Duration:            {$duration} second(s)");
        $this->writeLog("End Time: " . $endTime->format('Y-m-d H:i:s'));
        $this->writeLog("");

        return $summary;
    }

    /**
     * Evaluate and (if the quota is reached) award incentives for a single agent.
     */
    private function processAgent(object $balance, array &$summary, int $counter = 0, int $total = 0): void
    {
        $agentId           = (int) $balance->agent_id;
        $quota             = (int) ($balance->quota ?? 0);
        $incentiveValue    = (float) ($balance->incentives_value ?? 0);
        $currentIncentives = (float) ($balance->incentives ?? 0);

        // Resolve the agent's name (job orders are matched by full name).
        $user = DB::table('users')->where('id', $agentId)->first();
        if (!$user) {
            $summary['skipped']++;
            $this->writeLog("  [SKIP] Agent #{$agentId}: no matching user record");
            $this->writeLog("[{$counter}/{$total}] ⊘ SKIPPED");
            return;
        }

        $agentName = $this->buildFullName($user);
        $this->writeLog("  [AGENT] {$agentName} (#{$agentId})");
        $this->writeLog("  [CONFIG] Quota: {$quota} | Incentive Value: " . number_format($incentiveValue, 2) . " | Current Incentives: " . number_format($currentIncentives, 2));

        // Nothing to do if the agent is not configured for incentives.
        if ($quota <= 0 || $incentiveValue <= 0) {
            $summary['skipped']++;
            $this->writeLog("  [SKIP] Quota or incentive value not configured — nothing to award");
            $this->writeLog("[{$counter}/{$total}] ⊘ SKIPPED");
            return;
        }

        $nameVariants = $this->nameVariants($user);
        if (empty($nameVariants)) {
            $summary['skipped']++;
            $this->writeLog("  [SKIP] Unable to build a name to match job orders");
            $this->writeLog("[{$counter}/{$total}] ⊘ SKIPPED");
            return;
        }
        $this->writeLog("  [MATCH] Matching job orders via referred_by: " . implode(' | ', $nameVariants));

        // Base query for this agent's COMPLETED ("Done") job orders, matched by the
        // related application's referred_by full name (project convention).
        $completedBase = DB::table('job_orders')
            ->join('applications', 'job_orders.application_id', '=', 'applications.id')
            ->whereRaw('LOWER(job_orders.onsite_status) = ?', ['done'])
            ->where(function ($q) use ($nameVariants) {
                foreach ($nameVariants as $variant) {
                    $q->orWhereRaw('LOWER(applications.referred_by) LIKE ?', ['%' . $variant . '%']);
                }
            });

        // Total completed (for logging how many are skipped because already processed).
        $totalCompleted = (clone $completedBase)->count();

        // Only the COMPLETED job orders NOT yet recorded in history are countable.
        $jobOrderIds = (clone $completedBase)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('agent_incentive_history as aih')
                    ->whereColumn('aih.job_order_id', 'job_orders.id');
            })
            ->orderBy('job_orders.id', 'asc')
            ->pluck('job_orders.id')
            ->all();

        $available        = count($jobOrderIds);
        $alreadyProcessed = max(0, $totalCompleted - $available);

        $this->writeLog("  [QUERY] Completed: {$totalCompleted} | Already processed (skipped): {$alreadyProcessed} | New & countable: {$available}");
        if ($alreadyProcessed > 0) {
            $summary['skipped_job_orders'] = ($summary['skipped_job_orders'] ?? 0) + $alreadyProcessed;
        }

        // How many full quota cycles can we award right now?
        $cycles = intdiv($available, $quota);

        if ($cycles < 1) {
            // Progress only — not enough to award yet.
            $this->writeLog("  [PROGRESS] {$available}/{$quota} toward next incentive — quota not reached, no award");
            $this->writeLog("[{$counter}/{$total}] ✓ DONE (no award)");
            return;
        }

        // Only the job orders that actually contribute to a full cycle are processed.
        // Any remainder stays unprocessed and carries over to the next run.
        $processCount  = $cycles * $quota;
        $idsToProcess  = array_slice($jobOrderIds, 0, $processCount);
        $totalAward    = $cycles * $incentiveValue;
        $awardStr      = number_format($totalAward, 2, '.', ''); // numeric-only, safe for raw SQL
        $now           = Carbon::now();
        $orgId         = $balance->organization_id ?? null;

        // Batches are numbered per-agent and keep incrementing across runs so the
        // history reads as batch 1, 2, 3, … over the agent's lifetime. Each full
        // quota cycle awarded in this run gets its own consecutive batch number.
        $lastBatch  = (int) DB::table('agent_incentive_history')
            ->where('agent_id', $agentId)
            ->max('batch_number');
        $startBatch = $lastBatch + 1;
        $endBatch   = $startBatch + $cycles - 1;

        $this->writeLog("  [CALC] Quota reached x{$cycles} → awarding " . number_format($totalAward, 2) . " (= {$cycles} x " . number_format($incentiveValue, 2) . ") — batch(es) {$startBatch}" . ($cycles > 1 ? "-{$endBatch}" : ""));

        // Per-cycle detail (auditable, mirrors AutoDisconnect's per-item logging).
        for ($c = 0; $c < $cycles; $c++) {
            $cycleIds     = array_slice($idsToProcess, $c * $quota, $quota);
            $batchNumber  = $startBatch + $c;
            $this->writeLog("    [BATCH {$batchNumber}] (cycle " . ($c + 1) . "/{$cycles}) +" . number_format($incentiveValue, 2) . " for job order ID(s): " . implode(', ', $cycleIds));
        }

        $this->writeLog("  [DB] Recording {$processCount} job order(s) to agent_incentive_history and updating balance...");

        // All-or-nothing per agent: record the ledger rows and bump the balance
        // together. If the history insert collides (UNIQUE job_order_id) the whole
        // award rolls back, so a Job Order can never be paid without being recorded.
        DB::transaction(function () use ($idsToProcess, $quota, $incentiveValue, $orgId, $now, $balance, $awardStr, $startBatch) {
            $rows = [];
            foreach ($idsToProcess as $index => $jobOrderId) {
                // Every $quota job orders form one cycle → the next batch number.
                $batchNumber = $startBatch + intdiv($index, $quota);
                $rows[] = [
                    'agent_id'        => (int) $balance->agent_id,
                    'job_order_id'    => $jobOrderId,
                    'quota_reached'   => $quota,
                    'batch_number'    => $batchNumber,
                    'incentive_value' => $incentiveValue,
                    'organization_id' => $orgId,
                    'processed_at'    => $now,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('agent_incentive_history')->insert($chunk);
            }

            // COALESCE guards against a NULL incentives column and avoids a stale read.
            DB::table('agent_balance')
                ->where('id', $balance->id)
                ->update([
                    'incentives' => DB::raw("COALESCE(incentives, 0) + {$awardStr}"),
                    'updated_at' => $now,
                ]);
        });

        $newIncentives = $currentIncentives + $totalAward;

        $summary['agents_awarded']++;
        $summary['incentive_awards']    += $cycles;
        $summary['amount_awarded']      += $totalAward;
        $summary['job_orders_recorded'] += $processCount;

        $this->writeLog("  [DB] ✓ COMMIT SUCCESSFUL");
        $this->writeLog("  [AWARD] Incentives: " . number_format($currentIncentives, 2) . " → " . number_format($newIncentives, 2) . " (+" . number_format($totalAward, 2) . ")");
        if ($available > $processCount) {
            $this->writeLog("  [CARRY] " . ($available - $processCount) . " completed job order(s) carried over to next run");
        }
        $this->writeLog("  [COMPLETE] {$agentName} (#{$agentId}) — awarded incentive x{$cycles}, recorded {$processCount} job order(s)");
        $this->writeLog("[{$counter}/{$total}] ✓ SUCCESS");
    }

    /**
     * Build all lowercased name variants used to match against applications.referred_by.
     * Mirrors the matching used by CommissionController for consistency.
     */
    private function nameVariants(object $user): array
    {
        $first  = trim((string) ($user->first_name ?? ''));
        $middle = trim((string) ($user->middle_initial ?? ''));
        $last   = trim((string) ($user->last_name ?? ''));

        $variants = [];

        // first last
        $simple = trim($first . ' ' . $last);
        if ($simple !== '') {
            $variants[] = strtolower($simple);
        }

        // first M. last  (matches the User::full_name accessor format)
        $full = trim($first . ' ' . ($middle !== '' ? $middle . '. ' : '') . $last);
        if ($full !== '') {
            $variants[] = strtolower($full);
        }

        return array_values(array_unique(array_filter($variants)));
    }

    /**
     * Human-readable full name for logging.
     */
    private function buildFullName(object $user): string
    {
        $first  = trim((string) ($user->first_name ?? ''));
        $middle = trim((string) ($user->middle_initial ?? ''));
        $last   = trim((string) ($user->last_name ?? ''));
        $name   = trim($first . ' ' . ($middle !== '' ? $middle . '. ' : '') . $last);

        return $name !== '' ? $name : ('Agent #' . ($user->id ?? '?'));
    }

    /**
     * Write to a dedicated log file (and mirror to the default log).
     */
    private function writeLog(string $message): void
    {
        $timestamp = Carbon::now()->format('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [{$this->logName}] {$message}";

        $logDir = storage_path('logs/agentincentives');
        $logFile = $logDir . '/agent_incentives.log';

        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }

        file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);
        Log::channel('single')->info("[{$this->logName}] {$message}");
    }
}
