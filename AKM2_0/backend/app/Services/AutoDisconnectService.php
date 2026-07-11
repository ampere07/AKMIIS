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

    public function __construct(
        ManualRadiusOperationsService $radiusService,
        ?ItexmoSmsService $smsService = null,
        ?EmailQueueService $emailQueueService = null
    ) {
        $this->radiusService = $radiusService;
        $this->smsService = $smsService;
        $this->emailQueueService = $emailQueueService;
    }

    /**
     * Process automatic disconnections based on overdue invoices
     */
    public function processAutoDisconnect(): array
    {
        $this->writeLog("╔════════════════════════════════════════════════════════════════╗");
        $this->writeLog("║         STARTING AUTO DISCONNECTION PROCESS                    ║");
        $this->writeLog("╚════════════════════════════════════════════════════════════════╝");
        $startTime = Carbon::now();
        $this->writeLog("Start Time: " . $startTime->format('Y-m-d H:i:s'));
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

            $dcActualOffset = $config->disconnection_day ?? 4;
            $dcFee = $config->disconnection_fee ?? 0.00;
            $targetDate = Carbon::today()->subDays($dcActualOffset)->format('Y-m-d');
            
            $this->writeLog("[CONFIG] Disconnection Day Offset: {$dcActualOffset} days");
            $this->writeLog("[CONFIG] Disconnection Fee: ₱" . number_format($dcFee, 2));
            $this->writeLog("[CONFIG] Target Due Date: {$targetDate}");
            $this->writeLog("");

            // Fetch ONLY the latest invoice for each account and check if IT is overdue
            $this->writeLog("[QUERY] Searching for latest overdue invoices...");
            
            // 1. Get the IDs of the absolute latest invoice for every account
            $latestInvoiceIds = DB::table('invoices')
                ->select(DB::raw('MAX(id) as id'))
                ->groupBy('account_no')
                ->pluck('id');

            // 2. Fetch those specific latest invoices and filter by EXACT disconnection day
            // Logic: Due Date + Offset == Today (calculated as Due Date == Today - Offset)
            $invoices = Invoice::with(['billingAccount.customer', 'billingAccount.technicalDetails'])
                ->whereIn('id', $latestInvoiceIds)
                ->whereIn('status', ['Unpaid', 'Partial'])
                ->whereDate('due_date', $targetDate) 
                ->get();

            $totalCount = $invoices->count();
            $this->writeLog("[RESULT] Found {$totalCount} account(s) where (Due Date: {$targetDate} + Offset: {$dcActualOffset}) matches Today");
            $this->writeLog("");

            if ($totalCount === 0) {
                $this->writeLog("[INFO] No invoices to process for disconnection today.");
                $this->writeLog("[INFO] Criteria: Status IN ('Unpaid', 'Partial') AND Due Date = {$targetDate}");
                $endTime = Carbon::now();
                $duration = $endTime->diffInSeconds($startTime);
                $this->writeLog("");
                $this->writeLog("╔════════════════════════════════════════════════════════════════╗");
                $this->writeLog("║         AUTO DISCONNECTION COMPLETE (No Actions)               ║");
                $this->writeLog("╚════════════════════════════════════════════════════════════════╝");
                $this->writeLog("End Time: " . $endTime->format('Y-m-d H:i:s'));
                $this->writeLog("Duration: {$duration} second(s)");
                $this->writeLog("");
                $this->writeLog("");
                
                $this->releaseLock();
                return [
                    'success' => true,
                    'processed' => 0,
                    'skipped' => 0,
                    'errors' => [],
                    'duration' => $duration
                ];
            }

            $this->writeLog("[PROCESS] Starting disconnection process...");
            $this->writeLog("─────────────────────────────────────────────────────────────────");

            $processedCount = 0;
            $skippedCount = 0;
            $errors = [];
            $counter = 0;

            foreach ($invoices as $invoice) {
                $counter++;
                $this->writeLog("");
                $this->writeLog("[{$counter}/{$totalCount}] ══════════════════════════════════════════════");
                
                $result = $this->processDisconnection($invoice, $dcActualOffset);
                
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
     */
    private function processDisconnection(Invoice $invoice, int $dcActualOffset): array
    {
        $accountNo = $invoice->account_no;
        $this->writeLog("[ACCOUNT] {$accountNo}");

        $billingAccount = $invoice->billingAccount;

        if (!$billingAccount) {
            $this->writeLog("  [SKIP] Billing account not found");
            return ['success' => false, 'reason' => 'Billing account not found'];
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
     * Process automatic pullout requests
     */
    public function processAutoPullout(): array
    {
        $this->writeLog("");
        $this->writeLog("╔════════════════════════════════════════════════════════════════╗");
        $this->writeLog("║         STARTING AUTO PULLOUT PROCESS                          ║");
        $this->writeLog("╚════════════════════════════════════════════════════════════════╝");
        $startTime = Carbon::now();
        $this->writeLog("Start Time: " . $startTime->format('Y-m-d H:i:s'));
        $this->writeLog("");

        try {
            $config = BillingConfig::first();
            
            if (!$config) {
                $this->writeLog("[ERROR] Billing configuration not found");
                throw new Exception("Billing configuration not found");
            }

            $pulloutOffset = $config->pullout_day ?? $config->pullout_offset ?? 30;
            
            if ($pulloutOffset <= 0) {
                $this->writeLog("[INFO] Auto Pullout is disabled (pullout_day = 0)");
                return [
                    'success' => true,
                    'created' => 0,
                    'skipped' => 0,
                    'errors' => [],
                    'duration' => 0
                ];
            }

            $targetDate = Carbon::today()->subDays($pulloutOffset)->format('Y-m-d');
            
            $this->writeLog("[CONFIG] Pullout Day Offset: {$pulloutOffset} days");
            $this->writeLog("[CONFIG] Target Due Date: {$targetDate}");
            $this->writeLog("");

            // Fetch overdue invoices for pullout
            $this->writeLog("[QUERY] Searching for pullout candidates...");
            $invoices = Invoice::with(['billingAccount.customer', 'billingAccount.technicalDetails'])
                ->whereIn('status', ['Unpaid', 'Partial'])
                ->whereDate('due_date', $targetDate)
                ->get();

            $totalCount = $invoices->count();
            $this->writeLog("[RESULT] Found {$totalCount} invoice(s) with due date = {$targetDate}");
            $this->writeLog("");

            if ($totalCount === 0) {
                $this->writeLog("[INFO] No invoices to process for pullout today.");
                $this->writeLog("[INFO] Criteria: Status IN ('Unpaid', 'Partial') AND Due Date = {$targetDate}");
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
                    $this->createPulloutRequest($billingAccount, $pulloutOffset);
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
        if ($name) $message = str_replace('{{customer_name}}', $name, $message);
        if ($accountNo) $message = str_replace('{{account_no}}', $accountNo, $message);
        if ($balance) $message = str_replace('{{balance}}', $balance, $message);

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


