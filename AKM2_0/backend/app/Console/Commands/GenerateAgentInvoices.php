<?php

namespace App\Console\Commands;

use App\Services\AgentInvoiceService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Weekly agent referral invoices.
 *
 * Runs every Monday at 00:00 (Asia/Manila) and bills the seven days immediately
 * before the run — one invoice per team, one per agent who belongs to no team.
 * A run on Monday 17 August bills 10 August 00:00:00 to 16 August 23:59:59; the
 * day of the run is never included.
 *
 * Safe to run as often as you like. An owner already invoiced for the period is
 * skipped, and a customer already billed to that owner is refused by the
 * database, so a second run in the same morning creates nothing.
 *
 *     php artisan cron:generate-agent-invoices
 *
 * Options:
 *     --as-of=YYYY-MM-DD  bill as though the run happened on this date, i.e. the
 *                         seven days before it
 *     --week=YYYY-MM-DD   deprecated alias for --as-of. NOTE the meaning changed
 *                         with the move to a rolling window: it once meant "the
 *                         calendar week containing this date", and now means the
 *                         week BEFORE it. A notice is printed when it is used.
 *     --agent=ID          only the owner this agent belongs to (a team or
 *                         themselves), for testing one case
 *     --dry-run           report what would be billed without writing anything
 */
class GenerateAgentInvoices extends Command
{
    protected $signature = 'cron:generate-agent-invoices
                            {--as-of= : Bill the seven days before this date (YYYY-MM-DD)}
                            {--week= : Deprecated alias for --as-of}
                            {--agent= : Only the owner this agent id belongs to}
                            {--dry-run : Report what would be billed without writing it}';

    protected $description = 'Generate the weekly referral invoice for every agent team and solo agent.';

    public function handle(AgentInvoiceService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $asOf = null;

        $given = $this->option('as-of') ?: $this->option('week');

        if ($this->option('week') && !$this->option('as-of')) {
            $this->warn('[AGENT INVOICES] --week is deprecated; use --as-of. It now means'
                . ' the seven days BEFORE the date given, not the week containing it.');
        }

        if ($given) {
            try {
                $asOf = Carbon::parse($given);
            } catch (Throwable $e) {
                $this->error("[AGENT INVOICES] Could not read \"{$given}\" as a date: " . $e->getMessage());
                return self::FAILURE;
            }
        }

        $agentId = $this->option('agent');
        $agentId = ($agentId === null || $agentId === '') ? null : (int) $agentId;

        $this->info('[AGENT INVOICES] Starting...' . ($dryRun ? ' (dry run)' : ''));

        if ($dryRun) {
            return $this->reportDryRun($service, $asOf, $agentId);
        }

        try {
            $summary = $service->generateForWeek($asOf, $agentId);
        } catch (Throwable $e) {
            $this->error('[AGENT INVOICES] Fatal error: ' . $e->getMessage());
            Log::channel('single')->error('[AGENT INVOICES] Fatal error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return self::FAILURE;
        }

        $this->info(sprintf(
            '[AGENT INVOICES] Week %s to %s — Owners: %d, Invoices: %d, Already invoiced: %d, Nothing to bill: %d, '
            . 'Customers: %d, Already billed: %d, Amount: %s, PDFs: %d (failed %d), Errors: %d',
            $summary['period_start'],
            $summary['period_end'],
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

        return $summary['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** List the owners that would be billed, writing nothing. */
    private function reportDryRun(AgentInvoiceService $service, ?Carbon $asOf, ?int $agentId): int
    {
        try {
            $owners = $service->resolveOwners($agentId);
        } catch (Throwable $e) {
            $this->error('[AGENT INVOICES] Could not list agents: ' . $e->getMessage());
            return self::FAILURE;
        }

        // Asked of the service, not worked out again here: a dry run that
        // reported a different week from the one a real run would bill would be
        // worse than no dry run at all.
        [$periodStart, $periodEnd] = $service->periodFor($asOf);
        $this->line(sprintf(
            '  billing %s 00:00:00 to %s 23:59:59  (the 7 days before %s)',
            $periodStart->format('Y-m-d'),
            $periodEnd->format('Y-m-d'),
            ($asOf ? $asOf->copy() : Carbon::now())->format('Y-m-d')
        ));

        foreach ($owners as $owner) {
            $this->line(sprintf(
                '  %-14s %s (%d agent(s))',
                $owner['type'],
                $owner['team_name'] ?? $owner['agent_name'],
                count($owner['members'])
            ));
        }

        $this->info('[AGENT INVOICES] Dry run — ' . count($owners) . ' owner(s) would be evaluated, nothing written.');

        return self::SUCCESS;
    }
}
