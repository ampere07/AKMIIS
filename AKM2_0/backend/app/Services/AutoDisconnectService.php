<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\BillingAccount;
use App\Models\ServiceOrder;
use App\Models\BillingConfig;
use App\Models\SMSTemplate;
use App\Models\EmailTemplate;
use App\Services\EmailQueueService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Throwable;
use Exception;

class AutoDisconnectService
{
    private $logName = 'Auto_DC';
    private $radiusService;
    private $smsService;
    private $emailQueueService;
    private $lockName = 'auto_disconnect_worker';
    private $lockTimeout = 300; // 5 minutes max execution time
    private $hasLock = false;

    /** Emit extra-detailed [VERBOSE] lines to the log/CLI. */
    private $verbose = true;

    /** Mirror every log line to stdout when running from the CLI. */
    private $cliEcho = true;

    /** Whether the current process is running under the CLI SAPI (set in constructor). */
    private $isCli = false;

    /**
     * Fixed 30-day billing-cycle configuration.
     *
     * The billing cycle behaves as a fixed 30-day calendar:
     *   - Day 31 never exists in billing-cycle computation.
     *   - Billing-cycle day 30 always exists logically (even in February).
     *   - Computed billing-cycle days are normalized into valid real calendar dates.
     *   - Proration always divides by 30 (never 28, 29 or 31).
     */
    private const BILLING_CYCLE_DAYS = 30;
    private const DC_OFFSET_DAYS = 10;
    private const ADDITIONAL_INVOICE_OFFSET_DAYS = 7;
    private const PRORATE_DIVISOR_DAYS = 30;

    // Due-date offset applied to a generated additional invoice (mirrors the billing generator's DAYS_UNTIL_DUE).
    private const ADDITIONAL_INVOICE_DUE_OFFSET_DAYS = 7;

    // Marker used in service_charge_logs to identify (and dedupe) auto-generated additional invoices.
    private const ADDITIONAL_INVOICE_CHARGE_TYPE = 'Prorated Additional Invoice';

    public function __construct(
        ManualRadiusOperationsService $radiusService,
        ?ItexmoSmsService $smsService = null,
        ?EmailQueueService $emailQueueService = null
    ) {
        $this->radiusService = $radiusService;
        $this->smsService = $smsService;
        $this->emailQueueService = $emailQueueService;
        $this->isCli = (PHP_SAPI === 'cli');
    }

    /**
     * Toggle verbose file logging and CLI echo at runtime.
     *
     * @param bool $verbose Emit [VERBOSE] detail lines.
     * @param bool $cliEcho Mirror log lines to stdout when running in the CLI.
     */
    public function setVerbose(bool $verbose = true, bool $cliEcho = true): self
    {
        $this->verbose = $verbose;
        $this->cliEcho = $cliEcho;
        return $this;
    }

    /**
     * Process automatic disconnections based on the fixed 30-day billing cycle.
     *
     * DC/restriction date = billing day + DC_OFFSET_DAYS fixed billing-cycle days,
     * normalized into a valid real calendar date. Accounts whose computed DC date
     * equals today (and whose latest invoice is still Unpaid/Partial) are restricted.
     */
    public function processAutoDisconnect(): array
    {
        $this->writeLog("╔════════════════════════════════════════════════════════════════╗");
        $this->writeLog("║         STARTING AUTO DISCONNECTION PROCESS                    ║");
        $this->writeLog("╚════════════════════════════════════════════════════════════════╝");
        $startTime = Carbon::now();
        $this->writeLog("Start Time: " . $startTime->format('Y-m-d H:i:s'));
        $this->writeVerbose("Runtime: PHP " . PHP_VERSION . " | SAPI: " . PHP_SAPI . " | Host: " . gethostname() . " | PID: " . getmypid() . " | TZ: " . date_default_timezone_get());
        $this->writeVerbose("Memory at start: " . $this->formatBytes(memory_get_usage(true)));
        $this->writeLog("");

        if (!$this->acquireLock()) {
            $this->writeLog("[LOCK] Process is locked by another worker. Exiting.");
            return [
                'success' => false,
                'error' => 'Process is locked by another worker'
            ];
        }

        try {
            $config = BillingConfig::first();

            if (!$config) {
                $this->writeLog("[ERROR] Billing configuration not found");
                throw new Exception("Billing configuration not found");
            }

            $dcFee = $config->disconnection_fee ?? 0.00;
            $today = Carbon::today();
            $off = $this->getScheduleOffsets($config);

            $this->writeLog("[CONFIG] Billing Cycle: fixed " . self::BILLING_CYCLE_DAYS . "-day cycle");
            $this->writeLog("[CONFIG] Due Date Offset: {$off['due_offset']} day(s) after billing day (billing_config.due_date_day)");
            $this->writeLog("[CONFIG] Disconnection Offset: {$off['dc_after_due']} day(s) after due date (billing_config.disconnection_day)");
            $this->writeLog("[CONFIG] => DC/Restriction Offset: {$off['dc_offset']} billing-cycle day(s) after billing day");
            $this->writeLog("[CONFIG] Additional Invoice Offset: {$off['grace_after_dc']} billing-cycle day(s) after DC day");
            $this->writeLog("[CONFIG] Disconnection Fee: ₱" . number_format($dcFee, 2));
            $this->writeLog("[CONFIG] Today: " . $today->format('Y-m-d'));
            $this->writeLog("");

            if (!$off['dc_enabled']) {
                $this->writeLog("[INFO] Auto-DC is disabled (billing_config.disconnection_day = 0). No disconnections will be processed.");
                $endTime = Carbon::now();
                $duration = $endTime->diffInSeconds($startTime);
                $this->releaseLock();
                return [
                    'success' => true,
                    'processed' => 0,
                    'skipped' => 0,
                    'errors' => [],
                    'duration' => $duration
                ];
            }

            // Resolve which billing-cycle days have their DC/restriction date == today,
            // using fixed billing-cycle arithmetic (NOT real-calendar addDays).
            $dcCycleMap = $this->resolveCycleDaysForTarget($today, $off['dc_offset']);
            $dcBillingDays = array_keys($dcCycleMap);

            if (empty($dcBillingDays)) {
                $this->writeLog("[SCHEDULE] No billing-cycle day resolves to a DC/restriction date of today.");
            } else {
                $this->writeLog("[SCHEDULE] Billing day(s) due for DC today (billing day + {$off['dc_offset']} cycle days):");
                foreach ($dcCycleMap as $bday => $info) {
                    $this->writeLog(
                        "  • Billing Day {$bday} (cycle " . $info['cycle_year'] . "-" . str_pad((string) $info['cycle_month'], 2, '0', STR_PAD_LEFT) . ")"
                        . " → DC " . $info['target']['date']->format('Y-m-d')
                    );
                }
            }
            $this->writeLog("");

            // Fetch ONLY the latest invoice for each account, then restrict to accounts
            // whose billing day is scheduled for DC today.
            $this->writeLog("[QUERY] Searching for accounts scheduled for DC/restriction today...");

            $invoices = null;
            $totalCount = 0;
            if (!empty($dcBillingDays)) {
                // 1. Get the IDs of the absolute latest invoice for every account
                $latestInvoiceIds = DB::table('invoices')
                    ->select(DB::raw('MAX(id) as id'))
                    ->groupBy('account_no')
                    ->pluck('id');

                $this->writeVerbose("Latest-invoice candidates across all accounts: " . $latestInvoiceIds->count());
                $this->writeVerbose("Filtering latest invoices by status IN (Unpaid, Partial) AND billing_day IN (" . implode(', ', $dcBillingDays) . ")");

                // 2. Fetch those latest invoices, still overdue, for the scheduled billing days
                $invoices = Invoice::with(['billingAccount.customer', 'billingAccount.technicalDetails', 'billingAccount.plan', 'billingAccount.billingStatus'])
                    ->whereIn('id', $latestInvoiceIds)
                    ->whereIn('status', ['Unpaid', 'Partial'])
                    ->whereHas('billingAccount', function ($query) use ($dcBillingDays) {
                        $query->whereIn('billing_day', $dcBillingDays);
                    })
                    ->get();

                $totalCount = $invoices->count();
            }
            $this->writeLog("[RESULT] Found {$totalCount} account(s) scheduled for DC/restriction today.");
            $this->writeLog("");

            $processedCount = 0;
            $skippedCount = 0;
            $errors = [];
            $counter = 0;

            if ($totalCount > 0) {
                $this->writeLog("[PROCESS] Starting disconnection process...");
                $this->writeLog("─────────────────────────────────────────────────────────────────");

                foreach ($invoices as $invoice) {
                    $counter++;
                    $this->writeLog("");
                    $this->writeLog("[{$counter}/{$totalCount}] ══════════════════════════════════════════════");

                    $billingAccount = $invoice->billingAccount;
                    $billingDay = $billingAccount ? (int) ($billingAccount->billing_day ?? 0) : 0;
                    $schedule = $dcCycleMap[$billingDay] ?? null;

                    $this->writeVerbose(
                        "DC candidate: invoice#{$invoice->id} account={$invoice->account_no}"
                        . " status={$invoice->status}"
                        . " due_date=" . ($invoice->due_date ? $invoice->due_date->format('Y-m-d') : 'n/a')
                        . " billing_day={$billingDay}"
                    );

                    $result = $this->processDisconnection($invoice, $schedule, $off);

                    if ($result['success']) {
                        $processedCount++;
                        $this->writeLog("[{$counter}/{$totalCount}] ✓ SUCCESS - Transaction Committed");
                    } else {
                        $skippedCount++;
                        $this->writeLog("[{$counter}/{$totalCount}] ⊘ SKIPPED: {$result['reason']}");
                        if (isset($result['reason'])) {
                            $errors[] = "Account {$invoice->account_no}: {$result['reason']}";
                        }
                    }
                }
            } else {
                $this->writeLog("[INFO] No accounts to disconnect today.");
            }

            // NOTE: The grace-period / additional-invoice automation runs as a separate
            // step (processGracePeriodCharge), invoked by the console command right after
            // this method returns. It is intentionally NOT called here to avoid double
            // charging.

            $endTime = Carbon::now();
            $duration = $endTime->diffInSeconds($startTime);

            $this->writeLog("");
            $this->writeLog("╔════════════════════════════════════════════════════════════════╗");
            $this->writeLog("║         AUTO DISCONNECTION COMPLETE                            ║");
            $this->writeLog("╚════════════════════════════════════════════════════════════════╝");
            $this->writeLog("Summary:");
            $this->writeLog("  • Total Found: {$totalCount}");
            $this->writeLog("  • Successfully Processed: {$processedCount}");
            $this->writeLog("  • Skipped: {$skippedCount}");
            $this->writeLog("  • Errors: " . count($errors));
            $this->writeLog("  • Duration: {$duration} second(s)");
            $this->writeVerbose("Peak memory: " . $this->formatBytes(memory_get_peak_usage(true)));
            $this->writeLog("End Time: " . $endTime->format('Y-m-d H:i:s'));
            $this->writeLog("");

            if (!empty($errors)) {
                $this->writeLog("[ERROR DETAILS]");
                foreach ($errors as $error) {
                    $this->writeLog("  × {$error}");
                }
                $this->writeLog("");
            }

            $this->releaseLock();
            return [
                'success' => true,
                'processed' => $processedCount,
                'skipped' => $skippedCount,
                'errors' => $errors,
                'duration' => $duration
            ];

        } catch (Throwable $e) {
            $endTime = Carbon::now();
            $duration = $endTime->diffInSeconds($startTime);

            $this->writeLog("");
            $this->writeLog("╔════════════════════════════════════════════════════════════════╗");
            $this->writeLog("║         CRITICAL ERROR                                         ║");
            $this->writeLog("╚════════════════════════════════════════════════════════════════╝");
            $this->writeLog("[CRITICAL] " . $e->getMessage());
            $this->writeLog("[TRACE] " . $e->getTraceAsString());
            $this->writeLog("End Time: " . $endTime->format('Y-m-d H:i:s'));
            $this->writeLog("Duration: {$duration} second(s)");
            $this->writeLog("");

            $this->releaseLock();
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Process a single disconnection
     *
     * @param Invoice     $invoice
     * @param array|null  $schedule Fixed billing-cycle schedule info for this account:
     *                              ['cycle_year','cycle_month','target' => addFixedBillingCycleDays() result]
     */
    private function processDisconnection(Invoice $invoice, ?array $schedule = null, array $offsets = []): array
    {
        $accountNo = $invoice->account_no;
        $this->writeLog("[ACCOUNT] {$accountNo}");

        $billingAccount = $invoice->billingAccount;

        if (!$billingAccount) {
            $this->writeLog("  [SKIP] Billing account not found");
            return ['success' => false, 'reason' => 'Billing account not found'];
        }

        // Log the fixed billing-cycle date coordinates that scheduled this DC.
        $billingDay = (int) ($billingAccount->billing_day ?? 0);
        if ($schedule) {
            $cycleYear = $schedule['cycle_year'];
            $cycleMonth = $schedule['cycle_month'];
            $dueOffset = (int) ($offsets['due_offset'] ?? 0);
            $dcOffset = (int) ($offsets['dc_offset'] ?? self::DC_OFFSET_DAYS);
            $noticeAfterDue = (int) ($offsets['notice_after_due'] ?? 0);
            $noticeOffset = (int) ($offsets['notice_offset'] ?? 0);
            $normalizedBilling = $this->normalizeBillingCycleDate($cycleYear, $cycleMonth, $billingDay);
            $due = $this->addFixedBillingCycleDays($cycleYear, $cycleMonth, $billingDay, $dueOffset);
            $notice = $this->addFixedBillingCycleDays($cycleYear, $cycleMonth, $billingDay, $noticeOffset);
            $dc = $schedule['target'];

            $this->writeLog("  [SCHEDULE] Billing Day: {$billingDay}");
            $this->writeLog("  [SCHEDULE] Billing Cycle Year: {$cycleYear}");
            $this->writeLog("  [SCHEDULE] Billing Cycle Month: {$cycleMonth}");
            $this->writeLog("  [SCHEDULE] Normalized Billing Date: " . $normalizedBilling->format('Y-m-d'));
            $this->writeLog("  [SCHEDULE] Due Offset Days: {$dueOffset}");
            $this->writeLog("  [SCHEDULE] Normalized Due Date: " . $due['date']->format('Y-m-d'));
            $this->writeLog("  [SCHEDULE] DC Notice Date (due + {$noticeAfterDue}, informational): " . $notice['date']->format('Y-m-d'));
            $this->writeLog("  [SCHEDULE] DC Offset Days (from billing day): {$dcOffset}");
            $this->writeLog("  [SCHEDULE] DC Cycle Day: {$dc['day']}");
            $this->writeLog("  [SCHEDULE] DC Cycle Month: {$dc['month']}");
            $this->writeLog("  [SCHEDULE] Normalized DC Date: " . $dc['date']->format('Y-m-d'));
        } else {
            $this->writeLog("  [SCHEDULE] Billing Day: {$billingDay} (no fixed-cycle schedule resolved)");
        }

        // Check if already disconnected today
        $alreadyDisconnected = DB::table('disconnected_logs')
            ->where('account_id', $billingAccount->id)
            ->whereDate('created_at', Carbon::today())
            ->exists();

        if ($alreadyDisconnected) {
            $this->writeLog("  [SKIP] Already disconnected today");
            return ['success' => false, 'reason' => 'Already disconnected today'];
        }

        // Validate account balance
        $currentBalance = floatval($billingAccount->account_balance);
        $this->writeLog("  [INFO] Current Balance: ₱" . number_format($currentBalance, 2));

        if ($currentBalance <= 0.00) {
            $this->writeLog("  [SKIP] Balance is zero or negative (already paid)");
            return ['success' => false, 'reason' => 'Balance already paid'];
        }

        // Check if already inactive or pullout
        $billingStatus = $billingAccount->billingStatus ? $billingAccount->billingStatus->status_name : '';
        $this->writeLog("  [INFO] Current Status: {$billingStatus}");

        if (in_array($billingStatus, ['Inactive', 'Pullout', 'Disconnected', 'Offline', 'Restricted', 'Pullout Restricted'])) {
            $this->writeLog("  [SKIP] Status is already {$billingStatus}");
            return ['success' => false, 'reason' => "Already {$billingStatus}"];
        }

        // Get technical details for username
        $technicalDetail = $billingAccount->technicalDetails->first();
        if (!$technicalDetail || empty($technicalDetail->username)) {
            $this->writeLog("  [SKIP] PPPoE username not found");
            return ['success' => false, 'reason' => 'PPPoE username not found'];
        }

        $username = $technicalDetail->username;
        $this->writeLog("  [INFO] Username: {$username}");

        // Create transaction to ensure atomicity
        DB::beginTransaction();
        try {
            // 1. Restrict via RADIUS first
            $this->writeLog("  [RADIUS] Initiating restriction...");
            $restrictResult = $this->radiusService->restrictedUser([
                'username' => $username,
                'accountNumber' => $accountNo,
                'remarks' => 'Auto DC',
                'updatedBy' => 'System'
            ]);

            if ($restrictResult['status'] !== 'success') {
                $reason = $restrictResult['message'] ?? 'Unknown RADIUS error';
                $this->writeLog("  [CRITICAL] RADIUS failure: {$reason}. STOPPING ENTIRE PROCESS.");
                \Log::channel('radiusrelated')->error('[AUTO DC RADIUS FAILURE] Account: ' . $accountNo . ' - Reason: ' . $reason);
                DB::rollBack();
                throw new Exception("CRITICAL RADIUS FAILURE: {$reason}");
            }
            $this->writeLog("  [RADIUS] ✓ Successfully restricted");

            // 2. Apply disconnection fee if configured
            $config = BillingConfig::first();
            $dcFee = floatval($config->disconnection_fee ?? 0);

            if ($dcFee > 0) {
                $this->writeLog("  [FEE] Applying disconnection fee: ₱" . number_format($dcFee, 2));

                // Update invoice
                // Use DB::table to ensure it's part of the raw transaction and avoid model events
                $currentServiceCharge = floatval($invoice->service_charge ?? 0);
                $currentTotalAmount = floatval($invoice->total_amount ?? 0);
                $currentInvoiceBalance = floatval($invoice->invoice_balance ?? 0);
                $newServiceCharge = $currentServiceCharge + $dcFee;
                $newTotalAmount = $currentTotalAmount + $dcFee;
                $newInvoiceBalance = $currentInvoiceBalance + $dcFee;

                DB::table('invoices')
                    ->where('id', $invoice->id)
                    ->update([
                        'service_charge' => $newServiceCharge,
                        'total_amount' => $newTotalAmount,
                        'invoice_balance' => $newInvoiceBalance,
                        'updated_by' => 'System',
                        'updated_at' => Carbon::now()
                    ]);

                // Update account balance
                $newBalance = $currentBalance + $dcFee;

                // Direct update to billing_accounts to ensure it persists
                DB::table('billing_accounts')
                    ->where('id', $billingAccount->id)
                    ->update([
                        'account_balance' => $newBalance,
                        'updated_by' => 'System',
                        'updated_at' => Carbon::now()
                    ]);

                // Update the local instance for logging & SMS
                $billingAccount->account_balance = $newBalance;

                $this->writeLog("  [FEE] New Balance: ₱" . number_format($newBalance, 2));

                // Log service charge
                DB::table('service_charge_logs')->insert([
                    'account_no' => $accountNo,
                    'invoice_id' => $invoice->id,
                    'service_charge_type' => 'Disconnection Fee',
                    'service_charge' => $dcFee,
                    'date_used' => Carbon::now(),
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                    'created_by' => 'System',
                    'updated_by' => 'System'
                ]);

            } else {
                $this->writeLog("  [FEE] No disconnection fee (set to 0)");
            }

            // 3. Override billing account status to Inactive (RADIUS service sets Restricted; we want Inactive here)
            $inactiveStatusId = DB::table('billing_status')->where('status_name', 'Inactive')->value('id') ?? 4;
            DB::table('billing_accounts')
                ->where('id', $billingAccount->id)
                ->update([
                    'billing_status_id' => $inactiveStatusId,
                    'updated_by' => 'System',
                    'updated_at' => Carbon::now()
                ]);

            $this->writeLog("  [LOG] Status overridden to Inactive (ID: {$inactiveStatusId}) after RADIUS restriction");

            $this->writeLog("  [DB] STARTING DB COMMIT for Account {$accountNo}...");
            DB::commit();
            $this->writeLog("  [DB] ✓ COMMIT SUCCESSFUL");

            // Send SMS notification - AFTER commit to prevent duplicates on rollback
            if ($this->smsService && $billingAccount->customer && $billingAccount->customer->contact_number_primary) {
                $this->writeLog("  [SMS] Attempting to trigger triggerSMS function...");
                $this->triggerSMS($billingAccount, 'Disconnected');
                $this->writeLog("  [SMS] triggerSMS function finished.");
            } else {
                $this->writeLog("  [SMS] Skipping SMS (Service null or no primary contact)");
            }

            // Send Email notification - AFTER commit
            if ($this->emailQueueService && $billingAccount->customer && $billingAccount->customer->email_address) {
                $this->writeLog("  [EMAIL] Attempting to trigger triggerEmail function...");
                $this->triggerEmail($billingAccount);
                $this->writeLog("  [EMAIL] triggerEmail function finished.");
            } else {
                $this->writeLog("  [EMAIL] Skipping Email (Service null or no email address)");
            }

            $this->writeLog("  [COMPLETE] Account {$accountNo} successfully restricted and set to Inactive");

            return ['success' => true];

        } catch (Throwable $e) {
            DB::rollBack();
            $this->writeLog("  [ERROR] Transaction rolled back for Account {$accountNo}: " . $e->getMessage());
            $this->writeLog("  [TRACE] " . $e->getTraceAsString());

            if (str_contains($e->getMessage(), 'RADIUS')) {
                \Log::channel('radiusrelated')->error('[AUTO DC EXCEPTION] Account: ' . $accountNo . ' - Error: ' . $e->getMessage());
            }

            throw $e;
        }
    }

    /**
     * Normalize a fixed billing-cycle day into a valid real calendar date.
     *
     * normalizedDate = first day of target cycle month + (cycleDay - 1) days
     *
     * This lets billing-cycle day 30 exist even in months with fewer than 30 real
     * calendar days (e.g. February) by rolling the surplus into the next real month:
     *   - normalizeBillingCycleDate(2026, 2, 30) => 2026-03-02 (Feb has 28 real days)
     *   - normalizeBillingCycleDate(2028, 2, 30) => 2028-03-01 (Feb has 29 real days)
     */
    private function normalizeBillingCycleDate(int $year, int $month, int $cycleDay): Carbon
    {
        return Carbon::create($year, $month, 1, 0, 0, 0)->addDays($cycleDay - 1);
    }

    /**
     * Add a fixed number of billing-cycle days to a billing-cycle coordinate.
     *
     * Uses fixed 30-day billing-cycle arithmetic (day 31 never exists, day 30 always
     * exists) and then normalizes the result into a real calendar date. Month overflow
     * (including December → January of the next year) is handled correctly.
     *
     * @return array{date: Carbon, year: int, month: int, day: int}
     */
    private function addFixedBillingCycleDays(int $cycleYear, int $cycleMonth, int $cycleDay, int $offset): array
    {
        $totalDay = $cycleDay + $offset;
        $targetYear = $cycleYear;
        $targetMonth = $cycleMonth;

        while ($totalDay > self::BILLING_CYCLE_DAYS) {
            $totalDay -= self::BILLING_CYCLE_DAYS;
            $targetMonth++;
            if ($targetMonth > 12) {
                $targetMonth = 1;
                $targetYear++;
            }
        }

        $normalizedDate = $this->normalizeBillingCycleDate($targetYear, $targetMonth, $totalDay);

        return [
            'date' => $normalizedDate,
            'year' => $targetYear,
            'month' => $targetMonth,
            'day' => $totalDay,
        ];
    }

    /**
     * Resolve which billing-cycle days have their (billing day + $offset) fixed-cycle
     * date landing on $today.
     *
     * A given offset (<= 30) wraps at most one billing-cycle month, so a target that
     * lands in $today's month can only originate from this month's or the previous
     * month's billing cycle. Each qualifying billing-cycle day maps to exactly one
     * originating cycle.
     *
     * @return array<int, array{cycle_year:int, cycle_month:int, target: array}>
     *         Keyed by billing-cycle day.
     */
    private function resolveCycleDaysForTarget(Carbon $today, int $offset): array
    {
        $result = [];

        // A target lands at most floor(offset / 30) + 1 billing-cycle months after its
        // originating billing cycle, so we look back that many months (plus the current
        // one) to find every billing-cycle day whose target equals today. This keeps the
        // resolver correct even when config-driven offsets exceed 30 cycle days.
        $monthsBack = intdiv(max(0, $offset), self::BILLING_CYCLE_DAYS) + 1;

        for ($back = 0; $back <= $monthsBack; $back++) {
            $ref = $today->copy()->startOfMonth()->subMonths($back);
            $cycleYear = (int) $ref->year;
            $cycleMonth = (int) $ref->month;

            for ($day = 1; $day <= self::BILLING_CYCLE_DAYS; $day++) {
                $computed = $this->addFixedBillingCycleDays($cycleYear, $cycleMonth, $day, $offset);
                if ($computed['date']->isSameDay($today)) {
                    $result[$day] = [
                        'cycle_year' => $cycleYear,
                        'cycle_month' => $cycleMonth,
                        'target' => $computed,
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * Resolve the fixed billing-cycle schedule offsets from BillingConfig.
     *
     * All offsets are expressed in fixed billing-cycle days measured from the billing day:
     *   due date         = billing day + due_date_day
     *   DC / restriction = due date    + disconnection_day
     *                    = billing day + (due_date_day + disconnection_day)
     *   coverage (proration window) = disconnection_day   (days from due date to DC)
     *   grace / additional invoice  = DC + ADDITIONAL_INVOICE_OFFSET_DAYS
     *
     * Falls back to the documented constants when a value is missing. A disconnection_day
     * of 0 disables auto-DC (and therefore the grace charge), matching the config UI where
     * "0 = disabled".
     *
     * NOTE: the grace/additional-invoice offset (days after DC) has no dedicated field in
     * billing_config, so it stays the ADDITIONAL_INVOICE_OFFSET_DAYS constant.
     *
     * The DC-notice date is informational only (the service has no notice channel) and is
     * measured as due date + disconnection_notice cycle days.
     *
     * @return array{due_offset:int, dc_after_due:int, dc_offset:int, notice_after_due:int, notice_offset:int, coverage:int, grace_after_dc:int, grace_offset:int, dc_enabled:bool}
     */
    private function getScheduleOffsets(?BillingConfig $config): array
    {
        $dueOffset      = (int) ($config->due_date_day ?? 0);
        $dcAfterDue     = (int) ($config->disconnection_day ?? self::DC_OFFSET_DAYS);
        $noticeAfterDue = (int) ($config->disconnection_notice ?? 0);
        $graceAfterDc   = self::ADDITIONAL_INVOICE_OFFSET_DAYS;
        $pulloutAfterDc = (int) ($config->pullout_day ?? $config->pullout_offset ?? 30);

        return [
            'due_offset'       => $dueOffset,
            'dc_after_due'     => $dcAfterDue,
            'dc_offset'        => $dueOffset + $dcAfterDue,
            'notice_after_due' => $noticeAfterDue,
            'notice_offset'    => $dueOffset + $noticeAfterDue,
            'coverage'         => $dcAfterDue,
            'grace_after_dc'   => $graceAfterDc,
            'grace_offset'     => $dueOffset + $dcAfterDue + $graceAfterDc,
            'pullout_after_dc' => $pulloutAfterDc,
            'pullout_offset'   => $dueOffset + $dcAfterDue + $pulloutAfterDc,
            'dc_enabled'       => $dcAfterDue > 0,
        ];
    }

    /**
     * Grace-period charge: generate prorated additional invoices for accounts whose
     * additional-invoice generation date is today (7 fixed billing-cycle days after the
     * DC/restriction date). Invoked by the console command right after processAutoDisconnect().
     *
     * additionalInvoiceDate = billing day + DC_OFFSET_DAYS + ADDITIONAL_INVOICE_OFFSET_DAYS
     *                         (all fixed billing-cycle days), normalized to a real date.
     *
     * Coverage is the fixed DC_OFFSET_DAYS (10 billing-cycle days) and proration always
     * divides the monthly fee by PRORATE_DIVISOR_DAYS (30), never by the real number of
     * calendar days in the month.
     *
     * @return array{success:bool, charged:int, skipped:int, errors:array, duration:int}
     */
    public function processGracePeriodCharge(): array
    {
        $this->writeLog("");
        $this->writeLog("╔════════════════════════════════════════════════════════════════╗");
        $this->writeLog("║         STARTING GRACE PERIOD CHARGE (ADDITIONAL INVOICE)      ║");
        $this->writeLog("╚════════════════════════════════════════════════════════════════╝");
        $startTime = Carbon::now();
        $this->writeLog("Start Time: " . $startTime->format('Y-m-d H:i:s'));
        $this->writeVerbose("Runtime: PHP " . PHP_VERSION . " | SAPI: " . PHP_SAPI . " | Memory: " . $this->formatBytes(memory_get_usage(true)));

        $today = Carbon::today();
        $config = BillingConfig::first();
        $off = $this->getScheduleOffsets($config);

        $charged = 0;
        $skipped = 0;
        $errors = [];

        if (!$off['dc_enabled']) {
            $this->writeLog("[GRACE] Auto-DC is disabled (billing_config.disconnection_day = 0). No grace charges will be generated.");
            $duration = Carbon::now()->diffInSeconds($startTime);
            return ['success' => true, 'charged' => 0, 'skipped' => 0, 'errors' => [], 'duration' => $duration];
        }

        $totalOffset = $off['grace_offset'];
        $cycleMap = $this->resolveCycleDaysForTarget($today, $totalOffset);
        $billingDays = array_keys($cycleMap);

        if (empty($billingDays)) {
            $this->writeLog("[GRACE] No billing-cycle day resolves to an additional-invoice date of today. Nothing to generate.");
            $duration = Carbon::now()->diffInSeconds($startTime);
            return ['success' => true, 'charged' => 0, 'skipped' => 0, 'errors' => [], 'duration' => $duration];
        }

        $this->writeLog("[GRACE] Billing day(s) whose additional-invoice date is today (billing day + {$totalOffset} cycle days): " . implode(', ', $billingDays));

        // Only restricted/disconnected accounts (those that were auto-DC'd) qualify.
        $disconnectedStatusIds = DB::table('billing_status')
            ->whereIn('status_name', ['Inactive', 'Disconnected', 'Restricted', 'Offline', 'Pullout', 'Pullout Restricted'])
            ->pluck('id')
            ->toArray();

        $latestInvoiceIds = DB::table('invoices')
            ->select(DB::raw('MAX(id) as id'))
            ->groupBy('account_no')
            ->pluck('id');

        $invoices = Invoice::with(['billingAccount.customer', 'billingAccount.plan', 'billingAccount.billingStatus'])
            ->whereIn('id', $latestInvoiceIds)
            ->whereIn('status', ['Unpaid', 'Partial'])
            ->whereHas('billingAccount', function ($query) use ($billingDays, $disconnectedStatusIds) {
                $query->whereIn('billing_day', $billingDays);
                if (!empty($disconnectedStatusIds)) {
                    $query->whereIn('billing_status_id', $disconnectedStatusIds);
                }
            })
            ->get();

        $totalCount = $invoices->count();
        $this->writeLog("[GRACE] Found {$totalCount} restricted/disconnected account(s) due for an additional invoice today.");
        $this->writeVerbose("Grace status filter status_ids=(" . implode(', ', $disconnectedStatusIds) . ")");

        if ($totalCount === 0) {
            $duration = Carbon::now()->diffInSeconds($startTime);
            return ['success' => true, 'charged' => 0, 'skipped' => 0, 'errors' => [], 'duration' => $duration];
        }

        $counter = 0;
        foreach ($invoices as $invoice) {
            $counter++;
            $accountNo = $invoice->account_no;
            $billingAccount = $invoice->billingAccount;

            $this->writeLog("");
            $this->writeLog("[GRACE][{$counter}/{$totalCount}] Account: {$accountNo}");
            $this->writeVerbose("Grace candidate: invoice#{$invoice->id} account={$accountNo} status={$invoice->status} balance=" . number_format(floatval($billingAccount->account_balance ?? 0), 2));

            if (!$billingAccount) {
                $this->writeLog("  [SKIP] Billing account not found");
                $skipped++;
                continue;
            }

            $billingDay = (int) ($billingAccount->billing_day ?? 0);
            if (!isset($cycleMap[$billingDay])) {
                $this->writeLog("  [SKIP] Billing day {$billingDay} does not resolve to today");
                $skipped++;
                continue;
            }

            $cycle = $cycleMap[$billingDay];
            $cycleYear = $cycle['cycle_year'];
            $cycleMonth = $cycle['cycle_month'];

            // Fixed billing-cycle coordinates: billing -> due -> DC -> additional invoice.
            $normalizedBilling = $this->normalizeBillingCycleDate($cycleYear, $cycleMonth, $billingDay);
            $due = $this->addFixedBillingCycleDays($cycleYear, $cycleMonth, $billingDay, $off['due_offset']);
            $dc = $this->addFixedBillingCycleDays($cycleYear, $cycleMonth, $billingDay, $off['dc_offset']);
            $additional = $this->addFixedBillingCycleDays($dc['year'], $dc['month'], $dc['day'], $off['grace_after_dc']);

            // Dedup guard: generate at most once per account per generation date.
            $alreadyGenerated = DB::table('service_charge_logs')
                ->where('account_no', $accountNo)
                ->where('service_charge_type', self::ADDITIONAL_INVOICE_CHARGE_TYPE)
                ->whereDate('date_used', $today)
                ->exists();

            if ($alreadyGenerated) {
                $this->writeLog("  [SKIP] Additional invoice already generated today for this account");
                $skipped++;
                continue;
            }

            // Monthly fee comes from the account's plan price.
            $monthlyFee = floatval($billingAccount->plan->price ?? 0);
            if ($monthlyFee <= 0) {
                $this->writeLog("  [SKIP] Monthly fee (plan price) unavailable or zero");
                $skipped++;
                continue;
            }

            // Fixed 30-day proration over the due-date -> DC coverage window (disconnection_day).
            $coverageDays = $off['coverage'];
            $dailyRate = $monthlyFee / self::PRORATE_DIVISOR_DAYS;
            $proratedAmount = round($dailyRate * $coverageDays, 2);

            // Required logging of all computed values.
            $this->writeLog("  [CALC] Billing Day: {$billingDay}");
            $this->writeLog("  [CALC] Billing Cycle Year: {$cycleYear}");
            $this->writeLog("  [CALC] Billing Cycle Month: {$cycleMonth}");
            $this->writeLog("  [CALC] Normalized Billing Date: " . $normalizedBilling->format('Y-m-d'));
            $this->writeLog("  [CALC] Due Offset Days: {$off['due_offset']}");
            $this->writeLog("  [CALC] Normalized Due Date: " . $due['date']->format('Y-m-d'));
            $this->writeLog("  [CALC] DC Offset Days (from billing day): {$off['dc_offset']}");
            $this->writeLog("  [CALC] DC Cycle Day: {$dc['day']}");
            $this->writeLog("  [CALC] DC Cycle Month: {$dc['month']}");
            $this->writeLog("  [CALC] Normalized DC Date: " . $dc['date']->format('Y-m-d'));
            $this->writeLog("  [CALC] Additional Invoice Offset Days: {$off['grace_after_dc']}");
            $this->writeLog("  [CALC] Additional Invoice Cycle Day: {$additional['day']}");
            $this->writeLog("  [CALC] Additional Invoice Cycle Month: {$additional['month']}");
            $this->writeLog("  [CALC] Normalized Additional Invoice Date: " . $additional['date']->format('Y-m-d'));
            $this->writeLog("  [CALC] Monthly Fee: ₱" . number_format($monthlyFee, 2));
            $this->writeLog("  [CALC] Fixed Daily Rate (fee / " . self::PRORATE_DIVISOR_DAYS . "): ₱" . number_format($dailyRate, 2));
            $this->writeLog("  [CALC] Coverage Days (due→DC): {$coverageDays}");
            $this->writeLog("  [CALC] Prorated Amount: ₱" . number_format($proratedAmount, 2));

            DB::beginTransaction();
            try {
                $invoiceDate = $additional['date']->copy();
                $dueDate = $invoiceDate->copy()->addDays(self::ADDITIONAL_INVOICE_DUE_OFFSET_DAYS);

                $newInvoiceId = DB::table('invoices')->insertGetId([
                    'account_no' => $accountNo,
                    'invoice_date' => $invoiceDate,
                    'invoice_balance' => $proratedAmount,
                    'others_and_basic_charges' => 0.00,
                    'pro_rate' => $proratedAmount,
                    'pro_rate_start' => $normalizedBilling->toDateString(),
                    'service_charge' => 0.00,
                    'rebate' => 0.00,
                    'discounts' => 0.00,
                    'staggered' => 0.00,
                    'total_amount' => $proratedAmount,
                    'received_payment' => 0.00,
                    'due_date' => $dueDate,
                    'status' => 'Unpaid',
                    'payment_portal_log_ref' => null,
                    'transaction_id' => null,
                    'created_by' => 'System',
                    'updated_by' => 'System',
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);

                // Marker for dedup + audit trail (mirrors the DC fee logging pattern).
                DB::table('service_charge_logs')->insert([
                    'account_no' => $accountNo,
                    'invoice_id' => $newInvoiceId,
                    'service_charge_type' => self::ADDITIONAL_INVOICE_CHARGE_TYPE,
                    'service_charge' => $proratedAmount,
                    'date_used' => Carbon::now(),
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                    'created_by' => 'System',
                    'updated_by' => 'System',
                ]);

                $currentBalance = floatval($billingAccount->account_balance);
                $newBalance = $currentBalance + $proratedAmount;
                DB::table('billing_accounts')
                    ->where('id', $billingAccount->id)
                    ->update([
                        'account_balance' => $newBalance,
                        'updated_by' => 'System',
                        'updated_at' => Carbon::now(),
                    ]);

                DB::commit();

                $this->writeLog("  [DB] ✓ Additional invoice #{$newInvoiceId} created (₱" . number_format($proratedAmount, 2) . "). New Balance: ₱" . number_format($newBalance, 2));
                $charged++;

            } catch (Throwable $e) {
                DB::rollBack();
                $this->writeLog("  [ERROR] Failed to generate additional invoice: " . $e->getMessage());
                $this->writeLog("  [TRACE] " . $e->getTraceAsString());
                $errors[] = "Account {$accountNo} (additional invoice): " . $e->getMessage();
                $skipped++;
            }
        }

        $duration = Carbon::now()->diffInSeconds($startTime);
        $this->writeLog("");
        $this->writeLog("[GRACE] Summary: Charged {$charged}, Skipped {$skipped}, Errors " . count($errors) . ", Duration {$duration}s");
        $this->writeVerbose("Peak memory: " . $this->formatBytes(memory_get_peak_usage(true)));

        return ['success' => true, 'charged' => $charged, 'skipped' => $skipped, 'errors' => $errors, 'duration' => $duration];
    }

    /**
     * Process automatic pullout requests
     */
    public function processAutoPullout(): array
    {
        $this->writeLog("");
        $this->writeLog("╔════════════════════════════════════════════════════════════════╗");
        $this->writeLog("║         STARTING AUTO PULLOUT PROCESS                          ║");
        $this->writeLog("╚════════════════════════════════════════════════════════════════╝");
        $startTime = Carbon::now();
        $today = Carbon::today();
        $this->writeLog("Start Time: " . $startTime->format('Y-m-d H:i:s'));
        $this->writeLog("");

        try {
            $config = BillingConfig::first();

            if (!$config) {
                $this->writeLog("[ERROR] Billing configuration not found");
                throw new Exception("Billing configuration not found");
            }

            $off = $this->getScheduleOffsets($config);

            if (!$off['dc_enabled']) {
                $this->writeLog("[INFO] Auto-DC is disabled. Skipping auto pullout.");
                $duration = Carbon::now()->diffInSeconds($startTime);
                return ['success' => true, 'created' => 0, 'skipped' => 0, 'errors' => [], 'duration' => $duration];
            }

            $pulloutOffsetFromDC = $off['pullout_after_dc'];

            if ($pulloutOffsetFromDC <= 0) {
                $this->writeLog("[INFO] Auto Pullout is disabled (pullout_day = 0)");
                return [
                    'success' => true,
                    'created' => 0,
                    'skipped' => 0,
                    'errors' => [],
                    'duration' => 0
                ];
            }

            $totalOffset = $off['pullout_offset'];
            $cycleMap = $this->resolveCycleDaysForTarget($today, $totalOffset);
            $billingDays = array_keys($cycleMap);

            if (empty($billingDays)) {
                $this->writeLog("[INFO] No billing-cycle day resolves to a pullout date of today. Nothing to process.");
                $endTime = Carbon::now();
                $duration = $endTime->diffInSeconds($startTime);
                $this->writeLog("");
                $this->writeLog("╔════════════════════════════════════════════════════════════════╗");
                $this->writeLog("║         AUTO PULLOUT COMPLETE (No Actions)                     ║");
                $this->writeLog("╚════════════════════════════════════════════════════════════════╝");
                $this->writeLog("End Time: " . $endTime->format('Y-m-d H:i:s'));
                $this->writeLog("Duration: {$duration} second(s)");
                $this->writeLog("");

                return [
                    'success' => true,
                    'created' => 0,
                    'skipped' => 0,
                    'errors' => [],
                    'duration' => $duration
                ];
            }

            $this->writeLog("[CONFIG] Disconnection Day Offset: {$off['dc_after_due']} days");
            $this->writeLog("[CONFIG] Pullout Day Offset (After DC): {$pulloutOffsetFromDC} days");
            $this->writeLog("[CONFIG] Total Pullout Day Offset (From Billing Day): {$totalOffset} days");
            $this->writeLog("[SCHEDULE] Billing day(s) due for Pullout today (billing day + {$totalOffset} cycle days): " . implode(', ', $billingDays));
            $this->writeVerbose("Runtime: PHP " . PHP_VERSION . " | SAPI: " . PHP_SAPI . " | Memory: " . $this->formatBytes(memory_get_usage(true)));
            $this->writeLog("");

            // Fetch overdue invoices for pullout
            $this->writeLog("[QUERY] Searching for pullout candidates...");
            
            $latestInvoiceIds = DB::table('invoices')
                ->select(DB::raw('MAX(id) as id'))
                ->groupBy('account_no')
                ->pluck('id');

            $invoices = Invoice::with(['billingAccount.customer', 'billingAccount.technicalDetails', 'billingAccount.billingStatus'])
                ->whereIn('id', $latestInvoiceIds)
                ->whereIn('status', ['Unpaid', 'Partial'])
                ->whereHas('billingAccount', function ($query) use ($billingDays) {
                    $query->whereIn('billing_day', $billingDays);
                })
                ->get();

            $totalCount = $invoices->count();
            $this->writeLog("[RESULT] Found {$totalCount} invoice(s) due for pullout today");
            $this->writeLog("");

            $this->writeLog("[PROCESS] Starting pullout request creation...");
            $this->writeLog("─────────────────────────────────────────────────────────────────");

            $createdCount = 0;
            $skippedCount = 0;
            $errors = [];
            $counter = 0;

            foreach ($invoices as $invoice) {
                $counter++;
                $accountNo = $invoice->account_no;

                $this->writeLog("");
                $this->writeLog("[{$counter}/{$totalCount}] ══════════════════════════════════════════════");
                $this->writeLog("[ACCOUNT] {$accountNo}");
                $this->writeVerbose("Pullout candidate: invoice#{$invoice->id} account={$accountNo} status={$invoice->status} due_date=" . ($invoice->due_date ? $invoice->due_date->format('Y-m-d') : 'n/a'));

                try {
                    // Check if pullout request already exists
                    $existingPullout = ServiceOrder::where('account_no', $accountNo)
                        ->whereIn('concern', ['Pullout', 'For Pullout', 'for pullout'])
                        ->whereNotIn('support_status', ['Closed', 'Cancelled'])
                        ->exists();

                    if ($existingPullout) {
                        $this->writeLog("  [SKIP] Pullout request already exists");
                        $this->writeLog("[{$counter}/{$totalCount}] ⊘ SKIPPED");
                        $skippedCount++;
                        continue;
                    }

                    $billingAccount = $invoice->billingAccount;
                    if (!$billingAccount) {
                        $this->writeLog("  [SKIP] Billing account not found");
                        $this->writeLog("[{$counter}/{$totalCount}] ⊘ SKIPPED");
                        $skippedCount++;
                        continue;
                    }

                    // Check if account is already Pullout or Disconnected - skip entirely
                    $statusName = $billingAccount->billingStatus ? $billingAccount->billingStatus->status_name : null;
                    if (in_array($statusName, ['Pullout', 'Disconnected', 'Pullout Restricted'])) {
                        $this->writeLog("  [SKIP] Account status is already {$statusName} - no action needed");
                        $this->writeLog("[{$counter}/{$totalCount}] ⊘ SKIPPED");
                        $skippedCount++;
                        continue;
                    }

                    // Get technical details for RADIUS username
                    $technicalDetail = $billingAccount->technicalDetails->first();
                    if (!$technicalDetail || empty($technicalDetail->username)) {
                        $this->writeLog("  [SKIP] PPPoE username not found");
                        $this->writeLog("[{$counter}/{$totalCount}] ⊘ SKIPPED");
                        $skippedCount++;
                        continue;
                    }

                    $username = $technicalDetail->username;
                    $this->writeLog("  [INFO] Username: {$username}");

                    // 1. Create pullout service order
                    $this->writeLog("  [CREATE] Creating pullout service order...");
                    $this->createPulloutRequest($billingAccount, $totalOffset);
                    $this->writeLog("  [CREATE] ✓ Pullout service order created");

                    // 2. Restrict user via RADIUS (also creates disconnected_logs entry)
                    $this->writeLog("  [RADIUS] Restricting user via RADIUS...");
                    $restrictResult = $this->radiusService->restrictedUser([
                        'username' => $username,
                        'accountNumber' => $accountNo,
                        'remarks' => 'Pullout',
                        'updatedBy' => 'System'
                    ]);

                    if ($restrictResult['status'] === 'success') {
                        $this->writeLog("  [RADIUS] ✓ Successfully restricted");
                    } else {
                        $reason = $restrictResult['message'] ?? 'Unknown';
                        $this->writeLog("  [RADIUS] ✗ Restrict failed: " . $reason);
                        \Log::channel('radiusrelated')->error('[AUTO PULLOUT RADIUS FAILURE] Account: ' . $accountNo . ' - Reason: ' . $reason);
                    }

                    // 3. Update billing status to Inactive
                    $inactiveStatusId = DB::table('billing_status')->where('status_name', 'Inactive')->value('id') ?? 4;
                    DB::table('billing_accounts')
                        ->where('id', $billingAccount->id)
                        ->update([
                            'billing_status_id' => $inactiveStatusId,
                            'updated_by' => 'System',
                            'updated_at' => Carbon::now()
                        ]);
                    $this->writeLog("  [DB] ✓ Billing status updated to Inactive (ID: {$inactiveStatusId})");

                    // 4. Send SMS notification
                    if ($this->smsService && $billingAccount->customer && $billingAccount->customer->contact_number_primary) {
                        $this->writeLog("  [SMS] Sending pullout notification...");
                        $this->triggerSMS($billingAccount, 'Disconnected');
                        $this->writeLog("  [SMS] ✓ SMS sent");
                    } else {
                        $this->writeLog("  [SMS] Skipping (no SMS service or no contact number)");
                    }

                    // 5. Send Email notification
                    if ($this->emailQueueService && $billingAccount->customer && $billingAccount->customer->email_address) {
                        $this->writeLog("  [EMAIL] Sending pullout notification...");
                        $this->triggerEmail($billingAccount);
                        $this->writeLog("  [EMAIL] ✓ Email queued");
                    } else {
                        $this->writeLog("  [EMAIL] Skipping (no email service or no email address)");
                    }

                    $createdCount++;
                    $this->writeLog("  [COMPLETE] Pullout fully processed for {$accountNo}");
                    $this->writeLog("[{$counter}/{$totalCount}] ✓ SUCCESS");

                } catch (Exception $e) {
                    $this->writeLog("  [ERROR] " . $e->getMessage());
                    $this->writeLog("  [TRACE] " . $e->getTraceAsString());
                    $this->writeLog("[{$counter}/{$totalCount}] ✗ ERROR");
                    $errors[] = "Account {$accountNo}: " . $e->getMessage();
                    $skippedCount++;
                }
            }

            $endTime = Carbon::now();
            $duration = $endTime->diffInSeconds($startTime);

            $this->writeLog("");
            $this->writeLog("╔════════════════════════════════════════════════════════════════╗");
            $this->writeLog("║         AUTO PULLOUT COMPLETE                                  ║");
            $this->writeLog("╚════════════════════════════════════════════════════════════════╝");
            $this->writeLog("Summary:");
            $this->writeLog("  • Total Found: {$totalCount}");
            $this->writeLog("  • Service Orders Created: {$createdCount}");
            $this->writeLog("  • Skipped: {$skippedCount}");
            $this->writeLog("  • Errors: " . count($errors));
            $this->writeLog("  • Duration: {$duration} second(s)");
            $this->writeVerbose("Peak memory: " . $this->formatBytes(memory_get_peak_usage(true)));
            $this->writeLog("End Time: " . $endTime->format('Y-m-d H:i:s'));
            $this->writeLog("");

            if (!empty($errors)) {
                $this->writeLog("[ERROR DETAILS]");
                foreach ($errors as $error) {
                    $this->writeLog("  × {$error}");
                }
                $this->writeLog("");
            }

            return [
                'success' => true,
                'created' => $createdCount,
                'skipped' => $skippedCount,
                'errors' => $errors,
                'duration' => $duration
            ];

        } catch (Exception $e) {
            $endTime = Carbon::now();
            $duration = $endTime->diffInSeconds($startTime);

            $this->writeLog("");
            $this->writeLog("╔════════════════════════════════════════════════════════════════╗");
            $this->writeLog("║         CRITICAL ERROR                                         ║");
            $this->writeLog("╚════════════════════════════════════════════════════════════════╝");
            $this->writeLog("[CRITICAL] " . $e->getMessage());
            $this->writeLog("[TRACE] " . $e->getTraceAsString());
            $this->writeLog("End Time: " . $endTime->format('Y-m-d H:i:s'));
            $this->writeLog("Duration: {$duration} second(s)");
            $this->writeLog("");

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Create a pullout service order
     */
    private function createPulloutRequest(BillingAccount $billingAccount, int $pulloutOffset): void
    {
        $serviceOrder = new ServiceOrder();
        $serviceOrder->Timestamp = Carbon::now();
        $serviceOrder->account_no = $billingAccount->account_no;
        $serviceOrder->support_status = 'For Visit';
        $serviceOrder->concern = 'for pullout';
        $serviceOrder->concern_remarks = "System Auto Generated (Overdue {$pulloutOffset} Days)";
        $serviceOrder->requested_by = 'System';
        $serviceOrder->created_by_user = 'System';
        $serviceOrder->updated_by_user = 'System';
        $serviceOrder->save();
    }

    /**
     * Trigger SMS notification
     */
    private function triggerSMS(BillingAccount $billingAccount, string $type): void
    {
        $this->writeLog("    [DEBUG] triggerSMS: Starting for Account {$billingAccount->account_no}");
        try {
            if (!$this->smsService) {
                $this->writeLog("    [DEBUG] triggerSMS: smsService is null");
                return;
            }

            $customer = $billingAccount->customer;
            if (!$customer || empty($customer->contact_number_primary)) {
                $this->writeLog("    [DEBUG] triggerSMS: Customer or primary contact missing");
                return;
            }
            $this->writeLog("    [DEBUG] triggerSMS: Target number: {$customer->contact_number_primary}");

            $planNameRaw = $billingAccount->plan->name ?? $customer->desired_plan ?? 'N/A';
            $message = $this->buildSmsMessage(
                $type,
                $customer->full_name,
                $billingAccount->account_no,
                [
                    'balance' => number_format($billingAccount->account_balance, 2),
                    'plan_name' => $planNameRaw
                ]
            );
            $this->writeLog("    [DEBUG] triggerSMS: Message built: " . (empty($message) ? 'EMPTY' : 'OK'));

            if (!empty($message)) {
                $this->writeLog("    [DEBUG] triggerSMS: Calling send...");
                $result = $this->smsService->send([
                    'contact_no' => $customer->contact_number_primary,
                    'message' => $message
                ]);

                $success = $result['success'] ?? false;
                $this->writeLog("    [DEBUG] triggerSMS: send call completed. Success: " . ($success ? 'YES' : 'NO'));
                if (!$success) {
                    $this->writeLog("    [DEBUG] triggerSMS Error Details: " . ($result['error'] ?? 'Unknown error'));
                }
            }

        } catch (Throwable $e) {
            $this->writeLog("    [DEBUG] triggerSMS Error: " . $e->getMessage());
            $this->writeLog("    [DEBUG] triggerSMS Error Trace: " . $e->getTraceAsString());
            // Don't throw - SMS failure shouldn't stop the process
        }
    }

    /**
     * Trigger Email notification
     */
    private function triggerEmail(BillingAccount $billingAccount): void
    {
        $this->writeLog("    [DEBUG] triggerEmail: Starting for Account {$billingAccount->account_no}");
        try {
            if (!$this->emailQueueService) {
                $this->writeLog("    [DEBUG] triggerEmail: emailQueueService is null");
                return;
            }

            $customer = $billingAccount->customer;
            if (!$customer || empty($customer->email_address)) {
                $this->writeLog("    [DEBUG] triggerEmail: Customer or email address missing");
                return;
            }
            $this->writeLog("    [DEBUG] triggerEmail: Target email: {$customer->email_address}");

            // Find template
            $template = EmailTemplate::where('Template_Code', 'DISCONNECTED')->first();

            if (!$template) {
                $this->writeLog("    [DEBUG] triggerEmail: DISCONNECTED template not found");
                return;
            }

            // Use email_body as requested
            $body = $template->email_body;
            if (empty($body)) {
                $this->writeLog("    [DEBUG] triggerEmail: email_body is empty in template");
                return;
            }

            $this->writeLog("    [DEBUG] triggerEmail: Queueing email via template...");

            $customerName = preg_replace('/\s+/', ' ', trim($customer->full_name ?? ''));
            $planNameRaw = $billingAccount->plan->name ?? $customer->desired_plan ?? 'N/A';
            $planNameFormatted = str_replace('₱', 'P', $planNameRaw);

            $emailData = [
                'customer_name' => $customerName,
                'account_no' => $billingAccount->account_no,
                'amount_due' => number_format($billingAccount->account_balance, 2),
                'balance' => number_format($billingAccount->account_balance, 2),
                'plan_name' => $planNameFormatted,
                'recipient_email' => $customer->email_address,
            ];

            $emailQueued = $this->emailQueueService->queueFromTemplate('DISCONNECTED', $emailData);

            if ($emailQueued) {
                $this->writeLog("    [DEBUG] triggerEmail: Email queued successfully via template.");
            } else {
                $this->writeLog("    [DEBUG] triggerEmail: Email failed to queue via template");
            }

        } catch (Throwable $e) {
            $this->writeLog("    [DEBUG] triggerEmail Error: " . $e->getMessage());
            $this->writeLog("    [DEBUG] triggerEmail Error Trace: " . $e->getTraceAsString());
        }
    }

    /**
     * Build SMS message based on type from database templates
     */
    private function buildSmsMessage(string $type, string $name, string $accountNo, array $data): string
    {
        try {
            // Find active template for this type
            $template = SMSTemplate::where('template_type', $type)
                ->where('is_active', true)
                ->first();

            if ($template) {
                $message = $template->message_content;

                // Common variable replacements
                $customerName = preg_replace('/\s+/', ' ', trim($name));
                $planNameFormatted = str_replace('₱', 'P', $data['plan_name'] ?? '');

                $message = str_replace('{{customer_name}}', $customerName, $message);
                $message = str_replace('{{account_no}}', $accountNo, $message);
                $message = str_replace('{{plan_name}}', $planNameFormatted, $message);
                $message = str_replace('{{plan_nam}}', $planNameFormatted, $message);

                // Add balance if present in data
                if (isset($data['balance'])) {
                    $message = str_replace('{{amount_due}}', $data['balance'], $message);
                    $message = str_replace('{{balance}}', $data['balance'], $message);
                }

                return $this->replaceGlobalVariables($message);
            }

            $this->writeLog("    [DEBUG] buildSmsMessage: Template type '{$type}' not found or inactive. Falling back to default.");

            // Fallback hardcoded messages if template not found
            switch ($type) {
                case 'Disconnected':
                case 'dcTxt':
                    $balance = $data['balance'] ?? '0.00';
                    return $this->replaceGlobalVariables("DISCONNECTION NOTICE: Dear {{customer_name}}, your account ({{account_no}}) has been disconnected due to non-payment. Outstanding balance: PHP {{balance}}. Please settle immediately to restore service. Thank you!", $name, $accountNo, $balance);

                default:
                    return '';
            }
        } catch (Throwable $e) {
            $this->writeLog("    [DEBUG] buildSmsMessage Error: " . $e->getMessage());
            return '';
        }
    }

    private function replaceGlobalVariables(string $message, string $name = '', string $accountNo = '', string $balance = ''): string
    {
        $portalUrl = 'sync.atssfiber.ph';
        $brandName = \DB::table('form_ui')->value('brand_name') ?? 'Your ISP';

        $message = str_replace('{{portal_url}}', $portalUrl, $message);
        $message = str_replace('{{company_name}}', $brandName, $message);

        // Handle fallbacks if needed
        $name = preg_replace('/\s+/', ' ', trim($name));
        if ($name)
            $message = str_replace('{{customer_name}}', $name, $message);
        if ($accountNo)
            $message = str_replace('{{account_no}}', $accountNo, $message);
        if ($balance)
            $message = str_replace('{{balance}}', $balance, $message);

        return $message;
    }

    /**
     * Write to log file
     */
    private function writeLog(string $message): void
    {
        $timestamp = Carbon::now()->format('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [{$this->logName}] {$message}";

        // Define directory and file path
        $logDir = storage_path('logs/autodisconnect');
        $logFile = $logDir . '/auto_disconnect_pullout.log';

        // Check/Create Directory
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }

        // Write to custom log file
        file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);

        // Also log to Laravel default log
        Log::channel('single')->info("[{$this->logName}] {$message}");

        // Verbose CLI echo: mirror every line to stdout when running from the console
        if ($this->cliEcho && $this->isCli) {
            $this->echoCli($logMessage);
        }
    }

    /**
     * Write an extra-detailed line that is only emitted when verbose mode is on.
     * Verbose lines are tagged [VERBOSE] and follow the same file + CLI echo path.
     */
    private function writeVerbose(string $message): void
    {
        if (!$this->verbose) {
            return;
        }
        $this->writeLog("[VERBOSE] {$message}");
    }

    /**
     * Echo a single line to stdout (CLI) and flush so output streams live.
     */
    private function echoCli(string $line): void
    {
        if (defined('STDOUT')) {
            @fwrite(STDOUT, $line . PHP_EOL);
        } else {
            echo $line . PHP_EOL;
        }
        @flush();
    }

    /**
     * Human-readable byte formatting for verbose memory logging.
     */
    private function formatBytes($bytes): string
    {
        $bytes = (float) $bytes;
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Acquire lock to prevent concurrent execution using database
     */
    private function acquireLock()
    {
        try {
            // Check if lock exists and is not expired
            $existingLock = DB::table('worker_locks')
                ->where('lock_name', $this->lockName)
                ->first();

            if ($existingLock) {
                $lockedAt = \Carbon\Carbon::parse($existingLock->locked_at);
                $expiresAt = $lockedAt->addSeconds($this->lockTimeout);

                // If lock is still valid (not expired)
                if (Carbon::now()->lessThan($expiresAt)) {
                    $this->writeLog("[LOCK] Lock is held by another process. Expires at: " . $expiresAt->format('Y-m-d H:i:s'));
                    return false;
                }

                // Lock expired, clean it up
                $this->writeLog("[LOCK] Found expired lock. Cleaning up and acquiring new lock.");
                DB::table('worker_locks')
                    ->where('lock_name', $this->lockName)
                    ->delete();
            }

            // Try to acquire lock
            DB::table('worker_locks')->insert([
                'lock_name' => $this->lockName,
                'locked_at' => Carbon::now(),
                'locked_by' => gethostname() . ':' . getmypid(),
                'created_at' => Carbon::now()
            ]);

            $this->hasLock = true;
            $this->writeLog("[LOCK] Lock acquired successfully");
            return true;

        } catch (Exception $e) {
            // Unique constraint violation means another process got the lock first
            $this->writeLog("[LOCK] Failed to acquire lock: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Release lock
     */
    private function releaseLock()
    {
        if ($this->hasLock) {
            try {
                DB::table('worker_locks')
                    ->where('lock_name', $this->lockName)
                    ->delete();

                $this->writeLog("[LOCK] Lock released successfully");
                $this->hasLock = false;
            } catch (Exception $e) {
                $this->writeLog("[LOCK] Failed to release lock: " . $e->getMessage());
            }
        }
    }
}
