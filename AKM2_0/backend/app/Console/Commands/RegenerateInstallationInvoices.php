<?php

namespace App\Console\Commands;

use App\Models\JobOrder;
use App\Services\InstallationFeeNotificationService;
use Illuminate\Console\Command;

/**
 * Re-renders installation fee invoice PDFs already on file so they show the due date as the
 * installation date. They were generated with a 7-day offset before the due date changed.
 *
 * Only job orders with an invoice_url are touched; the rest have no PDF and their due date
 * is calculated when listed. Customers are not emailed or texted again. The previous PDF
 * stays on Google Drive and its link is printed, so a job order can be put back by
 * restoring invoice_url.
 */
class RegenerateInstallationInvoices extends Command
{
    protected $signature = 'installation-invoices:regenerate
                            {--id=* : Only these job order ids}
                            {--dry-run : List the invoices that would be regenerated, change nothing}';

    protected $description = 'Regenerate installation fee invoice PDFs with the due date on the installation date';

    public function handle(InstallationFeeNotificationService $service)
    {
        $query = JobOrder::with('application')
            ->whereNotNull('invoice_url')
            ->where('invoice_url', '<>', '')
            ->where('installation_fee', '>', 0)
            ->orderBy('id');

        if ($ids = $this->option('id')) {
            $query->whereIn('id', $ids);
        }

        $jobOrders = $query->get();

        if ($jobOrders->isEmpty()) {
            $this->info('No installation invoices to regenerate.');
            return 0;
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->info(($dryRun ? '[DRY RUN] ' : '') . "{$jobOrders->count()} installation invoice(s):");

        $failed = 0;
        foreach ($jobOrders as $jobOrder) {
            $installed = $jobOrder->date_installed ? \Carbon\Carbon::parse($jobOrder->date_installed) : null;
            $due = $installed ? InstallationFeeNotificationService::dueDate($installed)->format('Y-m-d') : 'today (no install date)';
            $label = "JO-{$jobOrder->id} | installed " . ($installed ? $installed->format('Y-m-d') : '-') . " | due {$due}";

            if ($dryRun) {
                $this->line("  {$label}");
                continue;
            }

            $result = $service->regenerateInvoice($jobOrder);

            if ($result['success']) {
                $this->line("  OK   {$label}");
                $this->line("       old: {$result['old_url']}");
                $this->line("       new: {$result['new_url']}");
            } else {
                $failed++;
                $this->error("  FAIL {$label}: {$result['error']}");
            }
        }

        if (!$dryRun) {
            $this->info(($jobOrders->count() - $failed) . " regenerated, {$failed} failed.");
        }

        return $failed > 0 ? 1 : 0;
    }
}
