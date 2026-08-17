<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // ===================================================================
        // BILLING GENERATION (DEDICATED CRON JOB)
        // ===================================================================
        
        // Generate daily billings at 1:00 AM every day
        // Uses: EnhancedBillingGenerationServiceWithNotifications
        // Dependencies: BillingNotificationService, EmailQueueService, 
        //               GoogleDrivePdfGenerationService, ItexmoSmsService
        // Logs: storage/logs/billing/billinggeneration.log
        $schedule->command('cron:generate-daily-billings')
                 ->dailyAt('01:00')
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->onSuccess(function () {
                     \Illuminate\Support\Facades\Log::info('Billing generation cron completed successfully');
                 })
                 ->onFailure(function () {
                     \Illuminate\Support\Facades\Log::error('Billing generation cron failed');
                 });

        // ===================================================================
        // BILLING NOTIFICATIONS
        // ===================================================================

        // Send overdue notices at 10:00 AM for invoices 1 day past due
        // Uses: BillingNotificationService
        // Dependencies: EmailQueueService, GoogleDrivePdfGenerationService, ItexmoSmsService
        $schedule->command('billing:send-overdue --days=1')
                 ->dailyAt('10:00')
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->onSuccess(function () {
                     \Illuminate\Support\Facades\Log::info('Overdue notices sent successfully');
                 })
                 ->onFailure(function () {
                     \Illuminate\Support\Facades\Log::error('Overdue notices sending failed');
                 });

        // ===================================================================
        // OVERDUE & DISCONNECTION NOTICES
        // ===================================================================

        // Note: Overdue and Disconnection notices are now generated and sent 
        // as part of the 'cron:generate-daily-billings' command defined above.
        // The previous standalone commands 'cron:process-overdue-notifications'
        // and 'cron:process-disconnection-notices' have been deprecated and removed.

        // ===================================================================
        // AUTO DISCONNECT & PULLOUT
        // ===================================================================

        // Automatically disconnect overdue accounts and create pullout requests
        // Runs at 2:00 AM daily (after billing generation)
        // Uses: AutoDisconnectService, ManualRadiusOperationsService
        // Dependencies: BillingConfig for DC fee and offset settings
        // Disconnects accounts X days overdue (configurable via billing_config.disconnection_day)
        // Creates pullout requests for accounts Y days overdue (configurable via billing_config.pullout_offset)
        // Logs: storage/logs/disconnectionday.log
        $schedule->command('cron:auto-disconnect-pullout')
                 ->dailyAt('02:00')
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->onSuccess(function () {
                     \Illuminate\Support\Facades\Log::info('Auto disconnect/pullout completed successfully');
                 })
                 ->onFailure(function () {
                     \Illuminate\Support\Facades\Log::error('Auto disconnect/pullout failed');
                 });

        // ===================================================================
        // VIP ACCOUNTS EXPIRATION CHECK
        // ===================================================================

        // Check VIP accounts for expiration daily at midnight
        // Uses: ManualRadiusOperationsService
        // Logs: storage/logs/vipChecker.log
        $schedule->command('vip:check-expiration')
                 ->dailyAt('00:00')
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->onSuccess(function () {
                     \Illuminate\Support\Facades\Log::info('VIP expiration check completed successfully');
                 })
                 ->onFailure(function () {
                     \Illuminate\Support\Facades\Log::error('VIP expiration check failed');
                 });

        // ===================================================================
        // EMAIL QUEUE PROCESSING (DEDICATED CRON JOBS)
        // ===================================================================

        // Process pending emails every minute
        // Uses: EmailQueueService via dedicated cron command
        // Dependencies: ResendEmailService
        // Processes up to 50 emails per run
        $schedule->command('cron:process-email-queue')
                 ->everyMinute()
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->onSuccess(function () {
                     \Illuminate\Support\Facades\Log::info('Email queue cron completed successfully');
                 })
                 ->onFailure(function () {
                     \Illuminate\Support\Facades\Log::error('Email queue cron failed');
                 });

        // Retry failed emails every 5 minutes
        // Uses: EmailQueueService via dedicated cron command
        // Dependencies: ResendEmailService
        // Retries up to 20 failed emails with max 3 attempts
        $schedule->command('cron:retry-failed-emails')
                 ->everyFiveMinutes()
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->onSuccess(function () {
                     \Illuminate\Support\Facades\Log::info('Failed emails retry cron completed successfully');
                 })
                 ->onFailure(function () {
                     \Illuminate\Support\Facades\Log::error('Failed emails retry cron failed');
                 });

        // ===================================================================
        // AUTOMATED REPORTS QUEUING
        // ===================================================================
        
        $schedule->command('reports:queue')
                 ->everyMinute()
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->onSuccess(function () {
                     // \Illuminate\Support\Facades\Log::info('Reports queue cron completed successfully');
                 })
                 ->onFailure(function () {
                     \Illuminate\Support\Facades\Log::error('Reports queue cron failed');
                 });

        // ===================================================================
        // RADIUS STATUS SYNC
        // ===================================================================

        // Sync RADIUS user status and sessions every 2 minutes
        // Uses: RadiusStatusSyncService
        // Dependencies: RadiusConfig, BillingAccounts, TechnicalDetails, OnlineStatus
        // Logs: storage/logs/radiussync/radiussync.log
        $schedule->command('cron:sync-radius-status')
                 ->everyTwoMinutes()
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->onSuccess(function () {
                     \Illuminate\Support\Facades\Log::info('RADIUS status sync completed successfully');
                 })
                 ->onFailure(function () {
                     \Illuminate\Support\Facades\Log::error('RADIUS status sync failed');
                 });

        // ===================================================================
        // RADIUS OPERATION RETRY QUEUE
        // ===================================================================

        // Retry failed RADIUS operations every 2 minutes
        // Uses: RadiusQueueService, ManualRadiusOperationsService
        // Processes up to 20 pending items per run with exponential backoff
        // Logs: storage/logs/radiusrelated.log
        $schedule->command('cron:process-radius-queue')
                 ->everyTwoMinutes()
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->onSuccess(function () {
                     \Illuminate\Support\Facades\Log::info('RADIUS queue processing completed successfully');
                 })
                 ->onFailure(function () {
                     \Illuminate\Support\Facades\Log::error('RADIUS queue processing failed');
                 });

        // ===================================================================
        // PAYMENT PROCESSING
        // ===================================================================

        // Process pending payments every 2 minutes
        // Uses: PaymentWorkerService
        // Dependencies: Xendit API
        $schedule->command('payments:process')
                 ->everyTwoMinutes()
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->onSuccess(function () {
                     \Illuminate\Support\Facades\Log::info('Payment processing completed successfully');
                 })
                 ->onFailure(function () {
                     \Illuminate\Support\Facades\Log::error('Payment processing failed');
                 });

        // Retry failed payments daily at 2:00 PM
        // Uses: PaymentWorkerService
        // Dependencies: Xendit API
        $schedule->command('payments:retry-failed')
                 ->dailyAt('14:00')
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->onSuccess(function () {
                     \Illuminate\Support\Facades\Log::info('Failed payments retry completed');
                 })
                 ->onFailure(function () {
                     \Illuminate\Support\Facades\Log::error('Failed payments retry failed');
                 });

        // Reconcile pending Xendit payments every 5 minutes
        // Uses: XenditReconciliationService
        // Dependencies: Xendit API
        //
        // The safety net under the webhook. Asks Xendit directly about every
        // payment we created but never saw settle, so a dropped callback no
        // longer strands a paying customer at PENDING. It only ever moves a row
        // to QUEUED — 'payments:process' still does the posting — so this
        // cannot double-credit an account no matter how often it runs.
        //
        // Every 5 minutes rather than every 2: the per-row backoff inside the
        // service is what controls how often any given payment is actually
        // looked up, and the tightest tier there is 2 minutes.
        $schedule->command('cron:reconcile-xendit-payments')
                 ->everyFiveMinutes()
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->onSuccess(function () {
                     \Illuminate\Support\Facades\Log::info('Xendit reconciliation completed successfully');
                 })
                 ->onFailure(function () {
                     \Illuminate\Support\Facades\Log::error('Xendit reconciliation failed');
                 });

        // ===================================================================
        // RECONCILIATION TOOLS — NIGHTLY UNATTENDED PASSES
        // ===================================================================

        // SmartOLT: align ONU names to RADIUS usernames, retire long-dark ONUs.
        //
        // Uses: SmartOltReconciliationService (+ RadiusReconciliationService for
        //       live session state)
        // Dependencies: SmartOLT API, MikroTik User Manager REST
        // Logs: storage/logs/smartolt/daily-automation.log
        //
        // 02:00 — after the billing generators and the disconnect sweep have
        // settled, so an account terminated overnight is already terminated in
        // the database by the time the cleanup phase reads it.
        //
        // Safe if it runs late, twice, or is cut short. Every phase recomputes
        // what is left to do from current state rather than replaying a cursor:
        // an ONU already named for its subscriber is skipped and a deleted ONU
        // is gone from the inventory, so a second run applies nothing. A run
        // stopped by a SmartOLT quota limit checkpoints in `tool_jobs` and the
        // next run resumes from there. Deletion additionally requires the ONU to
        // be dark past the threshold, its account Terminated, no open job order,
        // and no live RADIUS session — and refuses to run at all when billing or
        // session state cannot be read.
        $schedule->command('cron:smartolt-daily-automation')
                 ->dailyAt('02:00')
                 ->timezone(config('app.timezone'))
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->onSuccess(function () {
                     \Illuminate\Support\Facades\Log::info('SmartOLT daily automation completed successfully');
                 })
                 ->onFailure(function () {
                     \Illuminate\Support\Facades\Log::error('SmartOLT daily automation failed');
                 });

        // MikroTik RADIUS: adopt missing PPPoE passwords, settle plan groups,
        // enforce restriction on accounts billing has written off.
        //
        // Uses: RadiusReconciliationService
        // Dependencies: MikroTik User Manager REST
        // Logs: storage/logs/radiusreconcile/daily-reconcile.log
        //
        // 03:15 — an hour after the SmartOLT pass so the two never contend for
        // the same RouterOS devices, and after the disconnect sweep so the
        // restriction phase acts on settled billing statuses.
        //
        // Safe if it runs late or twice: every mutation compares current state
        // first and skips when both sides already agree, so a re-run applies
        // nothing. It creates no records and enqueues nothing, so there is
        // nothing a repeat run could duplicate. Account creation, deletion and
        // duplicate resolution are deliberately NOT automated — they stay in the
        // operator's tool.
        $schedule->command('cron:radius-reconcile-daily')
                 ->dailyAt('03:15')
                 ->timezone(config('app.timezone'))
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->onSuccess(function () {
                     \Illuminate\Support\Facades\Log::info('RADIUS daily reconciliation completed successfully');
                 })
                 ->onFailure(function () {
                     \Illuminate\Support\Facades\Log::error('RADIUS daily reconciliation failed');
                 });

        // ===================================================================
        // MAINTENANCE & CLEANUP
        // ===================================================================

        // Cleanup worker locks every hour
        // Prevents stale locks from blocking payment processing
        $schedule->command('worker:cleanup-locks')
                 ->hourly()
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->onSuccess(function () {
                     \Illuminate\Support\Facades\Log::info('Worker locks cleaned up');
                 });

        // ===================================================================
        // AGENT REFERRAL INVOICES
        // ===================================================================

        // One referral invoice per agent team, and one per solo agent, for the
        // week that has just ended. Runs at 00:00 every Monday in the app
        // timezone (config/app.php -> Asia/Manila).
        //
        // Uses: AgentInvoiceService, AgentInvoicePdfService
        // Logs: storage/logs/agent-invoices/Agent_Invoices.log
        //
        // Safe if it runs late or twice: an owner already invoiced for the week
        // is skipped, and the database refuses a customer already billed to
        // them, so a repeat run creates nothing.
        $schedule->command('cron:generate-agent-invoices')
                 ->weeklyOn(1, '00:00')
                 ->timezone(config('app.timezone'))
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->onSuccess(function () {
                     \Illuminate\Support\Facades\Log::info('Agent invoice generation completed successfully');
                 })
                 ->onFailure(function () {
                     \Illuminate\Support\Facades\Log::error('Agent invoice generation failed');
                 });

        // ===================================================================
        // TECHNICIAN LIVE LOCATION
        // ===================================================================
        // The stale-location sweep (cron:mark-stale-locations) is invoked directly
        // from the system crontab every minute, e.g.:
        //   * * * * * cd /home/akmcbms/web/backend.akmiis.com/public_html && /usr/bin/php artisan cron:mark-stale-locations
        // so it is intentionally NOT registered with the Laravel scheduler here.

        // ===================================================================
        // OPTIONAL: Additional hourly billing checks during business hours
        // Uncomment if you want additional billing generation checks
        // ===================================================================
        // $schedule->command('billing:generate-daily')
        //          ->hourly()
        //          ->between('08:00', '18:00')
        //          ->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}



