<?php

namespace App\Console\Commands;

use App\Services\SmartOltReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The unattended nightly SmartOLT pass.
 *
 * Refreshes the ONU inventory and statuses, reads the live PPPoE sessions off every
 * configured RADIUS device, matches each ONU's bridge MAC to a session's
 * calling-station-id, renames matched ONUs to their subscriber's RADIUS username,
 * and unprovisions ONUs that have been offline, in LOS or in power failure for the
 * threshold and clear every safety guard.
 *
 * What makes it safe to run repeatedly. Nothing here is keyed off a cursor that a
 * second run could replay: every phase recomputes what is left to do from current
 * state. A rename whose ONU already carries the username is a skip, and a deleted
 * ONU is no longer in the inventory to be deleted again. A run cut short by a
 * SmartOLT quota stop therefore loses no progress — tomorrow's run simply finds
 * less to do — and any operator job that the same quota stop parked is resumed from
 * its own checkpoint in `tool_jobs` before this command starts its own work.
 *
 * What makes it safe to run at all. An ONU is only removed when it is dark past the
 * threshold, its billing account is Terminated, it has no open job order, and no
 * one is holding a live RADIUS session on it. If billing state or RADIUS session
 * state cannot be read, the cleanup phase does not run: not knowing who is online
 * is treated as a blocker, never as "nobody is".
 */
class SmartOltDailyAutomationCron extends Command
{
    protected $signature = 'cron:smartolt-daily-automation
                            {--offline-days= : Consecutive offline/LOS/PwrFail days before an ONU may be removed}
                            {--no-rename : Skip the MAC-match rename phase}
                            {--no-cleanup : Skip the deletion phase}
                            {--max-renames=500 : Ceiling on renames applied in one run}
                            {--max-deletes=100 : Ceiling on ONUs unprovisioned in one run}
                            {--dry-run : Report what would change without calling SmartOLT}';

    protected $description = 'Sync SmartOLT ONUs, align ONU names to RADIUS usernames by MAC, and unprovision long-dark ONUs';

    public function __construct(private SmartOltReconciliationService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $log     = $this->channel();
        $started = microtime(true);

        $options = [
            'offline_days' => $this->option('offline-days') !== null
                ? (int) $this->option('offline-days')
                : SmartOltReconciliationService::AUTOMATION_OFFLINE_DAYS,
            'rename'      => !$this->option('no-rename'),
            'cleanup'     => !$this->option('no-cleanup'),
            'dry_run'     => (bool) $this->option('dry-run'),
            'max_renames' => (int) $this->option('max-renames'),
            'max_deletes' => (int) $this->option('max-deletes'),
        ];

        $this->info('===========================================');
        $this->info('SmartOLT Daily Automation: ' . now()->format('Y-m-d H:i:s'));
        $this->info('===========================================');

        $log->info('=== SmartOLT daily automation started ===', [
            'timestamp' => now()->toDateTimeString(),
            'config'    => $options,
        ]);

        try {
            $result = $this->service->runDailyAutomation($options);
        } catch (Throwable $e) {
            $log->error('SmartOLT daily automation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            Log::error('cron:smartolt-daily-automation failed', ['error' => $e->getMessage()]);

            $this->error('Automation failed: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $this->report($result, $log, $started);

        // A per-item failure is a reported outcome, not a failed run — the schedule
        // must not start alerting because three ONUs out of four thousand refused.
        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function report(array $result, LoggerInterface $log, float $started): void
    {
        $duration = round(microtime(true) - $started, 2);

        $this->newLine();
        $this->line("  Applied:  {$result['success']}");
        $this->line("  Skipped:  {$result['skipped']}");
        $this->line("  Failed:   {$result['failed']}");

        foreach ($result['phases'] as $name => $phase) {
            if (!is_array($phase)) {
                continue;
            }

            $parts = [];
            foreach ($phase as $key => $value) {
                $parts[] = $key . '=' . (is_bool($value) ? ($value ? 'yes' : 'no') : $value);
            }

            $this->line('  [' . $name . '] ' . implode(' ', $parts));
        }

        if (!empty($result['errors'])) {
            $this->newLine();
            $this->warn('Errors:');
            foreach ($result['errors'] as $error) {
                $this->warn('  ' . (is_array($error) ? json_encode($error) : $error));
            }
        }

        $log->info('=== SmartOLT daily automation completed ===', [
            'applied'          => $result['success'],
            'skipped'          => $result['skipped'],
            'failed'           => $result['failed'],
            'phases'           => $result['phases'],
            'errors'           => $result['errors'],
            'duration_seconds' => $duration,
        ]);

        $this->newLine();
        $this->info('Completed in ' . $duration . 's');
    }

    /**
     * Dedicated log file, matching the cron channel pattern used by the other
     * sweeps: a run must be reconstructable from this file alone.
     */
    private function channel(): LoggerInterface
    {
        try {
            $path = storage_path('logs/smartolt');
            if (!file_exists($path)) {
                mkdir($path, 0755, true);
            }

            return Log::build([
                'driver' => 'single',
                'path'   => $path . '/daily-automation.log',
            ]);
        } catch (Throwable $e) {
            // An unwritable log directory must not stop the sweep.
            return Log::channel('stack');
        }
    }
}
