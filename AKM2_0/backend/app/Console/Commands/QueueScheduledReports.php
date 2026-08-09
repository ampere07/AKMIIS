<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class QueueScheduledReports extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reports:queue';

    protected $description = 'Queue scheduled reports into the email_queue table based on their schedule settings';

    public function handle()
    {
        // Define a dedicated channel for reports tracing
        $logger = \Illuminate\Support\Facades\Log::build([
            'driver' => 'single',
            'path' => storage_path('logs/reports.log'),
        ]);

        $logger->info('--- Starting Scheduled Reports Queue Processing Run ---');

        $autoReportSetting = \App\Models\SettingsAutoReport::firstOrCreate(['id' => 1], ['is_enabled' => true]);
        if (!$autoReportSetting->is_enabled) {
            $logger->info('--- Auto Send Report is disabled. Skipping this run. ---');
            $this->info('Auto Send Report is disabled. Skipping.');
            return Command::SUCCESS;
        }

        $now = \Carbon\Carbon::now('Asia/Manila');
        $currentTime = $now->format('H:i');
        $currentDay = $now->day;
        
        $reports = \App\Models\Report::all();
        $queuedCount = 0;

        foreach ($reports as $report) {
            // Check time first
            if (!$report->report_time) {
                continue;
            }

            // PHP's date might have seconds or single digit minute if stored differently. 
            // The input type="time" normally stores as "HH:mm" (e.g. 14:30).
            $reportTime = \Carbon\Carbon::parse($report->report_time)->format('H:i');
            
            if ($reportTime !== $currentTime) {
                continue;
            }

            $shouldQueue = false;

            if ($report->report_schedule === 'Every Day') {
                $shouldQueue = true;
            } elseif ($report->report_schedule === 'Every Month') {
                if ((int)$report->day === $currentDay) {
                    $shouldQueue = true;
                }
            } elseif ($report->report_schedule === 'Every 3 Months') {
                if ((int)$report->day === $currentDay) {
                    // Check if it's been a multiple of 3 months since creation
                    // Or simply if the current month is 1, 4, 7, 10 for simplicity, 
                    // or based on creation month. Let's use creation month if available.
                    $createdMonth = $report->created_at ? \Carbon\Carbon::parse($report->created_at)->month : 1;
                    $diff = $now->month - $createdMonth;
                    if ($diff % 3 === 0) {
                        $shouldQueue = true;
                    }
                }
            } elseif ($report->report_schedule === 'Every Year') {
                if ((int)$report->day === $currentDay) {
                    $createdMonth = $report->created_at ? \Carbon\Carbon::parse($report->created_at)->month : 1;
                    if ($now->month === $createdMonth) {
                        $shouldQueue = true;
                    }
                }
            }

            if ($shouldQueue) {
                // Determine recipients
                $sendTo = $report->send_to;
                $emails = array_map('trim', explode(',', $sendTo));

                // Roll the reporting window forward each run instead of resending
                // the original date_range every time. The window length comes from
                // the originally configured range; only its position advances.
                $windowRange = $report->date_range;
                $windowTo = null;
                $originalParts = array_map('trim', explode(' to ', (string) $report->date_range));

                if (count($originalParts) === 2 && $originalParts[0] && $originalParts[1]) {
                    try {
                        $originalFrom = \Carbon\Carbon::parse($originalParts[0]);
                        $originalTo = \Carbon\Carbon::parse($originalParts[1]);
                        $periodLengthDays = $originalFrom->diffInDays($originalTo) + 1;

                        if ($report->last_period_end) {
                            $windowFrom = \Carbon\Carbon::parse($report->last_period_end)->addDay();
                        } else {
                            $windowFrom = $originalFrom;
                        }
                        $windowToCarbon = $windowFrom->copy()->addDays($periodLengthDays - 1);

                        $windowRange = $windowFrom->format('Y-m-d') . ' to ' . $windowToCarbon->format('Y-m-d');
                        $windowTo = $windowToCarbon->format('Y-m-d');
                    } catch (\Exception $e) {
                        $logger->error("Report ID {$report->id} ('{$report->report_name}') failed to compute rolling window, falling back to stored date_range: " . $e->getMessage());
                    }
                }

                // Generate fresh attachment for the current rolling window
                $tempPath = null;
                try {
                    if (strtolower($report->report_type) === 'summary') {
                        $pdfService = new \App\Services\ReportPdfService();
                        $tempPath = $pdfService->generateSummaryPdf($report, $windowRange);
                    } else {
                        $csvService = new \App\Services\ReportCsvService();
                        $tempPath = $csvService->generateFile($report->report_type, $windowRange);
                    }
                    $logger->info("Report ID {$report->id} ('{$report->report_name}') attachment generated successfully for window {$windowRange}.");
                } catch (\Exception $e) {
                    $logger->error("Report ID {$report->id} ('{$report->report_name}') CSV generation failed: " . $e->getMessage());
                    \Illuminate\Support\Facades\Log::error('Scheduled Report CSV Generation Failed: ' . $e->getMessage());
                }

                if ($windowTo) {
                    $report->last_period_end = $windowTo;
                    $report->save();
                }

                foreach ($emails as $email) {
                    if (empty($email)) continue;

                    try {

                    \App\Models\EmailQueue::create([
                        'recipient_email' => $email,
                        'email_sender' => 'billing@akmiis.com',
                        'reply_to' => 'billing@akmiis.com',
                        'sender_name' => 'AKM IIS',
                        'subject' => 'Scheduled Report: ' . $report->report_name,
                        'body_html' => 'Report ' . htmlspecialchars($report->report_type) . ' — Period: ' . htmlspecialchars($windowRange),
                        'attachment_path' => $tempPath,
                        'status' => 'pending',
                        'attempts' => 0
                    ]);
                        $queuedCount++;
                        $logger->info("Report ID {$report->id} ('{$report->report_name}') successfully inserted into Email Queue for recipient: {$email}");
                    } catch (\Exception $e) {
                        $logger->error("Report ID {$report->id} ('{$report->report_name}') failed to insert into Email Queue for recipient {$email}. Error: " . $e->getMessage());
                    }
                }
            }
        }

        $logger->info("--- Run Completed. Successfully queued {$queuedCount} scheduled report emails. ---");
        $this->info("Successfully queued {$queuedCount} scheduled report emails.");
        return Command::SUCCESS;
    }
}
