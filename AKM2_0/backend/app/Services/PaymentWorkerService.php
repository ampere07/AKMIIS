<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;
use App\Services\RadiusQueueService;

class PaymentWorkerService
{
    private $lockName = 'payment_worker';
    private $lockTimeout = 300; // 5 minutes max execution time
    private $hasLock = false;
    private $radiusReconnectionService;
    private $manualRadiusService;

    public function __construct()
    {
        $this->radiusReconnectionService = new RadiusReconnectionService();
        $this->manualRadiusService = new ManualRadiusOperationsService();
    }

    /**
     * Main worker function - processes queued payments
     */
    public function processPayments()
    {
        $this->workerLog('===========================================');
        $this->workerLog('Payment Worker Started: ' . now()->format('Y-m-d H:i:s'));
        $this->workerLog('===========================================');

        if (!$this->acquireLock()) {
            $this->workerLog('Another worker is already running. Exiting.');
            $this->workerLog('===========================================');
            return false;
        }

        try {
            $this->workerLog('Checking for payments to process...');

            $payments = DB::table('pending_payments')
                ->where('status', 'QUEUED')
                ->orWhere(function($query) {
                    $query->where('status', 'PENDING')
                          ->whereNotNull('callback_payload')
                          ->where(function($q) {
                              $q->where('callback_payload', 'LIKE', '%PAID%')
                                ->orWhere('callback_payload', 'LIKE', '%PAYMENT_SUCCESS%');
                          });
                })
                // FAILED rows (e.g. customer-cancelled) are only reprocessed once a
                // payment callback arrives. FAILED rows with no payload are ignored.
                // Restricted to paid-looking payloads so genuinely-failed webhook
                // payloads (status FAILED/PAYMENT_FAILED) don't get reprocessed every run.
                ->orWhere(function($query) {
                    $query->where('status', 'FAILED')
                          ->whereNotNull('callback_payload')
                          ->where('callback_payload', '!=', '')
                          ->where(function($q) {
                              $q->where('callback_payload', 'LIKE', '%PAID%')
                                ->orWhere('callback_payload', 'LIKE', '%PAYMENT_SUCCESS%');
                          });
                })
                ->limit(20)
                ->get();

            if ($payments->isEmpty()) {
                $this->workerLog('No payments to process');
                $this->workerLog('===========================================');
                $this->workerLog('Payment Worker Completed: ' . now()->format('Y-m-d H:i:s'));
                $this->workerLog('===========================================');
                return true;
            }

            $this->workerLog("Found {$payments->count()} transactions to process");

            foreach ($payments as $payment) {
                $this->processPayment($payment);
            }

            $this->workerLog('===========================================');
            $this->workerLog('Payment Worker Completed: ' . now()->format('Y-m-d H:i:s'));
            $this->workerLog('===========================================');
            return true;

        } catch (Exception $e) {
            $this->workerLog('Worker Error: ' . $e->getMessage());
            Log::error('Payment Worker Exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->workerLog('===========================================');
            return false;
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Process individual payment
     */
    private function processPayment($payment)
    {
        DB::beginTransaction();
        
        try {
            $id = $payment->id;
            $ref = $payment->reference_no;
            $accountNo = $payment->account_no;
            $amount = floatval($payment->amount);
            $rawPayload = $payment->callback_payload;

            // Validate payment status from callback
            if ($rawPayload) {
                $json = json_decode($rawPayload, true);
                $gwStatus = strtoupper($json['status'] ?? '');
                
                $isLegitPaid = in_array($gwStatus, ['PAID', 'COMPLETED', 'SETTLED', 'PAYMENT_SUCCESS']);

                if (!$isLegitPaid) {
                    $this->workerLog("AUDIT FAIL: Ref $ref has payload but status is $gwStatus. Marking FAILED.");
                    
                    DB::table('pending_payments')
                        ->where('id', $id)
                        ->update(['status' => 'FAILED', 'updated_at' => now()]);
                    
                    DB::commit();
                    return;
                }
            }

            // Lock record for processing
            DB::table('pending_payments')
                ->where('id', $id)
                ->update([
                    'status' => 'PROCESSING',
                    'last_attempt_at' => now(),
                    'updated_at' => now()
                ]);

            // Get account information
            $account = DB::table('billing_accounts')
                ->join('customers', 'billing_accounts.customer_id', '=', 'customers.id')
                ->where('billing_accounts.account_no', $accountNo)
                ->select(
                    'billing_accounts.id as account_id',
                    'billing_accounts.account_no',
                    'billing_accounts.account_balance',
                    DB::raw("CONCAT(customers.first_name, ' ', IFNULL(customers.middle_initial, ''), ' ', customers.last_name) as full_name"),
                    'customers.contact_number_primary',
                    'customers.email_address',
                    'customers.desired_plan'
                )
                ->first();

            if (!$account) {
                $this->workerLog("ERROR: Account not found for payment $ref");
                DB::table('pending_payments')
                    ->where('id', $id)
                    ->update(['status' => 'FAILED', 'updated_at' => now()]);
                DB::commit();
                return;
            }

            // Update billing - distribute payment to invoices
            $result = $this->updateBilling($account, $amount, $ref);

            if ($result['success']) {
                // Mark payment as PAID
                DB::table('pending_payments')
                    ->where('id', $id)
                    ->update([
                        'status' => 'PAID',
                        'updated_at' => now()
                    ]);

                // Parse callback payload for payment details
                $json = json_decode($rawPayload, true);
                $checkoutID = $json['id'] ?? $payment->payment_id ?? 'N/A';
                $paymentChannel = $json['payment_channel'] ?? $json['bank_code'] ?? null;
                $ewalletType = $json['ewallet_type'] ?? null;
                $status = $json['status'] ?? 'PAID';
                $type = $json['type'] ?? null;

                // Insert into payment_portal_logs
                DB::table('payment_portal_logs')->insert([
                    'reference_no' => $ref,
                    'account_id' => $account->account_id,
                    'total_amount' => $amount,
                    'account_balance_before' => $account->account_balance,
                    'date_time' => now(),
                    'checkout_id' => $checkoutID,
                    'status' => $status,
                    'transaction_status' => 'PAID',
                    'ewallet_type' => $ewalletType,
                    'payment_channel' => $paymentChannel,
                    'type' => $type,
                    'payment_url' => $payment->payment_url ?? null,
                    'json_payload' => $payment->json_payload ?? null,
                    'callback_payload' => $rawPayload,
                    'updated_at' => now()
                ]);

                $this->workerLog("Success: Logged Ref $ref - Amount: ₱" . number_format($amount, 2) . " - {$result['distribution_summary']}");

                // Read reconnect conditions before committing (still within transaction for consistency)
                $latestBillingAccount = DB::table('billing_accounts')
                    ->where('account_no', $accountNo)
                    ->select('account_balance', 'billing_status_id')
                    ->first();

                $currentBalance  = floatval($latestBillingAccount->account_balance ?? 0);
                $currentStatusId = intval($latestBillingAccount->billing_status_id ?? 1);
                $needsReconnect  = ($currentStatusId !== 1 && $currentBalance <= 0);

                // Commit billing FIRST — payment is real regardless of what RADIUS does
                DB::commit();

                // Send Approval Notifications (after commit so they're never rolled back)
                $this->sendApprovalSms($account, $result['invoices_paid'] ?? [], $amount, $ref);
                $this->sendApprovalEmail($account, $result['invoices_paid'] ?? [], $amount, $ref);

                // Attempt reconnection AFTER commit — RADIUS failure must never roll back a real payment
                if ($needsReconnect) {
                    $this->workerLog("Reconnect triggered for $ref — Status ID: {$currentStatusId}, Balance: ₱" . number_format($currentBalance, 2));
                    $reconnectStatus = $this->attemptReconnect($account);

                    // Update reconnect_status outside the main transaction (standalone)
                    DB::table('pending_payments')
                        ->where('id', $id)
                        ->update(['reconnect_status' => $reconnectStatus]);

                    $this->workerLog("Reconnect attempt for $ref: $reconnectStatus");


                }
                
            } else {
                // Billing update failed
                DB::table('pending_payments')
                    ->where('id', $id)
                    ->update(['status' => 'API_RETRY', 'updated_at' => now()]);
                
                $this->workerLog("Billing update failed for Ref $ref: " . $result['message']);
                DB::rollBack();
            }

        } catch (Exception $e) {
            DB::rollBack();
            $this->workerLog("Failed to process payment {$payment->reference_no}: {$e->getMessage()}");
            
            DB::table('pending_payments')
                ->where('id', $payment->id)
                ->update(['status' => 'API_RETRY', 'updated_at' => now()]);
        }
    }

    private function replaceGlobalVariables(string $message): string
    {
        $portalUrl = 'sync.atssfiber.ph';
        $brandName = DB::table('form_ui')->value('brand_name') ?? 'Your ISP';

        $message = str_replace('{{portal_url}}', $portalUrl, $message);
        $message = str_replace('{{company_name}}', $brandName, $message);

        return $message;
    }

    /**
     * Update billing - distribute payment to unpaid invoices
     */
    private function updateBilling($account, $paymentAmount, $referenceNo)
    {
        try {
            $accountNo = $account->account_no;
            $remainingAmount = $paymentAmount;
            $distributionLog = [];
            $paidInvoices = [];

            // Get unpaid invoices ordered by invoice_date (oldest first)
            $unpaidInvoices = DB::table('invoices')
                ->where('account_no', $accountNo)
                ->where('status', '!=', 'Paid')
                ->orderBy('invoice_date', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            if ($unpaidInvoices->isEmpty()) {
                // No unpaid invoices - apply as credit/advance payment
                $newBalance = floatval($account->account_balance) - $paymentAmount;
                
                DB::table('billing_accounts')
                    ->where('account_no', $accountNo)
                    ->update([
                        'account_balance' => $newBalance,
                        'updated_at' => now()
                    ]);

                return [
                    'success' => true,
                    'distribution_summary' => "Applied as credit (No unpaid invoices)",
                    'distributed_amount' => $paymentAmount,
                    'remaining_amount' => 0,
                    'invoices_paid' => []
                ];
            }

            // Distribute payment to invoices
            foreach ($unpaidInvoices as $invoice) {
                if ($remainingAmount <= 0) {
                    break;
                }

                $invoiceId = $invoice->id;
                $invoiceBalance = floatval($invoice->total_amount) - floatval($invoice->received_payment);
                
                if ($invoiceBalance <= 0) {
                    continue; // Skip already paid invoices
                }

                $amountToApply = min($remainingAmount, $invoiceBalance);
                $newReceivedPayment = floatval($invoice->received_payment) + $amountToApply;
                $newInvoiceBalance = floatval($invoice->total_amount) - $newReceivedPayment;

                // Determine new status
                $newStatus = 'Unpaid';
                if ($newInvoiceBalance <= 0.01) { // Fully paid (accounting for floating point)
                    $newStatus = 'Paid';
                    $paidInvoices[] = [
                        'invoice_id' => $invoiceId,
                        'amount_paid' => $amountToApply
                    ];
                } elseif ($newReceivedPayment > 0) { // Partially paid
                    $newStatus = 'Partial';
                }

                // Update invoice
                DB::table('invoices')
                    ->where('id', $invoiceId)
                    ->update([
                        'received_payment' => $newReceivedPayment,
                        'status' => $newStatus,
                        'transaction_id' => $referenceNo,
                        'updated_at' => now(),
                        'updated_by' => 'Payment Worker'
                    ]);

                $distributionLog[] = "Invoice #{$invoiceId}: ₱" . number_format($amountToApply, 2) . " ({$newStatus})";
                $remainingAmount -= $amountToApply;

                $this->workerLog("Distributed ₱" . number_format($amountToApply, 2) . " to Invoice #{$invoiceId} - Status: {$newStatus}");
            }

            // Update account balance
            $newAccountBalance = floatval($account->account_balance) - $paymentAmount;
            
            DB::table('billing_accounts')
                ->where('account_no', $accountNo)
                ->update([
                    'account_balance' => $newAccountBalance,
                    'updated_at' => now()
                ]);

            $distributionSummary = implode(', ', $distributionLog);
            
            if ($remainingAmount > 0.01) {
                $distributionSummary .= " | Credit: ₱" . number_format($remainingAmount, 2);
            }

            return [
                'success' => true,
                'distribution_summary' => $distributionSummary,
                'distributed_amount' => $paymentAmount - $remainingAmount,
                'remaining_amount' => $remainingAmount,
                'invoices_paid' => $paidInvoices
            ];

        } catch (Exception $e) {
            Log::error('Billing update failed', [
                'account_no' => $account->account_no,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Attempt to reconnect user account
     * Enhanced version: Checks session_status from online_status table
     */
    /**
     * Attempt to reconnect user account
     * Matches logic in TransactionController::approve
     */
    private function attemptReconnect($account)
    {
        try {
            // Reload billing account to get latest balance and status
            $billingAccount = DB::table('billing_accounts')->where('id', $account->account_id)->first();
            $accountNo = $billingAccount->account_no;

            $this->workerLog("[RECONNECT CHECK] Starting for account: {$accountNo}");
            
            // Step 1: Check if balance qualifies (0 or negative)
            $balance = floatval($billingAccount->account_balance ?? 0);
            if ($balance > 0) {
                $this->workerLog("[RECONNECT SKIP] Balance is positive: ₱{$balance}");
                return 'balance_positive';
            }

            // Step 2: Check if already active (we'll still proceed to reconnect if needed)
            $isAlreadyActive = ($billingAccount->billing_status_id == 1);

            // Step 3: Get account details with PPPoE username and plan
            $accountDetails = DB::table('billing_accounts')
                ->leftJoin('customers', 'billing_accounts.customer_id', '=', 'customers.id')
                ->leftJoin('technical_details', 'billing_accounts.id', '=', 'technical_details.account_id')
                ->where('billing_accounts.id', $billingAccount->id)
                ->where('billing_accounts.id', $billingAccount->id)
                ->select('technical_details.username as pppoe_username', 'customers.desired_plan', 'customers.email_address')
                ->first();

            $username = $accountDetails->pppoe_username ?? null;
            $plan = $accountDetails->desired_plan ?? null;
            $emailAddress = $accountDetails->email_address ?? null;

            if (empty($username)) {
                $this->workerLog("[RECONNECT SKIP] No PPPoE username found in technical_details for account: {$accountNo}");
                return 'no_username';
            }

            if (empty($plan)) {
                $this->workerLog("[RECONNECT SKIP] No plan found for account: {$accountNo}");
                return 'no_plan';
            }

            $this->workerLog("[RECONNECT PROCEED] Conditions met - Proceeding with reconnection. Current Status Active: " . ($isAlreadyActive ? 'Yes' : 'No') . ", Balance: ₱{$balance}");

            // Step 5: Prepare parameters for ManualRadiusOperationsService
            $params = [
                'accountNumber' => $accountNo,
                'username' => $username,
                'plan' => $plan,
                'updatedBy' => 'Payment Worker Auto-Reconnect',
                'remarks' => 'Payment Worker Auto-Reconnect'
            ];

            // Step 6: Call ManualRadiusOperationsService reconnectUser
            $this->workerLog("[RECONNECT EXECUTE] Calling ManualRadiusOperationsService for {$username}");
            
            $radiusSuccess = false;
            $lastRadiusError = '';
            $result = [];
            
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                try {
                    $result = $this->manualRadiusService->reconnectUser($params);
                    if (($result['status'] ?? '') === 'success') {
                        $radiusSuccess = true;
                        $this->workerLog("[RECONNECT EXECUTE] Success on attempt {$attempt}");
                        break;
                    }
                    $lastRadiusError = $result['message'] ?? 'Operation returned failure';
                    $this->workerLog("[RECONNECT EXECUTE] Attempt {$attempt}/3 failed: {$lastRadiusError}");
                } catch (Exception $radEx) {
                    $lastRadiusError = $radEx->getMessage();
                    $this->workerLog("[RECONNECT EXECUTE] Attempt {$attempt}/3 exception: {$lastRadiusError}");
                }
                if ($attempt < 3) sleep(2);
            }

            if (!$radiusSuccess) {
                $this->workerLog("[RECONNECT EXECUTE] All 3 attempts failed. Queuing for retry.");
                RadiusQueueService::queue([
                    'source_type' => 'payment_worker',
                    'source_id' => $billingAccount->id,
                    'account_no' => $accountNo,
                    'operation' => 'reconnect_user',
                    'params' => $params,
                    'last_error' => $lastRadiusError,
                    'created_by' => 'Payment Worker',
                ]);
            }

            if (true) {
                $this->workerLog("[RECONNECT SUCCESS] Reconnection queued or completed successfully");

                // Step 7: Update billing_status_id to 1 (Active) if not already 1
                if (!$isAlreadyActive) {
                    DB::table('billing_accounts')
                        ->where('id', $billingAccount->id)
                        ->update([
                            'billing_status_id' => 1,
                            'updated_at' => now(),
                            'updated_by' => 'Payment Worker'
                        ]);
                    $this->workerLog("[RECONNECT DB] Updated billing_status_id to 1 for Account: {$accountNo}");
                } else {
                    $this->workerLog("[RECONNECT DB SKIP] Account already 1, skipping status update");
                }

                // Send SMS Notification
                try {
                    // Fetch SMS template
                    $smsTemplate = DB::table('sms_templates')
                        ->where('template_type', 'Reconnect')
                        ->where('is_active', 1)
                        ->first();

                    if ($smsTemplate) {
                        // Get Customer Name and Contact Number
                        $customerInfo = DB::table('billing_accounts')
                            ->join('customers', 'billing_accounts.customer_id', '=', 'customers.id')
                            ->where('billing_accounts.account_no', $accountNo)
                            ->select(
                                'customers.contact_number_primary',
                                DB::raw("CONCAT(customers.first_name, ' ', IFNULL(customers.middle_initial, ''), ' ', customers.last_name) as full_name")
                            )
                            ->first();

                        if ($customerInfo && !empty($customerInfo->contact_number_primary)) {
                            // Replace variables
                            $message = $smsTemplate->message_content;
                            $customerName = preg_replace('/\s+/', ' ', trim($customerInfo->full_name));
                            $planNameFormatted = str_replace('₱', 'P', $plan ?? '');

                            $message = str_replace('{{customer_name}}', $customerName, $message);
                            $message = str_replace('{{account_no}}', $accountNo, $message);
                            $message = str_replace('{{plan_name}}', $planNameFormatted, $message);
                            $message = str_replace('{{plan_nam}}', $planNameFormatted, $message);

                            // Send SMS
                            $smsService = new \App\Services\ItexmoSmsService();
                            $smsResult = $smsService->send([
                                'contact_no' => $customerInfo->contact_number_primary,
                                'message' => $message
                            ]);

                            if ($smsResult['success']) {
                                $this->workerLog("[RECONNECT SMS] SMS sent to " . $customerInfo->contact_number_primary);
                            } else {
                                $this->workerLog("[RECONNECT SMS FAILED] " . ($smsResult['error'] ?? 'Unknown error'));
                            }
                        } else {
                            $this->workerLog("[RECONNECT SMS SKIP] No contact number found for account " . $accountNo);
                        }
                    } else {
                        $this->workerLog("[RECONNECT SMS SKIP] No active Reconnect SMS template found");
                    }
                } catch (Exception $e) {
                    $this->workerLog("[RECONNECT SMS EXCEPTION] " . $e->getMessage());
                }

                // Send Email Notification
                try {
                    $emailTemplate = \App\Models\EmailTemplate::where('Template_Code', 'RECONNECT')->first();

                    if ($emailTemplate && $emailAddress && !empty($emailAddress)) {
                        $emailService = app(\App\Services\EmailQueueService::class);

                        $customerInfo = DB::table('billing_accounts')
                            ->join('customers', 'billing_accounts.customer_id', '=', 'customers.id')
                            ->where('billing_accounts.account_no', $accountNo)
                            ->select(
                                'customers.email_address',
                                'customers.desired_plan as plan_name',
                                DB::raw("CONCAT(customers.first_name, ' ', IFNULL(customers.middle_initial, ''), ' ', customers.last_name) as full_name")
                            )
                            ->first();

                        if ($customerInfo) {
                            $customerName = preg_replace('/\s+/', ' ', trim($customerInfo->full_name));
                            $planNameFormatted = str_replace('₱', 'P', $customerInfo->plan_name ?? '');

                            $emailData = [
                                'customer_name' => $customerName,
                                'account_no' => $accountNo,
                                'plan_name' => $planNameFormatted,
                                'recipient_email' => $customerInfo->email_address,
                            ];
                            $emailService->queueFromTemplate('RECONNECT', $emailData);
                            $this->workerLog("[RECONNECT EMAIL] Email queued for: " . $customerInfo->email_address);
                        }
                    }
                } catch (Exception $e) {
                    $this->workerLog("[RECONNECT EMAIL EXCEPTION] " . $e->getMessage());
                }

                $this->failPulloutServiceOrders($accountNo);

                return $radiusSuccess ? 'success' : 'queued';
            }
            
        } catch (Exception $e) {
            $this->workerLog("[RECONNECT EXCEPTION] Failed for {$account->account_no}: {$e->getMessage()}");
            $this->workerLog("[RECONNECT EXCEPTION] Trace: {$e->getTraceAsString()}");
            \Log::channel('radiusrelated')->error('[PAYMENT WORKER RECONNECT EXCEPTION] Account: ' . ($account->account_no ?? 'Unknown') . ' - Error: ' . $e->getMessage());
            return 'exception';
        }
    }

    /**
     * Mark open Pullout service orders as Failed when an account is reconnected.
     * Only fires when balance reaches 0 and billing_status_id is set to 1 via reconnectUser.
     */
    private function failPulloutServiceOrders(string $accountNo): void
    {
        $this->soFailLog('[RUNNING] Starting pullout service order check for account: ' . $accountNo);
        try {
            // Balance guard: only auto-fail when the account is fully paid (balance <= 0)
            $balance = floatval(DB::table('billing_accounts')
                ->where('account_no', $accountNo)
                ->value('account_balance') ?? 0);

            if ($balance > 0) {
                $this->soFailLog('[SKIP] Account balance still positive (₱' . number_format($balance, 2) . ') - skipping pullout fail for account: ' . $accountNo);
                $this->soFailLog('[DONE] Completed pullout service order check for account: ' . $accountNo);
                return;
            }

            $order = DB::table('service_orders')
                ->where('account_no', $accountNo)
                ->whereIn(DB::raw('LOWER(TRIM(concern))'), ['pullout', 'for pullout'])
                ->whereNotIn('support_status', ['Failed', 'Completed'])
                ->whereNotIn('visit_status', ['Failed', 'Completed'])
                ->orderBy('created_at', 'desc')
                ->first();

            if (!$order) {
                $this->soFailLog('[SKIP] No open Pullout service orders found for account: ' . $accountNo);
                $this->soFailLog('[DONE] Completed pullout service order check for account: ' . $accountNo);
                return;
            }

            $this->soFailLog('[FOUND] Pullout service order matched - ID: ' . $order->id . ', Account: ' . $accountNo);

            DB::table('service_orders')
                ->where('id', $order->id)
                ->update([
                    'support_status'  => 'Failed',
                    'visit_status'    => 'Failed',
                    'support_remarks' => 'auto failed due to client reconnected',
                    'updated_by_user' => 'System',
                    'updated_at'      => now(),
                ]);

            $this->soFailLog('[SUCCESS] Pullout service order marked Failed - ID: ' . $order->id . ', Account: ' . $accountNo);
            $this->soFailLog('[DONE] Completed pullout service order check for account: ' . $accountNo);
        } catch (Exception $e) {
            $this->soFailLog('[FAILED] Account: ' . $accountNo . ' - Error: ' . $e->getMessage());
        }
    }

    /**
     * Write a timestamped line to the dedicated sofailingauto.log file, mirror it to
     * the default Laravel log and the worker log. Wrapped in try/catch so that logging
     * can never break the calling flow.
     */
    private function soFailLog(string $message): void
    {
        try {
            $timestamp = now()->format('Y-m-d H:i:s');
            $line = "[{$timestamp}] [SO Failing Auto] {$message}";

            file_put_contents(storage_path('logs/sofailingauto.log'), $line . PHP_EOL, FILE_APPEND);

            // Mirror to the default Laravel log and the existing worker log.
            Log::channel('single')->info('[SO Failing Auto] ' . $message);
            $this->workerLog('[SO Failing Auto] ' . $message);
        } catch (\Throwable $e) {
            // Logging must never break the flow.
        }
    }

    /**
     * Send Transaction Approval SMS notification
     */
    private function sendApprovalSms($account, $invoicesPaid, $totalPaidAmount, $referenceNo = null)
    {
        try {
            if ($account && !empty($account->contact_number_primary)) {
                $paymentLogDate = date('Y-m-d');
                $finalAmount = $totalPaidAmount;
                
                if ($referenceNo) {
                    $logEntry = DB::table('payment_portal_logs')->where('reference_no', $referenceNo)->first();
                    if ($logEntry) {
                        $finalAmount = $logEntry->total_amount;
                        $paymentLogDate = date('Y-m-d', strtotime($logEntry->date_time));
                    }
                }

                $paidTemplate = DB::table('sms_templates')
                    ->where('template_type', 'Paid')
                    ->where('is_active', 1)
                    ->first();
                    
                if ($paidTemplate) {
                    $smsService = new \App\Services\ItexmoSmsService();
                    
                    // Consolidate invoice IDs or use N/A if none
                    $invoiceIds = !empty($invoicesPaid) 
                        ? collect($invoicesPaid)->pluck('invoice_id')->unique()->implode(', ')
                        : 'N/A';
                    
                    $message = $paidTemplate->message_content;
                    
                    // Replace variables
                    $customerName = preg_replace('/\s+/', ' ', trim($account->full_name));
                    $planNameFormatted = str_replace('₱', 'P', $account->desired_plan ?? 'N/A');

                    $message = str_replace('{{customer_name}}', $customerName, $message);
                    $message = str_replace('{{account_no}}', $account->account_no, $message);
                    $message = str_replace('{{plan_name}}', $planNameFormatted, $message);
                    $message = str_replace('{{plan_nam}}', $planNameFormatted, $message);
                    $message = str_replace('{{invoice_id}}', $invoiceIds, $message);
                    
                    // Support multiple variations of placeholders
                    $formattedAmount = number_format($finalAmount, 2);
                    
                    $message = str_replace('{{amount_paid}}', $formattedAmount, $message);
                    $message = str_replace('{{amount}}', $formattedAmount, $message);
                    $message = str_replace('{{date}}', $paymentLogDate, $message);
                    $message = str_replace('{{payment_date}}', $paymentLogDate, $message);
                    
                    $message = $this->replaceGlobalVariables($message);
                    
                    $result = $smsService->send([
                        'contact_no' => $account->contact_number_primary,
                        'message' => $message
                    ]);
                    
                    if ($result['success']) {
                        $this->workerLog("Approval SMS sent to {$account->contact_number_primary}");
                    } else {
                        $this->workerLog("Approval SMS Failed: " . ($result['error'] ?? 'Unknown error'));
                    }
                }
            }
        } catch (Exception $e) {
            $this->workerLog("Approval SMS Exception: " . $e->getMessage());
        }
    }

    /**
     * Send Transaction Approval Email notification
     */
    private function sendApprovalEmail($account, $invoicesPaid, $totalPaidAmount, $referenceNo = null)
    {
        try {
            if ($account && !empty($account->email_address)) {
                $emailService = app(\App\Services\EmailQueueService::class);
                
                $paymentLogDate = date('Y-m-d');
                $finalAmount = $totalPaidAmount;
                
                if ($referenceNo) {
                    $logEntry = DB::table('payment_portal_logs')->where('reference_no', $referenceNo)->first();
                    if ($logEntry) {
                        $finalAmount = $logEntry->total_amount;
                        $paymentLogDate = date('Y-m-d', strtotime($logEntry->date_time));
                    }
                }
                
                // Consolidate invoice IDs or use N/A
                $invoiceIds = !empty($invoicesPaid) 
                    ? collect($invoicesPaid)->pluck('invoice_id')->unique()->implode(', ')
                    : 'N/A';
                    
                $brandName = DB::table('form_ui')->value('brand_name') ?? 'Your ISP';
                
                $customerName = preg_replace('/\s+/', ' ', trim($account->full_name));
                $planNameFormatted = str_replace('₱', 'P', $account->desired_plan ?? 'N/A');

                $formattedAmount = number_format($finalAmount, 2);

                $emailData = [
                    'Amount' => $formattedAmount,
                    'amount' => $formattedAmount,
                    'amount_paid' => $formattedAmount,
                    'Company_Name' => $brandName,
                    'Account_No' => $account->account_no,
                    'account_no' => $account->account_no,
                    'Date' => $paymentLogDate,
                    'date' => $paymentLogDate,
                    'payment_date' => $paymentLogDate,
                    'Full_Name' => $customerName,
                    'Plan' => $planNameFormatted,
                    'invoice_ids' => $invoiceIds,
                    'recipient_email' => $account->email_address,
                ];

                $emailService->queueFromTemplate('PAID', $emailData);
                
                $this->workerLog("Approval Email queued via template PAID to {$account->email_address}");
            }
        } catch (Exception $e) {
            $this->workerLog("Approval Email Exception: " . $e->getMessage());
        }
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
                if (now()->lessThan($expiresAt)) {
                    $this->workerLog('Lock is held by another process. Expires at: ' . $expiresAt->format('Y-m-d H:i:s'));
                    return false;
                }

                // Lock expired, clean it up
                $this->workerLog('Found expired lock. Cleaning up and acquiring new lock.');
                DB::table('worker_locks')
                    ->where('lock_name', $this->lockName)
                    ->delete();
            }

            // Try to acquire lock
            DB::table('worker_locks')->insert([
                'lock_name' => $this->lockName,
                'locked_at' => now(),
                'locked_by' => gethostname() . ':' . getmypid(),
                'created_at' => now()
            ]);

            $this->hasLock = true;
            $this->workerLog('Lock acquired successfully');
            return true;

        } catch (Exception $e) {
            // Unique constraint violation means another process got the lock first
            $this->workerLog('Failed to acquire lock: ' . $e->getMessage());
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
                
                $this->workerLog('Lock released successfully');
                $this->hasLock = false;
            } catch (Exception $e) {
                $this->workerLog('Failed to release lock: ' . $e->getMessage());
            }
        }
    }

    /**
     * Write log message
     */
    private function workerLog($message)
    {
        $timestamp = now()->format('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [Payment Worker] {$message}";
        
        // Log to custom paymentworker.log file
        $logPath = storage_path('logs/paymentworker.log');
        file_put_contents($logPath, $logMessage . PHP_EOL, FILE_APPEND);
        
        // Also log to Laravel default log
        Log::channel('single')->info('[Payment Worker] ' . $message);
    }

    /**
     * Get worker statistics
     */
    public function getStatistics()
    {
        return [
            'pending' => DB::table('pending_payments')
                ->where('status', 'PENDING')
                ->count(),
            'queued' => DB::table('pending_payments')
                ->where('status', 'QUEUED')
                ->count(),
            'processing' => DB::table('pending_payments')
                ->where('status', 'PROCESSING')
                ->count(),
            'paid' => DB::table('pending_payments')
                ->where('status', 'PAID')
                ->whereDate('updated_at', today())
                ->count(),
            'failed' => DB::table('pending_payments')
                ->where('status', 'FAILED')
                ->whereDate('updated_at', today())
                ->count(),
            'api_retry' => DB::table('pending_payments')
                ->where('status', 'API_RETRY')
                ->count(),
        ];
    }

    /**
     * Retry failed payments
     */
    public function retryFailedPayments()
    {
        $retryPayments = DB::table('pending_payments')
            ->where('status', 'API_RETRY')
            ->limit(10)
            ->get();

        foreach ($retryPayments as $payment) {
            DB::table('pending_payments')
                ->where('id', $payment->id)
                ->update(['status' => 'QUEUED', 'updated_at' => now()]);
        }

        return $retryPayments->count();
    }

}
