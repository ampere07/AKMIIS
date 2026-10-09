<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\ManualRadiusOperationsService;
use App\Models\RadiusConfig;
use App\Support\RadiusRetryPolicy;
use Carbon\Carbon;

/**
 * Retry queue for RADIUS operations that could not be applied immediately.
 *
 * Each queued operation is attempted up to the configured maximum, waiting a
 * progressively longer time after each failure (see config/radius.php). The
 * schedule and the attempt counter live in the database row, not in memory, so
 * the retry sequence survives an application or worker restart.
 */
class RadiusQueueService
{
    private $logName = 'Radius_Queue';

    /**
     * Sources whose restrict/disconnect jobs exist only because the account was overdue.
     * Operator-requested restrictions and disconnections (service orders) are not tied to
     * the balance, so they are never cancelled on it.
     */
    private const BILLING_DRIVEN_SOURCES = ['auto_disconnect', 'auto_pullout'];

    /** Balance at or below which an account counts as paid — same tolerance as the payment worker. */
    private const SETTLED_EPSILON = 0.01;

    /**
     * Queue a failed RADIUS operation for retry
     */
    public static function queue(array $data): ?int
    {
        try {
            $attempt = $data['attempts'] ?? 0;
            $maxAttempts = $data['max_attempts'] ?? RadiusRetryPolicy::maxAttempts();

            $insertData = [
                'source_type'     => $data['source_type'],
                'source_id'       => $data['source_id'],
                'account_no'      => $data['account_no'] ?? null,
                'operation'       => $data['operation'],
                'params'          => json_encode($data['params']),
                'status'          => 'pending',
                'attempts'        => $attempt,
                'max_attempts'    => $maxAttempts,
                'last_error'      => $data['last_error'] ?? null,
                'next_retry_at'   => Carbon::now(),
                'created_by'      => $data['created_by'] ?? 'System',
                'created_at'      => now(),
                'updated_at'      => now(),
            ];

            if (\Illuminate\Support\Facades\Schema::hasColumn('radius_operation_queue', 'organization_id')) {
                $insertData['organization_id'] = $data['organization_id'] ?? null;
            }

            // Use insert() instead of insertGetId() to avoid exceptions on tables without auto-increment IDs
            $success = DB::table('radius_operation_queue')->insert($insertData);

            if ($success) {
                // Static method can't use $this->writeLog, so write directly
                $timestamp = Carbon::now()->format('Y-m-d H:i:s');
                $logDir = storage_path('logs/radiusqueue');
                $logFile = $logDir . '/radius_queue.log';
                if (!file_exists($logDir)) {
                    mkdir($logDir, 0755, true);
                }
                $msg = "[{$timestamp}] [Radius_Queue] [QUEUED] Operation: {$data['operation']} | Source: {$data['source_type']}#{$data['source_id']} | Account: " . ($data['account_no'] ?? 'N/A');
                file_put_contents($logFile, $msg . PHP_EOL, FILE_APPEND);

                return 1; // Return a truthy integer to satisfy callers expecting an ID
            }
            
            return null;
        } catch (\Exception $e) {
            Log::channel('radiusrelated')->error('[RADIUS QUEUE] Failed to queue operation: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * True when an unfinished (pending or processing) queue entry already exists for the
     * same account + operation.
     *
     * Used by callers that may retry the same customer on a later run (cron jobs) so a
     * RADIUS outage produces exactly one queue entry per pending operation instead of one
     * per run. Optionally narrowed to a single source_type.
     */
    public static function hasPendingOperation(?string $accountNo, string $operation, ?string $sourceType = null): bool
    {
        if (empty($accountNo)) {
            return false;
        }

        $query = DB::table('radius_operation_queue')
            ->where('account_no', $accountNo)
            ->where('operation', $operation)
            ->whereIn('status', ['pending', 'processing']);

        if ($sourceType !== null) {
            $query->where('source_type', $sourceType);
        }

        return $query->exists();
    }

    /**
     * Queue a failed RADIUS operation only when no unfinished entry for the same
     * account + operation exists yet.
     *
     * @return array{queued: bool, duplicate: bool, error: string|null}
     */
    public static function queueUnique(array $data): array
    {
        try {
            if (self::hasPendingOperation($data['account_no'] ?? null, $data['operation'], $data['source_type'] ?? null)) {
                return ['queued' => false, 'duplicate' => true, 'error' => null];
            }

            $queued = self::queue($data) !== null;

            return [
                'queued'    => $queued,
                'duplicate' => false,
                'error'     => $queued ? null : 'Queue insert returned no result',
            ];
        } catch (\Throwable $e) {
            Log::channel('radiusrelated')->error('[RADIUS QUEUE] queueUnique failed: ' . $e->getMessage(), [
                'account_no' => $data['account_no'] ?? null,
                'operation'  => $data['operation'] ?? null,
            ]);

            return ['queued' => false, 'duplicate' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Process all pending items in the queue
     * Called by the cron command
     */
    public function processQueue(?int $batchSize = null): array
    {
        $batchSize = $batchSize ?? RadiusRetryPolicy::batchSize();

        $results = [
            'processed' => 0,
            'succeeded' => 0,
            'failed'    => 0,
            'skipped'   => 0,
            'reclaimed' => 0,
        ];

        $maxAttempts = RadiusRetryPolicy::maxAttempts();

        $this->writeLog("╔════════════════════════════════════════════════════════════════╗");
        $this->writeLog("║         RADIUS QUEUE PROCESSING START                          ║");
        $this->writeLog("╚════════════════════════════════════════════════════════════════╝");
        $startTime = Carbon::now();
        $this->writeLog("Start Time: " . $startTime->format('Y-m-d H:i:s'));
        $this->writeLog("Retry Policy: up to {$maxAttempts} attempts | delays: " . RadiusRetryPolicy::describeSchedule());
        $this->writeLog("");

        // Recover anything a previous worker was holding when it stopped, before
        // deciding what is due — otherwise those jobs would never be seen again.
        $results['reclaimed'] = $this->reclaimStaleProcessing();

        // Fetch pending items that are due for retry.
        //
        // A row's own max_attempts wins when it holds a usable value; the configured
        // maximum fills in only where the row has none.
        $effectiveMax = 'COALESCE(NULLIF(max_attempts, 0), ' . (int) $maxAttempts . ')';

        $pendingItems = DB::table('radius_operation_queue')
            ->where('status', 'pending')
            ->where(function ($q) {
                // A row that has never been scheduled is due immediately.
                $q->whereNull('next_retry_at')
                  ->orWhere('next_retry_at', '<=', Carbon::now());
            })
            ->whereRaw("attempts < {$effectiveMax}")
            ->orderBy('next_retry_at', 'asc')
            ->limit($batchSize)
            ->get();

        if ($pendingItems->isEmpty()) {
            $this->writeLog("[INFO] No pending items in queue. Nothing to process.");
            $this->writeLog("");
            return $results;
        }

        $totalCount = $pendingItems->count();
        $this->writeLog("[QUERY] Found {$totalCount} pending item(s) to process");
        $this->writeLog("─────────────────────────────────────────────────────────────────");
        $this->writeLog("");

        $counter = 0;
        foreach ($pendingItems as $item) {
            $counter++;
            $results['processed']++;

            $itemMax     = RadiusRetryPolicy::resolveMaxAttempts(isset($item->max_attempts) ? (int) $item->max_attempts : null);
            $thisAttempt = (int) $item->attempts + 1;

            $this->writeLog("[{$counter}/{$totalCount}] ══════════════════════════════════════════════");
            $this->writeLog("  [ITEM] ID: {$item->id} | Operation: {$item->operation} | Account: " . ($item->account_no ?? 'N/A'));
            $this->writeLog("  [ITEM] Source: {$item->source_type}#{$item->source_id} | Attempt: {$thisAttempt}/{$itemMax}");

            // Claim the row only while it is still pending. The batch was read at the start
            // of the run, and a payment cancels a queued restriction in the meantime by moving
            // it to 'cancelled'. Marking it 'processing' unconditionally overwrote that cancel
            // and restricted a customer who had already paid.
            $claimed = DB::table('radius_operation_queue')
                ->where('id', $item->id)
                ->where('status', 'pending')
                ->update([
                    'status'     => 'processing',
                    'updated_at' => now(),
                ]);

            if ($claimed === 0) {
                $results['skipped']++;
                $this->writeLog("  [SKIP] No longer pending (cancelled or claimed elsewhere) — not executed");
                $this->writeLog("");
                continue;
            }

            if ($this->cancelIfSettled($item)) {
                $results['skipped']++;
                $this->writeLog("");
                continue;
            }

            try {
                $params = json_decode($item->params, true);
                $this->writeLog("  [EXEC] Executing {$item->operation}...");

                $errorMessage = null;
                $success = $this->executeOperation($item->operation, $params, $errorMessage);

                if ($success) {
                    // Mark as success
                    DB::table('radius_operation_queue')
                        ->where('id', $item->id)
                        ->update([
                            'status'       => 'success',
                            'attempts'     => $thisAttempt,
                            'completed_at' => now(),
                            'updated_at'   => now(),
                        ]);

                    $results['succeeded']++;
                    $this->writeLog("  [RESULT] ✓ SUCCESS on attempt {$thisAttempt}/{$itemMax}");
                } else {
                    $errorMsg = $errorMessage ?? 'Operation returned failure status';
                    $this->markRetryOrFailed($item, $errorMsg);
                    $results['failed']++;
                    $this->writeLog("  [RESULT] ✗ FAILED - " . $errorMsg);
                }
            } catch (\Exception $e) {
                $this->markRetryOrFailed($item, $e->getMessage());
                $results['failed']++;
                $this->writeLog("  [RESULT] ✗ EXCEPTION - " . $e->getMessage());
            }

            $this->writeLog("");
        }

        $endTime = Carbon::now();
        $duration = $endTime->diffInSeconds($startTime);

        $this->writeLog("╔════════════════════════════════════════════════════════════════╗");
        $this->writeLog("║         RADIUS QUEUE PROCESSING COMPLETE                       ║");
        $this->writeLog("╚════════════════════════════════════════════════════════════════╝");
        $this->writeLog("Summary:");
        $this->writeLog("  • Total Processed: {$results['processed']}");
        $this->writeLog("  • Succeeded: {$results['succeeded']}");
        $this->writeLog("  • Failed (retry scheduled or exhausted): {$results['failed']}");
        $this->writeLog("  • Skipped (cancelled or settled): {$results['skipped']}");
        $this->writeLog("  • Reclaimed from stopped worker: {$results['reclaimed']}");
        $this->writeLog("  • Duration: {$duration} second(s)");
        $this->writeLog("End Time: " . $endTime->format('Y-m-d H:i:s'));
        $this->writeLog("");
        $this->writeLog("");

        return $results;
    }

    private function executeOperation(string $operation, array $params, &$errorMessage = null): bool
    {
        switch ($operation) {
            case 'create_user':
                $success = $this->retryCreateUser($params);
                if (!$success) {
                    $errorMessage = 'create_user failed on all endpoints.';
                }
                return $success;

            case 'reconnect_user':
                $service = app(ManualRadiusOperationsService::class);
                $result = $service->reconnectUser($params);
                $reconnectSucceeded = (($result['status'] ?? '') === 'success');

                // Auto-fail open pullout service orders regardless of whether the RADIUS
                // reconnect itself succeeded on this run. The customer has already paid (the
                // balance guard inside enforces ≤ ₱0.01), so their equipment must NOT be pulled
                // out even while the reconnect is still queued/retrying. This runs every time
                // the queued item is processed and is idempotent, so a still-open pullout SO is
                // failed on the first cron pass rather than waiting for the reconnect to land.
                app(PulloutServiceOrderCloser::class)
                    ->closeIfSettled($params['accountNumber'] ?? '', null, 'radius queue retry');

                if (!$reconnectSucceeded) {
                    $errorMessage = $result['message'] ?? 'Operation returned failure status';
                    return false;
                }
                return true;

            case 'restricted_user':
                $service = app(ManualRadiusOperationsService::class);
                $result = $service->restrictedUser($params);
                if (($result['status'] ?? '') !== 'success') {
                    $errorMessage = $result['message'] ?? 'Operation returned failure status';
                    return false;
                }
                return true;

            case 'disconnect_user':
                $service = app(ManualRadiusOperationsService::class);
                $result = $service->disconnectUser($params);
                if (($result['status'] ?? '') !== 'success') {
                    $errorMessage = $result['message'] ?? 'Operation returned failure status';
                    return false;
                }
                return true;

            case 'update_credentials':
                $service = app(ManualRadiusOperationsService::class);
                $result = $service->updateCredentials($params);
                if (($result['status'] ?? '') !== 'success') {
                    $errorMessage = $result['message'] ?? 'Operation returned failure status';
                    return false;
                }
                return true;

            default:
                $errorMessage = "Unknown operation: {$operation}";
                $this->writeLog("  [ERROR] " . $errorMessage);
                return false;
        }
    }

    /**
     * Retry create_user (the direct HTTP PUT used by JobOrderController)
     */
    private function retryCreateUser(array $params): bool
    {
        $username = $params['username'] ?? '';
        $password = $params['password'] ?? '';
        $group = $params['group'] ?? '';
        $organizationId = $params['organization_id'] ?? null;
        $city = $params['city'] ?? null;

        if (empty($username) || empty($password)) {
            $this->writeLog("  [ERROR] create_user: Missing username or password");
            return false;
        }

        $resolver = app(RadiusServerResolver::class);

        // A create places a NEW account, so pick the target server the same way
        // JobOrderController does — by the customer's city — when we know it. This keeps
        // the account on exactly one server rather than creating it on all of them.
        if (!empty($city)) {
            $config = $resolver->resolveForCity($city, $organizationId);
            if (!$config) {
                $this->writeLog("  [ERROR] create_user: No RADIUS config found for city '{$city}'");
                return false;
            }
            $this->writeLog("  [RADIUS] create_user targeting city-mapped server (Config #{$config->id} | {$config->ip}) for '{$username}'");
            return $this->putCreateUser($config, $username, $password, $group);
        }

        // No city recorded on the queued item: fall back to the ordered configs and stop
        // on the first server that accepts the create (never creating on more than one).
        $radiusConfigs = $resolver->orderedConfigs($organizationId);

        if ($radiusConfigs->isEmpty()) {
            $this->writeLog("  [ERROR] create_user: No RADIUS config found");
            return false;
        }

        foreach ($radiusConfigs as $config) {
            if ($this->putCreateUser($config, $username, $password, $group)) {
                return true;
            }
        }

        $this->writeLog("  [ERROR] create_user failed on all endpoints.");
        return false;
    }

    /**
     * Create the account on a single RADIUS config over the native RouterOS API.
     *
     * RouterosApiService::addUser() is read-then-write: an account that is already on the
     * device is reported as success and left untouched, so replaying a queued create after
     * a partial outage cannot produce a second copy of the subscriber.
     */
    private function putCreateUser(RadiusConfig $config, string $username, string $password, string $group): bool
    {
        $target = $config->ip . ' (Config #' . $config->id . ')';
        $this->writeLog("  [RADIUS] API create_user at {$target} | User: {$username} | Group: {$group}");

        try {
            $api = app(RouterosApiService::class);

            if ($api->addUser($config, $username, $password, $group)) {
                $this->writeLog("  [RADIUS] ✓ create_user SUCCESS at {$target}");
                return true;
            }

            $this->writeLog("  [RADIUS] ✗ create_user FAILED at {$target} - " . $api->getLastError());
        } catch (\Throwable $e) {
            $this->writeLog("  [RADIUS] ✗ create_user EXCEPTION at {$target}: " . $e->getMessage());
        }

        return false;
    }

    /**
     * Record the outcome of a failed attempt: schedule the next one, or give up.
     *
     * Retries used to be scheduled immediately, so with the cron every two minutes
     * all 5 attempts were spent in about ten minutes. A RADIUS outage longer than
     * that permanently failed every reconnect queued during it, leaving paid
     * customers restricted. Waiting progressively longer spreads the attempts over
     * hours instead.
     */
    private function markRetryOrFailed(object $item, string $error): void
    {
        $newAttempts = (int) $item->attempts + 1;
        $maxAttempts = RadiusRetryPolicy::resolveMaxAttempts(isset($item->max_attempts) ? (int) $item->max_attempts : null);

        if (RadiusRetryPolicy::isExhausted($newAttempts, $maxAttempts)) {
            // Every allowed attempt has now failed. The job stays 'failed' and is
            // never picked up again by the queue query.
            DB::table('radius_operation_queue')
                ->where('id', $item->id)
                ->update([
                    'status'     => 'failed',
                    'attempts'   => $newAttempts,
                    'last_error' => $error,
                    'updated_at' => now(),
                ]);

            $this->writeLog("  [RETRY] ✗ Item #{$item->id} permanently FAILED after {$newAttempts}/{$maxAttempts} attempts");
            $this->writeLog("  [RETRY] Last Error: {$error}");

            Log::channel('radiusrelated')->error('[RADIUS QUEUE] Job permanently failed', [
                'job_id'       => $item->id,
                'operation'    => $item->operation,
                'account_no'   => $item->account_no ?? null,
                'attempts'     => $newAttempts,
                'max_attempts' => $maxAttempts,
                'last_error'   => $error,
            ]);

            return;
        }

        $delayMinutes = RadiusRetryPolicy::delayMinutesAfter($newAttempts);
        $nextRetryAt  = RadiusRetryPolicy::nextRetryAt($newAttempts);

        DB::table('radius_operation_queue')
            ->where('id', $item->id)
            ->update([
                'status'        => 'pending',
                'attempts'      => $newAttempts,
                'last_error'    => $error,
                'next_retry_at' => $nextRetryAt,
                'updated_at'    => now(),
            ]);

        $this->writeLog("  [RETRY] Item #{$item->id} scheduled for retry (attempt {$newAttempts}/{$maxAttempts}) at " . $nextRetryAt->format('Y-m-d H:i:s') . " (in {$delayMinutes} minute(s))");
    }

    /**
     * Return jobs abandoned by a worker that stopped mid-item.
     *
     * A row is set to 'processing' before the operation runs. If the worker is
     * killed at that moment nothing ever clears it, and the job would sit in
     * 'processing' for ever. The attempt counter is deliberately left alone: the
     * attempt never produced a result, so it does not consume one of the job's
     * allowed tries.
     */
    private function reclaimStaleProcessing(): int
    {
        $cutoff = Carbon::now()->subMinutes(RadiusRetryPolicy::staleProcessingMinutes());

        $stale = DB::table('radius_operation_queue')
            ->where('status', 'processing')
            ->where('updated_at', '<=', $cutoff)
            ->get(['id', 'operation', 'account_no', 'attempts']);

        foreach ($stale as $row) {
            DB::table('radius_operation_queue')
                ->where('id', $row->id)
                ->where('status', 'processing')
                ->update([
                    'status'        => 'pending',
                    'next_retry_at' => Carbon::now(),
                    'updated_at'    => now(),
                ]);

            $this->writeLog("  [RECOVER] Item #{$row->id} ({$row->operation}, account " . ($row->account_no ?? 'N/A') . ") was left processing by a stopped worker — returned to the queue (attempts still {$row->attempts})");
        }

        return $stale->count();
    }

    /**
     * Cancel a claimed overdue restriction whose account has been paid since it was queued.
     *
     * The payment worker and transaction approval cancel these when they settle a
     * balance, but only while the row is 'pending'. A row that was mid-attempt at that
     * moment goes back to 'pending' after a failure and would otherwise restrict a
     * customer who has already paid. Checking the balance right before running the job
     * closes that gap.
     */
    private function cancelIfSettled(object $item): bool
    {
        if (!in_array($item->operation, ['restricted_user', 'disconnect_user'], true)
            || !in_array($item->source_type, self::BILLING_DRIVEN_SOURCES, true)
            || empty($item->account_no)) {
            return false;
        }

        $balance = DB::table('billing_accounts')
            ->where('account_no', $item->account_no)
            ->value('account_balance');

        if ($balance === null || (float) $balance > self::SETTLED_EPSILON) {
            return false;
        }

        DB::table('radius_operation_queue')
            ->where('id', $item->id)
            ->update([
                'status'       => 'cancelled',
                'last_error'   => 'Cancelled - account balance settled before the queued restriction ran',
                'completed_at' => now(),
                'updated_at'   => now(),
            ]);

        $this->writeLog("  [CANCELLED] Account balance is settled (₱" . number_format((float) $balance, 2) . ") — overdue restriction not applied");
        Log::channel('radiusrelated')->info('[Radius_Queue] [CANCELLED] Overdue ' . $item->operation . ' for account ' . $item->account_no . ' - balance settled before it ran.');

        return true;
    }



    /**
     * Get summary statistics for the queue
     */
    public static function getStats(): array
    {
        return [
            'pending'    => DB::table('radius_operation_queue')->where('status', 'pending')->count(),
            'processing' => DB::table('radius_operation_queue')->where('status', 'processing')->count(),
            'success'    => DB::table('radius_operation_queue')->where('status', 'success')->count(),
            'failed'     => DB::table('radius_operation_queue')->where('status', 'failed')->count(),
            'total'      => DB::table('radius_operation_queue')->count(),
        ];
    }

    /**
     * Write to log file
     */
    private function writeLog(string $message): void
    {
        $timestamp = Carbon::now()->format('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [{$this->logName}] {$message}";

        // Define directory and file path
        $logDir = storage_path('logs/radiusqueue');
        $logFile = $logDir . '/radius_queue.log';

        // Check/Create Directory
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }

        // Write to custom log file
        file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);

        // Also log to Laravel default log
        Log::channel('single')->info("[{$this->logName}] {$message}");
    }
}
