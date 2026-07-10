<?php

namespace App\Services;

use App\Models\BillingAccount;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\StatementOfAccount;
use App\Models\AppPlan;
use App\Models\Discount;
use App\Models\StaggeredInstallation;
use App\Models\AdvancedPayment;
use App\Models\MassRebate;
use App\Models\RebateUsage;
use App\Models\Barangay;
use App\Models\BillingConfig;
use App\Models\Overdue;
use App\Models\DCNotice;
use App\Models\ReconnectionLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class EnhancedBillingGenerationServiceWithNotifications
{
    protected BillingNotificationService $notificationService;

    /**
     * Reconnection date of the pro-rate matched during the most recent
     * calculateReconnectionProRate() call (null when no pro-rate applied).
     * Used to populate pro_rate_start so the PDF coverage period reflects
     * the actual reconnection day. Reset on every calculation.
     */
    protected ?Carbon $lastReconnectionProRateStart = null;

    protected const VAT_RATE = 0.12;
    protected const DAYS_IN_MONTH = 30;
    protected const DAYS_UNTIL_DUE = 7;
    protected const DAYS_UNTIL_DC_NOTICE = 4;
    protected const END_OF_MONTH_BILLING = 0;

    public function __construct(BillingNotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }
    
    protected function log($level, $message, $context = [])
    {
        Log::channel('billing')->{$level}($message, $context);
    }

    public function generateSOAForBillingDay(int $billingDay, Carbon $generationDate, int $userId): array
    {
        $results = [
            'success' => 0,
            'failed' => 0,
            'errors' => [],
            'statements' => [],
            'notifications' => []
        ];

        try {
            $accounts = $this->getActiveAccountsForBillingDay($billingDay, $generationDate);

            foreach ($accounts as $account) {
                try {
                    $statement = $this->createEnhancedStatement($account, $generationDate, $userId);
                    $results['statements'][] = $statement;
                    $results['success']++;
                    
                    $notificationResult = $this->queueNotification($account, null, $statement);
                    $results['notifications'][] = $notificationResult;
                    
                } catch (\Exception $e) {
                    $results['failed']++;
                    $results['errors'][] = [
                        'account_id' => $account->id,
                        'account_no' => $account->account_no,
                        'error' => $e->getMessage()
                    ];
                    $this->log('error', "Failed to generate SOA for account {$account->account_no}: " . $e->getMessage());
                }
            }

            return $results;
        } catch (\Exception $e) {
            $this->log('error', "Error in generateSOAForBillingDay: " . $e->getMessage());
            throw $e;
        }
    }

    public function generateInvoicesForBillingDay(int $billingDay, Carbon $generationDate, int $userId): array
    {
        $results = [
            'success' => 0,
            'failed' => 0,
            'errors' => [],
            'invoices' => [],
            'notifications' => []
        ];

        try {
            $accounts = $this->getActiveAccountsForBillingDay($billingDay, $generationDate);

            foreach ($accounts as $account) {
                try {
                    $invoice = $this->createEnhancedInvoice($account, $generationDate, $userId);
                    $results['invoices'][] = $invoice;
                    $results['success']++;
                    
                    $notificationResult = $this->queueNotification($account, $invoice, null);
                    $results['notifications'][] = $notificationResult;
                    
                } catch (\Exception $e) {
                    $results['failed']++;
                    $results['errors'][] = [
                        'account_id' => $account->id,
                        'account_no' => $account->account_no,
                        'error' => $e->getMessage()
                    ];
                    $this->log('error', "Failed to generate invoice for account {$account->account_no}: " . $e->getMessage());
                }
            }

            return $results;
        } catch (\Exception $e) {
            $this->log('error', "Error in generateInvoicesForBillingDay: " . $e->getMessage());
            throw $e;
        }
    }

    protected function queueNotification(
        BillingAccount $account,
        ?Invoice $invoice,
        ?StatementOfAccount $soa
    ): array {
        try {
            $this->log('info', 'Sending notification synchronously', [
                'account_no' => $account->account_no,
                'has_invoice' => $invoice !== null,
                'has_soa' => $soa !== null
            ]);

            // Execute notification synchronously.
            // NOTE: dispatch()->afterResponse() was used previously but it does NOT work
            // in CLI/Artisan context (no HTTP response lifecycle), so notifications were
            // silently never executed during cron jobs.
            // Set the time to send at 8:00 AM GMT+8 (Asia/Manila)
            $timeToSend = Carbon::now('Asia/Manila')->setTime(8, 0, 0)->format('Y-m-d H:i:s');

            $notificationResult = $this->notificationService->notifyBillingGenerated(
                $account,
                $invoice,
                $soa,
                $timeToSend
            );
            
            $this->log('info', 'Notification completed', [
                'account_no' => $account->account_no,
                'email_queued' => $notificationResult['email_queued'] ?? false,
                'sms_sent' => $notificationResult['sms_sent'] ?? false,
                'errors' => $notificationResult['errors'] ?? []
            ]);
            
            return [
                'account_no' => $account->account_no,
                'queued' => true,
                'notification_result' => $notificationResult
            ];
        } catch (\Exception $e) {
            $this->log('error', 'Failed to send notification', [
                'account_no' => $account->account_no,
                'error' => $e->getMessage()
            ]);
            
            return [
                'account_no' => $account->account_no,
                'queued' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    protected function getActiveAccountsForBillingDay(int $billingDay, Carbon $generationDate)
    {
        $targetDay = $this->adjustBillingDayForMonth($billingDay, $generationDate);

        $query = BillingAccount::with([
            'customer',
            'technicalDetails',
            'plan'
        ])
            ->where('billing_status_id', 1)
            ->whereNotNull('date_installed')
            ->whereNotNull('account_no');

        if ($billingDay === self::END_OF_MONTH_BILLING) {
            $query->where('billing_day', self::END_OF_MONTH_BILLING);
        } else {
            $query->where('billing_day', $targetDay);
        }

        $accounts = $query->get();

        $this->log('info', 'Loaded accounts with complete data', [
            'billing_day' => $billingDay,
            'generation_date' => $generationDate->format('Y-m-d'),
            'accounts_count' => $accounts->count()
        ]);

        return $accounts;
    }

    protected function adjustBillingDayForMonth(int $billingDay, Carbon $date): int
    {
        if ($billingDay === self::END_OF_MONTH_BILLING) {
            return self::END_OF_MONTH_BILLING;
        }

        if ($date->format('M') === 'Feb') {
            if ($billingDay === 29) {
                return 1;
            } elseif ($billingDay === 30) {
                return 2;
            } elseif ($billingDay === 31) {
                return 3;
            }
        }
        return $billingDay;
    }

    public function createEnhancedStatement(BillingAccount $account, Carbon $statementDate, int $userId): StatementOfAccount
    {
        $statementDate = $statementDate->copy()->setTimezone('Asia/Manila')->startOfDay();
        DB::beginTransaction();

        try {
            $customer = $account->customer;
            if (!$customer) {
                throw new \Exception("Customer not found for account {$account->account_no}");
            }

            $desiredPlan = $customer->desired_plan;
            if (!$desiredPlan) {
                throw new \Exception("No desired_plan found for customer {$customer->full_name}");
            }

            $planName = $this->extractPlanName($desiredPlan);
            
            $plan = AppPlan::where('plan_name', $planName)->first();
                
            if (!$plan) {
                $allPlans = AppPlan::select('id', 'plan_name', 'price')->get();
                throw new \Exception("Plan '{$planName}' not found in plan_list table (extracted from '{$desiredPlan}'). Available plans: " . $allPlans->pluck('plan_name')->implode(', '));
            }

            if (!$plan->price || $plan->price <= 0) {
                throw new \Exception("Plan '{$planName}' has invalid price: " . ($plan->price ?? 'NULL'));
            }

            $dueDateOffset = $this->getDueDateOffset();
            $adjustedDate = $this->calculateAdjustedBillingDate($account, $statementDate);
            $dueDate = $adjustedDate->copy()->addDays($dueDateOffset);

            // First-time customer detection (no invoice history yet). Detected here so the
            // SOA mirrors the pro-rated amount that the first invoice will carry.
            $firstInvoiceProRate = $this->calculateFirstInvoiceProRate($account, $plan->price);

            // Create initial statement to get the ID
            $statement = StatementOfAccount::create([
                'account_no' => $account->account_no,
                'statement_date' => $statementDate->format('Y-m-d'),
                'balance_from_previous_bill' => 0,
                'payment_received_previous' => 0,
                'remaining_balance_previous' => 0,
                'monthly_service_fee' => 0,
                'others_and_basic_charges' => 0,
                'service_charge' => 0,
                'rebate' => 0,
                'discounts' => 0,
                'staggered' => 0,
                'vat' => 0,
                'due_date' => $dueDate,
                'amount_due' => 0,
                'total_amount_due' => 0,
                'created_by' => (string) $userId,
                'updated_by' => (string) $userId
            ]);

            $prorateAmount = $this->calculateProrateAmount($account, $plan->price, $adjustedDate);

            // First bill for a newly installed customer: use the pro-rated amount so the
            // SOA matches the invoice. Existing customers keep the normal monthly amount.
            if ($firstInvoiceProRate !== null) {
                $prorateAmount = $firstInvoiceProRate['amount'];
            }

            $monthlyFeeGross = $prorateAmount / (1 + self::VAT_RATE);
            $vat = $monthlyFeeGross * self::VAT_RATE;

            // Reconnection pro-rate for a customer skipped last cycle but reconnected
            // inside the generation window. Added to the monthly service fee so it
            // flows into amount_due / total_amount_due below.
            $reconnectionProRate = $this->calculateReconnectionProRate($account, $plan->price, $statementDate);
            $reconnectionProRateStart = $this->lastReconnectionProRateStart;

            $monthlyServiceFee = ($prorateAmount - $vat) + $reconnectionProRate;

            // Use statement ID as the reference for charges
            $charges = $this->calculateChargesAndDeductions(
                $account, 
                $statementDate, 
                $userId, 
                (string)$statement->id,
                $plan->price,
                false,
                false
            );
            
            $othersAndBasicCharges = 0;

            $amountDue = $monthlyServiceFee + $vat + $charges['staggered_install_fees'] + $charges['service_fees'] - $charges['rebates'] - $charges['discounts'] - $charges['advanced_payments'];
            
            $previousBalance = $this->getPreviousBalance($account, $statementDate);
            $paymentReceived = $charges['payment_received_previous'];
            $remainingBalance = $previousBalance - $paymentReceived;
            $totalAmountDue = $remainingBalance + $amountDue;

            // Update statement with actual values
            $statement->update([
                'balance_from_previous_bill' => round($previousBalance, 2),
                'payment_received_previous' => round($paymentReceived, 2),
                'remaining_balance_previous' => round($remainingBalance, 2),
                'monthly_service_fee' => round($monthlyServiceFee, 2),
                'others_and_basic_charges' => round($othersAndBasicCharges, 2),
                'pro_rate' => round($reconnectionProRate, 2),
                'pro_rate_start' => $reconnectionProRateStart ? $reconnectionProRateStart->format('Y-m-d') : null,
                'service_charge' => round($charges['service_fees'], 2),
                'rebate' => round($charges['rebates'], 2),
                'discounts' => round($charges['discounts'], 2),
                'staggered' => round($charges['staggered_install_fees'], 2),
                'vat' => round($vat, 2),
                'amount_due' => round($amountDue, 2),
                'total_amount_due' => round($totalAmountDue, 2)
            ]);

            DB::commit();
            
            // GENERATE PDF AND SAVE TO GOOGLE DRIVE IMMEDIATELY AFTER COMMIT
            try {
                $pdfService = app(\App\Services\GoogleDrivePdfGenerationService::class);
                $pdfResult = $pdfService->generateBillingPdf($account, null, $statement);
                
                if (isset($pdfResult['success']) && $pdfResult['success'] && !empty($pdfResult['url'])) {
                    $statement->print_link = $pdfResult['url'];
                    $statement->save();
                    
                    $this->log('info', 'SOA PDF generated and saved to Google Drive immediately', [
                        'account_no' => $account->account_no,
                        'statement_id' => $statement->id,
                        'print_link' => $pdfResult['url']
                    ]);
                } else {
                    $this->log('error', 'Failed to generate SOA PDF immediately', [
                        'account_no' => $account->account_no,
                        'error' => $pdfResult['error'] ?? 'Unknown error'
                    ]);
                }
            } catch (\Exception $e) {
                $this->log('error', 'Exception generating SOA PDF immediately', [
                    'account_no' => $account->account_no,
                    'error' => $e->getMessage()
                ]);
            }
            
            $this->log('info', 'SOA created successfully', [
                'account_no' => $account->account_no,
                'statement_id' => $statement->id,
                'total_amount_due' => $statement->total_amount_due
            ]);
            
            return $statement;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function createEnhancedInvoice(BillingAccount $account, Carbon $invoiceDate, int $userId): Invoice
    {
        $invoiceDate = $invoiceDate->copy()->setTimezone('Asia/Manila')->startOfDay();
        DB::beginTransaction();

        try {
            $customer = $account->customer;
            if (!$customer) {
                throw new \Exception("Customer not found for account {$account->account_no}");
            }

            $desiredPlan = $customer->desired_plan;
            if (!$desiredPlan) {
                throw new \Exception("No desired_plan found for customer {$customer->full_name}");
            }

            $planName = $this->extractPlanName($desiredPlan);
            
            $plan = AppPlan::where('plan_name', $planName)->first();
            if (!$plan) {
                throw new \Exception("Plan '{$planName}' not found in plan_list table (extracted from '{$desiredPlan}')");
            }

            if (!$plan->price || $plan->price <= 0) {
                throw new \Exception("Plan '{$planName}' has invalid price: " . ($plan->price ?? 'NULL'));
            }

            $dueDateOffset = $this->getDueDateOffset();
            $adjustedDate = $this->calculateAdjustedBillingDate($account, $invoiceDate);
            $dueDate = $adjustedDate->copy()->addDays($dueDateOffset);

            // Detect a first-time (newly installed, never billed) customer BEFORE creating
            // the invoice row below, otherwise the invoice-existence check would be fooled
            // by this very invoice and never pro-rate the first bill.
            $firstInvoiceProRate = $this->calculateFirstInvoiceProRate($account, $plan->price);

            // Create initial invoice to get the ID
            $invoice = Invoice::create([
                'account_no' => $account->account_no,
                'invoice_date' => $invoiceDate->format('Y-m-d'),
                'invoice_balance' => 0,
                'others_and_basic_charges' => 0,
                'service_charge' => 0,
                'rebate' => 0,
                'discounts' => 0,
                'staggered' => 0,
                'total_amount' => 0,
                'received_payment' => 0.00,
                'due_date' => $dueDate,
                'status' => 'Unpaid',
                'created_by' => (string) $userId,
                'updated_by' => (string) $userId
            ]);
            
            $prorateAmount = $this->calculateProrateAmount($account, $plan->price, $adjustedDate);

            // First bill for a newly installed customer: replace the full monthly amount
            // with the pro-rated (install-date -> billing-day) amount. Existing customers
            // return null here and keep the normal monthly amount above.
            if ($firstInvoiceProRate !== null) {
                $prorateAmount = $firstInvoiceProRate['amount'];
            }

            // Reconnection pro-rate for a customer skipped last cycle but reconnected
            // inside the generation window. Added to invoice_balance and total_amount.
            $reconnectionProRate = $this->calculateReconnectionProRate($account, $plan->price, $invoiceDate);
            $reconnectionProRateStart = $this->lastReconnectionProRateStart;

            $charges = $this->calculateChargesAndDeductions(
                $account,
                $invoiceDate,
                $userId,
                (string)$invoice->id,
                $plan->price,
                true,
                true
            );

            $othersBasicCharges = 0;

            $totalAmount = $prorateAmount + $reconnectionProRate + $charges['staggered_install_fees'] + $charges['service_fees'] - $charges['rebates'] - $charges['discounts'] - $charges['advanced_payments'];

            if ($account->account_balance < 0) {
                $totalAmount += $account->account_balance;
            }

            $invoice->update([
                'invoice_balance' => round($prorateAmount + $reconnectionProRate, 2),
                'others_and_basic_charges' => round($othersBasicCharges, 2),
                'pro_rate' => round($reconnectionProRate, 2),
                'pro_rate_start' => $reconnectionProRateStart ? $reconnectionProRateStart->format('Y-m-d') : null,
                'service_charge' => round($charges['service_fees'], 2),
                'rebate' => round($charges['rebates'], 2),
                'discounts' => round($charges['discounts'], 2),
                'staggered' => round($charges['staggered_install_fees'], 2),
                'total_amount' => round($totalAmount, 2),
                'status' => $totalAmount <= 0 ? 'Paid' : 'Unpaid'
            ]);

            $appliedDiscounts = $charges['discounts'];
            
            $newBalance = $account->account_balance > 0 
                ? $totalAmount + $account->account_balance 
                : $totalAmount;

            $account->update([
                'account_balance' => round($newBalance, 2),
                'balance_update_date' => $invoiceDate->format('Y-m-d')
            ]);
            
            $this->log('info', 'Invoice updated with discount applied to balance', [
                'account_no' => $account->account_no,
                'invoice_balance' => $prorateAmount,
                'total_amount' => $totalAmount,
                'discounts_applied' => $appliedDiscounts,
                'previous_balance' => $account->account_balance,
                'new_balance' => $newBalance
            ]);
            
            $this->markDiscountsAsUsed($account, $userId, (string)$invoice->id);
            $this->markRebatesAsUsed($account, $userId, (string)$invoice->id);
            $this->markPlanChangesAsUsed($account, $userId, (string)$invoice->id);
            $this->trackStaggeredInvoiceAssociation($account->account_no, $invoice->id);

            // Consume the reconnection pro-rate (once, on the invoice) so it is not
            // re-charged on a subsequent billing cycle. Only when actually charged.
            if ($reconnectionProRate > 0) {
                $this->markReconnectionProRateAsUsed($account, $userId, (string)$invoice->id, $invoiceDate);
            }

            DB::commit();
            
            $this->log('info', 'Invoice created successfully', [
                'account_no' => $account->account_no,
                'invoice_id' => $invoice->id,
                'total_amount' => $invoice->total_amount
            ]);
            
            return $invoice;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
    

    protected function calculateAdjustedBillingDate(BillingAccount $account, Carbon $baseDate): Carbon
    {
        if ($account->billing_day === self::END_OF_MONTH_BILLING) {
            return $baseDate->copy()->endOfMonth();
        }
        
        // Normalize time to start of day to avoid time propagation issues
        $baseDate = $baseDate->copy()->startOfDay();
        $adjustedDate = $baseDate->copy()->day($account->billing_day);
        
        // If the calculated billing day is in the past relative to the generation date,
        // it means we are generating the bill in advance for the next month.
        if ($adjustedDate->format('Y-m-d') < $baseDate->format('Y-m-d')) {
            $adjustedDate->addMonth();
        }
        
        return $adjustedDate;
    }

    protected function calculateProrateAmount(BillingAccount $account, float $monthlyFee, Carbon $currentDate): float
    {
        // Try to find an unused plan change log for this account
        $planChange = DB::table('plan_change_logs')
            ->where('account_id', $account->id)
            ->where('status', 'Unused')
            ->orderBy('date_changed', 'desc')
            ->first();

        if (!$planChange) {
            // No plan change, return the fixed monthly plan price
            return $monthlyFee;
        }

        // Get the old and new plan details
        $oldPlan = AppPlan::find($planChange->old_plan_id);
        $newPlan = AppPlan::find($planChange->new_plan_id);

        if (!$oldPlan || !$newPlan) {
            $this->log('warning', 'Plan change log found but plans not found', [
                'account_no' => $account->account_no,
                'old_plan_id' => $planChange->old_plan_id,
                'new_plan_id' => $planChange->new_plan_id
            ]);
            return $monthlyFee;
        }

        $oldPrice = (float)$oldPlan->price;
        $newPrice = (float)$newPlan->price;
        $dateChanged = Carbon::parse($planChange->date_changed);

        // Define the billing cycle period (one month)
        // $currentDate is the adjusted billing date (end of the period)
        $cycleEnd = $currentDate->copy();
        $cycleStart = $cycleEnd->copy()->subMonth();
        
        // Dynamic days based on the actual billing period (e.g., 28 for Feb, 31 for Mar)
        $totalDays = $cycleStart->diffInDays($cycleEnd);
        if ($totalDays <= 0) $totalDays = self::DAYS_IN_MONTH; 

        // Check if the plan change occurred within this billing cycle
        if ($dateChanged->lte($cycleEnd)) {
            
            if ($dateChanged->gt($cycleStart)) {
                // Change happened during the cycle
                $daysOnOldPlan = $cycleStart->diffInDays($dateChanged);
                // Ensure we don't exceed the total days in the period
                if ($daysOnOldPlan > $totalDays) $daysOnOldPlan = $totalDays;
                
                $daysOnNewPlan = $totalDays - $daysOnOldPlan;
            } else {
                // Change happened before the cycle start but was not used (pushed to this bill)
                // If it's unused, we'll assume they were on the new plan for the whole month
                // since they officially switched but it hasn't been applied to a bill yet.
                return $monthlyFee;
            }

            $proratedAmount = (($daysOnOldPlan / $totalDays) * $oldPrice) + (($daysOnNewPlan / $totalDays) * $newPrice);
            
            $this->log('info', 'Prorating monthly fee due to plan change', [
                'account_no' => $account->account_no,
                'old_plan' => $oldPlan->plan_name,
                'new_plan' => $newPlan->plan_name,
                'days_old' => $daysOnOldPlan,
                'days_new' => $daysOnNewPlan,
                'old_price' => $oldPrice,
                'new_price' => $newPrice,
                'total_days_in_month' => $totalDays,
                'total_amount' => $proratedAmount
            ]);

            return round($proratedAmount, 2);
        }

        return $monthlyFee;
    }

    /**
     * Pro-rate the VERY FIRST invoice for a newly installed customer.
     *
     * A customer is "first-time billed" when no invoice record exists yet for the
     * account. Their first bill only covers the partial period from the installation
     * date up to their configured billing day, charged on a fixed 30-day basis:
     *
     *     Daily Rate       = Monthly Plan Amount / 30
     *     Pro-rated Amount = Daily Rate * Remaining Billable Days
     *
     * Where Remaining Billable Days is the number of days from the installation date
     * to the upcoming billing_day. If the customer installed exactly on their billing
     * day (or a full cycle away) the full monthly amount is charged instead.
     *
     * Returns null for customers who already have invoice history, so they follow the
     * normal monthly billing process untouched. The returned 'amount' is a drop-in
     * replacement for the monthly plan price (VAT-inclusive, same basis as the plan
     * price) and carries no side effects — first-invoice detection stays purely a
     * function of invoice existence, which prevents duplicate pro-rated first invoices.
     *
     * @return array{amount: float, pro_rate_days: int, daily_rate: float, full_month: bool}|null
     */
    protected function calculateFirstInvoiceProRate(BillingAccount $account, float $monthlyFee): ?array
    {
        // Existing customers (any invoice on record) are billed the normal monthly way.
        $hasInvoiceHistory = Invoice::where('account_no', $account->account_no)->exists();
        if ($hasInvoiceHistory) {
            return null;
        }

        $dailyRate = $monthlyFee / self::DAYS_IN_MONTH;

        // Defensive: an account with no installation date cannot be pro-rated by days,
        // so it is billed the full monthly amount (still flagged as first-time).
        if (empty($account->date_installed)) {
            $this->log('warning', 'First-time customer has no installation date; charging full monthly amount', [
                'account_no'    => $account->account_no,
                'is_first_time' => true,
                'billing_day'   => (int) $account->billing_day,
            ]);

            return [
                'amount'        => round($monthlyFee, 2),
                'pro_rate_days' => self::DAYS_IN_MONTH,
                'daily_rate'    => round($dailyRate, 2),
                'full_month'    => true,
            ];
        }

        $installDate = Carbon::parse($account->date_installed)->startOfDay();
        $installDay  = (int) $installDate->day;

        // Remaining days are counted on a fixed 30-day cycle, NOT on actual calendar
        // days, so the charge is identical whether the month has 28, 30, or 31 days.
        // End-of-month billing and any day beyond the 30-day frame normalize to 30.
        $rawBillingDay = (int) $account->billing_day;
        $billingDay = ($rawBillingDay === self::END_OF_MONTH_BILLING || $rawBillingDay > self::DAYS_IN_MONTH)
            ? self::DAYS_IN_MONTH
            : $rawBillingDay;
        if ($installDay > self::DAYS_IN_MONTH) {
            $installDay = self::DAYS_IN_MONTH;
        }

        // Remaining billable days on a fixed 30-day cycle (calendar month length is
        // irrelevant). The span runs from the install day up to the billing day.
        if ($installDay < $billingDay) {
            // Same cycle: install day through billing day, inclusive of both ends.
            // e.g. installed on the 1st with billing day 30 => 30 - 1 + 1 = 30 days.
            $proRateDays = $billingDay - $installDay + 1;
        } else {
            // Installed on/after the billing day => bill from the install day up to the
            // NEXT cycle's billing day. Installing exactly on the billing day gives a
            // full 30-day cycle. e.g. install day 20, billing day 10 => 30 - 10 = 20 days.
            $proRateDays = self::DAYS_IN_MONTH - ($installDay - $billingDay);
        }

        // A full 30-day span is simply the normal monthly amount.
        $fullMonth = ($proRateDays >= self::DAYS_IN_MONTH);
        $amount = $fullMonth ? $monthlyFee : ($dailyRate * $proRateDays);

        $this->log('info', 'First-time customer detected: pro-rating first invoice', [
            'account_no'    => $account->account_no,
            'is_first_time' => true,
            'install_date'  => $installDate->format('Y-m-d'),
            'billing_day'   => $rawBillingDay,
            'pro_rate_days' => $proRateDays,
            'daily_rate'    => round($dailyRate, 2),
            'monthly_amount' => round($monthlyFee, 2),
            'final_amount'  => round($amount, 2),
            'full_month'    => $fullMonth,
        ]);

        return [
            'amount'        => round($amount, 2),
            'pro_rate_days' => $proRateDays,
            'daily_rate'    => round($dailyRate, 2),
            'full_month'    => $fullMonth,
        ];
    }

    /**
     * Calculate a pro-rate charge for a customer who was disconnected (and therefore
     * skipped) during the previous billing run, but reconnected inside that previous
     * cycle's generation window before their billing_day.
     *
     * The pro-rate window is bounded by BOTH billing_day AND advance_generation_day:
     *   prevBillingDayDate  = previous cycle's billing_day date
     *   prevGenerationDate  = prevBillingDayDate - advance_generation_day days
     * If the customer reconnected between those two dates and never received a bill
     * for that window, they owe a pro-rate for the days from reconnection -> billing_day.
     *
     * Example: billing_day=10, advance_generation_day=7, generation runs May 3 for the
     * May 10 cycle. The next run (June 3, for the June 10 cycle) checks the May 3 -> May 10
     * window. A reconnection on May 4 yields 6 days => (plan_price / 30) * 6.
     *
     * @param  Carbon  $generationDate  The date billing is being generated (today).
     */
    protected function calculateReconnectionProRate(BillingAccount $account, float $planPrice, Carbon $generationDate): float
    {
        // Reset carried state for every calculation so a previous account's match
        // can never leak into this account's PDF coverage period.
        $this->lastReconnectionProRateStart = null;

        try {
            $match = $this->findReconnectionForProRate($account, $generationDate);
            if (!$match) {
                return 0.0;
            }

            $reconnectionDate   = $match['reconnection_date'];
            $prevGenerationDate = $match['prev_generation_date'];
            $prevBillingDayDate = $match['prev_billing_day_date'];

            // Days from the reconnection date up to the cycle's billing_day.
            $proRateDays = (int) abs(
                $reconnectionDate->copy()->startOfDay()
                    ->diffInDays($prevBillingDayDate->copy()->startOfDay())
            );

            // Guard: nothing to charge if they reconnected on/after the billing day.
            if ($proRateDays <= 0) {
                return 0.0;
            }

            $proRateAmount = round(($planPrice / self::DAYS_IN_MONTH) * $proRateDays, 2);

            // Carry the reconnection date so the billing document's coverage period
            // (PDF Period_Start) can reflect the actual first billed day.
            $this->lastReconnectionProRateStart = $reconnectionDate->copy();

            $this->log('info', 'Reconnection pro-rate applied', [
                'account_no'           => $account->account_no,
                'reconnection_date'    => $reconnectionDate->format('Y-m-d'),
                'prev_generation_date' => $prevGenerationDate->format('Y-m-d'),
                'prev_billing_day'     => $prevBillingDayDate->format('Y-m-d'),
                'pro_rate_days'        => $proRateDays,
                'pro_rate_amount'      => $proRateAmount,
            ]);

            return $proRateAmount;
        } catch (\Throwable $e) {
            // Never let a pro-rate lookup break billing generation.
            $this->log('error', 'Failed to calculate reconnection pro-rate', [
                'account_no' => $account->account_no,
                'error'      => $e->getMessage(),
            ]);
            return 0.0;
        }
    }

    /**
     * Locate the reconnection log (if any) that qualifies this account for a
     * reconnection pro-rate on the current generation run, together with the
     * previous-cycle window dates. Returns null when no pro-rate is owed.
     *
     * Guard conditions (any => null):
     *   - A billing (invoice) already exists for the account inside the window
     *     (the account was NOT skipped last cycle).
     *   - No un-applied reconnection log exists inside the window.
     *
     * @return array{reconnection: ReconnectionLog, reconnection_date: Carbon, prev_generation_date: Carbon, prev_billing_day_date: Carbon}|null
     */
    protected function findReconnectionForProRate(BillingAccount $account, Carbon $generationDate): ?array
    {
        $advanceGenerationDay = $this->getAdvanceGenerationDay();

        // Current cycle's billing_day date (already handles advance-generation
        // month wrap-around, e.g. generating in advance for next month).
        $adjustedBillingDate = $this->calculateAdjustedBillingDate($account, $generationDate);

        // Previous billing cycle window.
        $prevBillingDayDate = $adjustedBillingDate->copy()->subMonthNoOverflow()->startOfDay();

        // For end-of-month accounts the previous billing day is the LAST day of the
        // previous month (e.g. May 31). subMonthNoOverflow alone does not yield this
        // when the current month is shorter (June 30 -> May 30), so re-snap to the
        // end of the previous month to keep the window and day count correct.
        if ((int) $account->billing_day === self::END_OF_MONTH_BILLING) {
            $prevBillingDayDate = $prevBillingDayDate->endOfMonth()->startOfDay();
        }

        $prevGenerationDate = $prevBillingDayDate->copy()->subDays($advanceGenerationDay)->startOfDay();
        $windowEnd          = $prevBillingDayDate->copy()->endOfDay();

        // Guard: if the account already has an invoice dated inside the previous
        // window, it was billed (not skipped) and no pro-rate is needed.
        $billingExists = Invoice::where('account_no', $account->account_no)
            ->whereBetween('invoice_date', [$prevGenerationDate, $windowEnd])
            ->exists();

        if ($billingExists) {
            return null;
        }

        // Most recent reconnection inside the window that has not yet been billed
        // a pro-rate (prevents double-charging on later cycles).
        $reconnection = ReconnectionLog::where('account_id', $account->id)
            ->whereBetween('created_at', [$prevGenerationDate, $windowEnd])
            ->where(function ($q) {
                $q->where('pro_rate_applied', false)
                    ->orWhereNull('pro_rate_applied');
            })
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$reconnection) {
            return null;
        }

        return [
            'reconnection'          => $reconnection,
            'reconnection_date'     => Carbon::parse($reconnection->created_at)->startOfDay(),
            'prev_generation_date'  => $prevGenerationDate,
            'prev_billing_day_date' => $prevBillingDayDate,
        ];
    }

    /**
     * Mark the reconnection that drove a pro-rate as billed so it is never
     * charged again on a subsequent cycle. Called once, from the invoice
     * (the authoritative document that "consumes" one-time charges) so that
     * the SOA and invoice for the same run both reflect the pro-rate first.
     */
    protected function markReconnectionProRateAsUsed(BillingAccount $account, int $userId, string $invoiceId, Carbon $generationDate): void
    {
        try {
            $match = $this->findReconnectionForProRate($account, $generationDate);
            if (!$match) {
                return;
            }

            /** @var ReconnectionLog $reconnection */
            $reconnection = $match['reconnection'];
            $reconnection->update([
                'pro_rate_applied'    => true,
                'billing_status'      => 'Billed',
                'pro_rate_invoice_id' => $invoiceId,
                'pro_rate_billed_at'  => now(),
                'updated_by_user'     => (string) $userId,
            ]);

            // Only the most recent reconnection in the window is charged (per spec).
            // Mark any other un-applied reconnections in the same window as superseded
            // so they are not left lingering as pending / re-evaluated later.
            $superseded = ReconnectionLog::where('account_id', $account->id)
                ->where('id', '!=', $reconnection->id)
                ->whereBetween('created_at', [
                    $match['prev_generation_date'],
                    $match['prev_billing_day_date']->copy()->endOfDay(),
                ])
                ->where(function ($q) {
                    $q->where('pro_rate_applied', false)
                        ->orWhereNull('pro_rate_applied');
                })
                ->update([
                    'pro_rate_applied'    => true,
                    'billing_status'      => 'Superseded',
                    'pro_rate_invoice_id' => $invoiceId,
                    'pro_rate_billed_at'  => now(),
                    'updated_by_user'     => (string) $userId,
                ]);

            $this->log('info', 'Reconnection marked as pro-rate applied', [
                'account_no'        => $account->account_no,
                'reconnection_id'   => $reconnection->id,
                'invoice_id'        => $invoiceId,
                'superseded_count'  => $superseded,
            ]);
        } catch (\Throwable $e) {
            $this->log('error', 'Failed to mark reconnection pro-rate as applied', [
                'account_no' => $account->account_no,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    protected function getDaysBetweenDatesIncludingDueDate(Carbon $startDate, Carbon $endDate): int
    {
        $endDateWithBuffer = $endDate->copy()->addDays(self::DAYS_UNTIL_DUE);
        return $startDate->diffInDays($endDateWithBuffer) + 1;
    }

    protected function getDueDateOffset(): int
    {
        $billingConfig = BillingConfig::first();
        
        if (!$billingConfig || $billingConfig->due_date_day === null) {
            $this->log('info', 'No due_date_day configured, using default ' . self::DAYS_UNTIL_DUE);
            return self::DAYS_UNTIL_DUE;
        }
        
        return (int)$billingConfig->due_date_day;
    }

    protected function getAdvanceGenerationDay(): int
    {
        $billingConfig = BillingConfig::first();
        
        if (!$billingConfig || $billingConfig->advance_generation_day === null) {
            $this->log('info', 'No advance_generation_day configured, using default 0');
            return 0;
        }
        
        return $billingConfig->advance_generation_day;
    }

    protected function calculateTargetBillingDays(Carbon $generationDate): array
    {
        $advanceGenerationDay = $this->getAdvanceGenerationDay();
        $currentDay = $generationDate->day;
        $targetBillingDay = $currentDay + $advanceGenerationDay;
        
        $billingDays = [];
        
        if ($generationDate->isLastOfMonth()) {
            $billingDays[] = self::END_OF_MONTH_BILLING;
            
            $lastDayOfMonth = $generationDate->day;
            $targetDay = $lastDayOfMonth + $advanceGenerationDay;
            
            if ($targetDay <= 31) {
                $billingDays[] = $targetDay;
            }
        } else {
            if ($targetBillingDay <= 31) {
                $billingDays[] = $targetBillingDay;
            }
            
            $lastDayOfMonth = $generationDate->copy()->endOfMonth()->day;
            if ($targetBillingDay > $lastDayOfMonth) {
                $billingDays[] = self::END_OF_MONTH_BILLING;
            }
        }
        
        $this->log('info', 'Calculated target billing days', [
            'generation_date' => $generationDate->format('Y-m-d'),
            'current_day' => $currentDay,
            'advance_generation_day' => $advanceGenerationDay,
            'target_billing_day' => $targetBillingDay,
            'billing_days_to_process' => $billingDays
        ]);
        
        return $billingDays;
    }

    public function generateAllBillingsForToday(int $userId): array
    {
        $today = Carbon::now('Asia/Manila');
        $targetBillingDays = $this->calculateTargetBillingDays($today);
        $advanceGenerationDay = $this->getAdvanceGenerationDay();

        $results = [
            'date' => $today->format('Y-m-d'),
            'advance_generation_day' => $advanceGenerationDay,
            'billing_days_processed' => [],
            'invoices' => ['success' => 0, 'failed' => 0, 'errors' => [], 'notifications' => []],
            'statements' => ['success' => 0, 'failed' => 0, 'errors' => [], 'notifications' => []]
        ];

        foreach ($targetBillingDays as $billingDay) {
            $billingDayLabel = $billingDay === self::END_OF_MONTH_BILLING ? 'End of Month (0)' : "Day {$billingDay}";
            
            $this->log('info', "Processing billing day: {$billingDayLabel}");
            
            // Use Unified Billing Generation to prevent duplicate SMS
            $unifiedResults = $this->generateUnifiedBilling($billingDay, $today, $userId);
            
            $results['billing_days_processed'][] = $billingDayLabel;
            
            // Merge Invoice Results
            $results['invoices']['success'] += $unifiedResults['invoices']['success'];
            $results['invoices']['failed'] += $unifiedResults['invoices']['failed'];
            $results['invoices']['errors'] = array_merge($results['invoices']['errors'], $unifiedResults['invoices']['errors']);
            
            // Merge Statement Results
            $results['statements']['success'] += $unifiedResults['statements']['success'];
            $results['statements']['failed'] += $unifiedResults['statements']['failed'];
            $results['statements']['errors'] = array_merge($results['statements']['errors'], $unifiedResults['statements']['errors']);
            
            // Merge Notifications (Unified) - adding to statements for tracking, though it covers both
            $results['statements']['notifications'] = array_merge($results['statements']['notifications'], $unifiedResults['notifications'] ?? []);
        }

        return $results;
    }

    public function generateUnifiedBilling(int $billingDay, Carbon $generationDate, int $userId): array
    {
        $results = [
            'invoices' => ['success' => 0, 'failed' => 0, 'errors' => []],
            'statements' => ['success' => 0, 'failed' => 0, 'errors' => []],
            'notifications' => []
        ];

        try {
            $accounts = $this->getActiveAccountsForBillingDay($billingDay, $generationDate);

            foreach ($accounts as $account) {
                $soa = null;
                $invoice = null;

                // 1. Generate SOA
                try {
                    $soa = $this->createEnhancedStatement($account, $generationDate, $userId);
                    $results['statements']['success']++;
                } catch (\Exception $e) {
                    $results['statements']['failed']++;
                    $results['statements']['errors'][] = [
                        'account_id' => $account->id,
                        'account_no' => $account->account_no,
                        'error' => "SOA Error: " . $e->getMessage()
                    ];
                    $this->log('error', "Failed to generate SOA for account {$account->account_no}: " . $e->getMessage());
                }

                // 2. Generate Invoice
                try {
                    $invoice = $this->createEnhancedInvoice($account, $generationDate, $userId);
                    $results['invoices']['success']++;
                } catch (\Exception $e) {
                    $results['invoices']['failed']++;
                    $results['invoices']['errors'][] = [
                        'account_id' => $account->id,
                        'account_no' => $account->account_no,
                        'error' => "Invoice Error: " . $e->getMessage()
                    ];
                    $this->log('error', "Failed to generate Invoice for account {$account->account_no}: " . $e->getMessage());
                }

                // 3. Notify ONCE (if either exists)
                if ($soa || $invoice) {
                     $notificationResult = $this->queueNotification($account, $invoice, $soa);
                     $results['notifications'][] = $notificationResult;
                }
            }
        } catch (\Exception $e) {
            $this->log('error', "Error in generateUnifiedBilling: " . $e->getMessage());
            // In case of catastrophic failure, we just return partial results with the error logged
            // You might want to bubble this up depending on desire
        }
        
        return $results;
    }

    public function generateBillingsForSpecificDay(int $billingDay, int $userId): array
    {
        $today = Carbon::now('Asia/Manila');

        // Use Unified Billing
        $unifiedResults = $this->generateUnifiedBilling($billingDay, $today, $userId);

        return [
            'date' => $today->format('Y-m-d'),
            'billing_day' => $billingDay === self::END_OF_MONTH_BILLING ? 'End of Month (0)' : $billingDay,
            'invoices' => $unifiedResults['invoices'],
            'statements' => $unifiedResults['statements'],
            'notifications' => $unifiedResults['notifications']
        ];
    }

    protected function calculateChargesAndDeductions(
        BillingAccount $account, 
        Carbon $date, 
        int $userId, 
        string $invoiceId,
        float $monthlyFee,
        bool $updateDiscountStatus = false,
        bool $includeDiscounts = true
    ): array {
        $staggeredInstallFees = $this->calculateStaggeredInstallFees($account, $userId, $invoiceId, $updateDiscountStatus);
        $discounts = $includeDiscounts ? $this->calculateDiscounts($account, $userId, $invoiceId, $updateDiscountStatus) : 0;
        $advancedPayments = $this->calculateAdvancedPayments($account, $date, $userId, $invoiceId);
        $rebates = $this->calculateRebates($account, $date, $monthlyFee);
        $serviceFees = $this->calculateServiceFees($account, $date, $userId);
        $paymentReceived = $this->calculatePaymentReceived($account, $date);

        return [
            'staggered_install_fees' => $staggeredInstallFees,
            'discounts' => $discounts,
            'advanced_payments' => $advancedPayments,
            'rebates' => $rebates,
            'service_fees' => $serviceFees,
            'total_deductions' => $advancedPayments + $discounts + $rebates,
            'payment_received_previous' => $paymentReceived
        ];
    }

    protected function calculateStaggeredInstallFees(BillingAccount $account, int $userId, string $invoiceId, bool $updateStatus = false): float
    {
        $total = 0;

        $staggeredInstallations = StaggeredInstallation::where('account_no', $account->account_no)
            ->where('status', 'Active')
            ->where('months_to_pay', '>', 0)
            ->get();

        foreach ($staggeredInstallations as $installation) {
            $total += $installation->monthly_payment;
        }

        return round($total, 2);
    }

    protected function calculateDiscounts(BillingAccount $account, int $userId, string $invoiceId, bool $updateStatus = false): float
    {
        $total = 0;

        $discounts = Discount::where('account_no', $account->account_no)
            ->whereIn('status', ['Unused', 'Permanent', 'Monthly'])
            ->get();

        foreach ($discounts as $discount) {
            if ($discount->status === 'Unused') {
                $total += $discount->discount_amount;
            } elseif ($discount->status === 'Permanent') {
                $total += $discount->discount_amount;
            } elseif ($discount->status === 'Monthly' && $discount->remaining > 0) {
                $total += $discount->discount_amount;
            }
        }

        return round($total, 2);
    }

    protected function calculateAdvancedPayments(
        BillingAccount $account, 
        Carbon $date, 
        int $userId, 
        string $invoiceId
    ): float {
        $total = 0;
        $currentMonth = $date->format('F');

        $advancedPayments = AdvancedPayment::where('account_no', $account->account_no)
            ->where('payment_month', $currentMonth)
            ->where('status', 'Unused')
            ->get();

        foreach ($advancedPayments as $payment) {
            $total += $payment->payment_amount;
            $payment->update([
                'status' => 'Used',
                'invoice_used_id' => $invoiceId,
                'updated_by' => $userId
            ]);
        }

        return round($total, 2);
    }

    protected function calculateRebates(BillingAccount $account, Carbon $date, float $monthlyFee): float
    {
        $total = 0;
        $currentMonth = $date->format('F');
        
        $customer = $account->customer;
        if (!$customer) {
            return 0;
        }

        $technicalDetails = $account->technicalDetails->first();
        if (!$technicalDetails) {
            return 0;
        }

        $rebates = MassRebate::where('status', 'Unused')
            ->where('month', $currentMonth)
            ->get();

        $daysInCurrentMonth = $date->daysInMonth;
        $dailyRate = $monthlyFee / $daysInCurrentMonth;

        foreach ($rebates as $rebate) {
            $matchFound = false;

            if ($rebate->rebate_type === 'lcpnap') {
                if ($technicalDetails->lcpnap && $technicalDetails->lcpnap === $rebate->selected_rebate) {
                    $matchFound = true;
                }
            } elseif ($rebate->rebate_type === 'lcp') {
                if ($technicalDetails->lcp && $technicalDetails->lcp === $rebate->selected_rebate) {
                    $matchFound = true;
                }
            } elseif ($rebate->rebate_type === 'location') {
                if (($customer->location && $customer->location === $rebate->selected_rebate) ||
                    ($customer->barangay && $customer->barangay === $rebate->selected_rebate)) {
                    $matchFound = true;
                }
            }

            if ($matchFound) {
                $rebateUsage = RebateUsage::where('rebates_id', $rebate->id)
                    ->where('account_no', $account->account_no)
                    ->where('status', 'Unused')
                    ->first();

                if ($rebateUsage) {
                    $rebateDays = $rebate->number_of_dates ?? 0;
                    $rebateValue = $dailyRate * $rebateDays;
                    $total += $rebateValue;
                }
            }
        }

        return round($total, 2);
    }

    protected function calculateServiceFees(BillingAccount $account, Carbon $date, int $userId): float
    {
        $total = 0;

        $serviceFees = DB::table('service_charge_logs')
            ->where('account_no', $account->account_no)
            ->where('status', 'Unused')
            ->get();

        foreach ($serviceFees as $fee) {
            $total += $fee->service_charge;
            
            DB::table('service_charge_logs')
                ->where('id', $fee->id)
                ->update([
                    'status' => 'Used',
                    'date_used' => now(),
                    'updated_at' => now()
                ]);
        }

        return round($total, 2);
    }

    protected function calculatePaymentReceived(BillingAccount $account, Carbon $date): float
    {
        $lastMonth = $date->copy()->subMonth();
        
        $transactions = DB::table('transactions')
            ->where('account_no', $account->account_no)
            ->where('status', 'Done')
            ->whereNotIn('transaction_type', ['Security Deposit', 'Installation Fee'])
            ->whereMonth('payment_date', $lastMonth->month)
            ->whereYear('payment_date', $lastMonth->year)
            ->sum('received_payment');

        return floatval($transactions);
    }

    protected function extractPlanName(string $desiredPlan): string
    {
        // First handle " - " separator
        if (strpos($desiredPlan, ' - ') !== false) {
            $parts = explode(' - ', $desiredPlan);
            $desiredPlan = trim($parts[0]);
        }
        
        // Then handle space separator (e.g., "SWIFT 1000" -> "SWIFT")
        if (strpos($desiredPlan, ' ') !== false) {
            $parts = explode(' ', $desiredPlan);
            return trim($parts[0]);
        }
        
        return trim($desiredPlan);
    }

    protected function getPreviousBalance(BillingAccount $account, Carbon $currentDate): float
    {
        $accountBalance = floatval($account->account_balance);
        
        $this->log('info', 'Getting previous balance for SOA', [
            'account_no' => $account->account_no,
            'account_balance' => $accountBalance,
            'current_date' => $currentDate->format('Y-m-d')
        ]);
        
        return $accountBalance;
    }

    protected function markDiscountsAsUsed(BillingAccount $account, int $userId, string $invoiceId): void
    {
        $discounts = Discount::where('account_no', $account->account_no)
            ->whereIn('status', ['Unused', 'Permanent', 'Monthly'])
            ->get();

        foreach ($discounts as $discount) {
            if ($discount->status === 'Unused') {
                $discount->update([
                    'status' => 'Used',
                    'invoice_used_id' => $invoiceId,
                    'used_date' => now(),
                    'updated_by_user_id' => $userId
                ]);
            } elseif ($discount->status === 'Permanent') {
                $discount->update([
                    'invoice_used_id' => $invoiceId,
                    'updated_by_user_id' => $userId
                ]);
            } elseif ($discount->status === 'Monthly' && $discount->remaining > 0) {
                $discount->update([
                    'invoice_used_id' => $invoiceId,
                    'remaining' => $discount->remaining - 1,
                    'updated_by_user_id' => $userId
                ]);
            }
        }
    }

    protected function markPlanChangesAsUsed(BillingAccount $account, int $userId, string $invoiceId): void
    {
        DB::table('plan_change_logs')
            ->where('account_id', $account->id)
            ->where('status', 'Unused')
            ->update([
                'status' => 'Used',
                'date_used' => now(),
                'remarks' => DB::raw("CONCAT(IFNULL(remarks, ''), ' [Applied to Invoice: ', '$invoiceId', ']')"),
                'updated_by_user' => (string) $userId,
                'updated_at' => now()
            ]);
    }

    protected function markRebatesAsUsed(BillingAccount $account, int $userId, string $invoiceId): void
    {
        $currentMonth = Carbon::now('Asia/Manila')->format('F');
        $customer = $account->customer;
        
        if (!$customer) {
            return;
        }

        $technicalDetails = $account->technicalDetails->first();
        if (!$technicalDetails) {
            return;
        }

        $rebates = MassRebate::where('status', 'Unused')
            ->where('month', $currentMonth)
            ->get();

        foreach ($rebates as $rebate) {
            $matchFound = false;

            if ($rebate->rebate_type === 'lcpnap') {
                if ($technicalDetails->lcpnap && $technicalDetails->lcpnap === $rebate->selected_rebate) {
                    $matchFound = true;
                }
            } elseif ($rebate->rebate_type === 'lcp') {
                if ($technicalDetails->lcp && $technicalDetails->lcp === $rebate->selected_rebate) {
                    $matchFound = true;
                }
            } elseif ($rebate->rebate_type === 'location') {
                if (($customer->location && $customer->location === $rebate->selected_rebate) ||
                    ($customer->barangay && $customer->barangay === $rebate->selected_rebate)) {
                    $matchFound = true;
                }
            }

            if ($matchFound) {
                $rebateUsage = RebateUsage::where('rebates_id', $rebate->id)
                    ->where('account_no', $account->account_no)
                    ->where('status', 'Unused')
                    ->first();

                if ($rebateUsage) {
                    $rebateUsage->update(['status' => 'Used']);
                    $this->checkAndUpdateRebateStatus($rebate->id, $userId);
                }
            }
        }
    }

    protected function checkAndUpdateRebateStatus(int $rebateId, int $userId): void
    {
        $unusedCount = RebateUsage::where('rebates_id', $rebateId)
            ->where('status', 'Unused')
            ->count();

        if ($unusedCount === 0) {
            $rebate = MassRebate::find($rebateId);
            if ($rebate) {
                $rebate->update([
                    'status' => 'Used',
                    'modified_by' => (string) $userId,
                    'modified_date' => now()
                ]);
            }
        }
    }

    protected function trackStaggeredInvoiceAssociation(string $accountNo, int $invoiceId): void
    {
        try {
            $staggeredInstallations = StaggeredInstallation::where('account_no', $accountNo)
                ->where('status', 'Active')
                ->where('months_to_pay', '>', 0)
                ->get();

            foreach ($staggeredInstallations as $staggered) {
                $monthColumn = null;
                for ($i = 1; $i <= 12; $i++) {
                    $col = 'month' . $i;
                    if (empty($staggered->$col)) {
                        $monthColumn = $col;
                        break;
                    }
                }

                if (!$monthColumn) {
                    continue;
                }

                $staggered->$monthColumn = (string)$invoiceId;
                $staggered->months_to_pay = $staggered->months_to_pay - 1;

                if ($staggered->months_to_pay <= 0) {
                    $staggered->status = 'Completed';
                }

                $staggered->modified_by = 'system';
                $staggered->modified_date = now();
                $staggered->timestamps = false;
                $staggered->save();
            }
        } catch (\Exception $e) {
            $this->log('error', 'Error tracking staggered invoice association: ' . $e->getMessage());
        }
    }

    public function generateOverdueNotices(bool $force = false, int $userId = 1): array
    {
        $config = [
            'overdue_off' => 1 // Default 1 day after due date
        ];
        
        $targetDue = Carbon::now('Asia/Manila')->subDays($config['overdue_off'])->format('Y-m-d');
        $this->log('info', ">> OVERDUE GEN: Finding Invoices with Due Date = $targetDue");

        $invoices = Invoice::whereDate('due_date', $targetDue)
            ->whereIn('status', ['Unpaid', 'Partial'])
            ->get();
            
        $this->log('info', ">> Found " . $invoices->count() . " potential overdue invoices.");

        $cnt = 0;
        $results = [
            'success' => 0, 
            'failed' => 0, 
            'errors' => [],
            'target_due_date' => $targetDue,
            'found_invoices' => $invoices->count()
        ];

        foreach ($invoices as $inv) {
            // Skip accounts that are already Pullout or Disconnected
            $billingAccount = \App\Models\BillingAccount::where('account_no', $inv->account_no)->first();
            if ($billingAccount) {
                $statusName = $billingAccount->billingStatus ? $billingAccount->billingStatus->status_name : null;
                if (in_array($statusName, ['Pullout', 'Disconnected', 'Pullout Restricted'])) {
                    $this->log('info', "   Skipping Inv: {$inv->id} (Account status is {$statusName} - no notification)");
                    continue;
                }
            }

            if (!$force) {
                // Check if Overdue record exists
                $exists = Overdue::where('invoice_id', $inv->id)->exists();
                if ($exists) {
                    $this->log('info', "   Skipping Inv: {$inv->id} (Overdue notice already sent)");
                    continue;
                }
            }

            $this->log('info', "   Processing Overdue for Inv: {$inv->id} (Acct: {$inv->account_no})");

            try {
                $systemUserId = $userId; 

                // Use Notification Service to Generate PDF and Send Notifications
                $notificationResult = $this->notificationService->notifyOverdue($inv);
                
                $pdfUrl = $notificationResult['pdf_url'] ?? null;

                // Resolve account_id from billing_accounts using account_no
                $billingAccount = \App\Models\BillingAccount::where('account_no', $inv->account_no)->first();
                $accountId = $billingAccount ? $billingAccount->id : $inv->account_id;

                // Insert into Overdue table
                Overdue::create([
                    'account_id' => $accountId,
                    'account_no' => $inv->account_no,
                    'invoice_id' => $inv->id, 
                    'overdue_date' => now(),
                    'print_link' => $pdfUrl,
                    'created_by_user_id' => $systemUserId,
                    'updated_by_user_id' => $systemUserId
                ]);

                $cnt++;
                $results['success']++;

            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = "Error processing invoice {$inv->id}: " . $e->getMessage();
                $this->log('error', "ERROR in Overdue {$inv->account_no}: " . $e->getMessage());
            }
        }

        return $results;
    }

    public function generateDCNotices(bool $force = false, int $userId = 1, bool $bypassDateCheck = false): array
    {
        $config = [
            'dc_note_off' => 3 // Default 3 days after due date
        ];

        $targetDue = Carbon::now('Asia/Manila')->subDays($config['dc_note_off'])->format('Y-m-d');
        $query = Invoice::whereIn('status', ['Unpaid', 'Partial']);
        
        if (!$bypassDateCheck) {
            $query->whereDate('due_date', $targetDue);
            $this->log('info', ">> DC NOTICE GEN: Finding Invoices with Due Date = $targetDue");
        } else {
            $this->log('info', ">> DC NOTICE GEN: Bypassing Date Check (Fetching ALL Unpaid)");
        }
            
        $invoices = $query->get();
            
        $this->log('info', ">> Found " . $invoices->count() . " invoices qualifying for DC Notice.");

        $cnt = 0;
        $results = [
            'success' => 0, 
            'failed' => 0, 
            'errors' => [],
            'target_due_date' => $targetDue,
            'found_invoices' => $invoices->count()
        ];

        foreach ($invoices as $inv) {
            // Skip accounts that are already Pullout or Disconnected
            $billingAccount = \App\Models\BillingAccount::where('account_no', $inv->account_no)->first();
            if ($billingAccount) {
                $statusName = $billingAccount->billingStatus ? $billingAccount->billingStatus->status_name : null;
                if (in_array($statusName, ['Pullout', 'Disconnected', 'Pullout Restricted'])) {
                    $this->log('info', "   Skipping Inv: {$inv->id} (Account status is {$statusName} - no DC notice)");
                    continue;
                }
            }

            if (!$force) {
                // Check if DC Notice record exists
                $exists = DCNotice::where('invoice_id', $inv->id)->exists();
                if ($exists) {
                    $this->log('info', "   Skipping Inv: {$inv->id} (DC notice already sent)");
                    continue;
                }
            }

            $this->log('info', "   Processing DC Notice for Inv: {$inv->id}");

            try {
                $systemUserId = $userId;

                // Use Notification Service
                $notificationResult = $this->notificationService->notifyDcNotice($inv);

                $pdfUrl = $notificationResult['pdf_url'] ?? null;
                
                if (!$inv->account_no) {
                     throw new \Exception("Invoice {$inv->id} has no account_no");
                }

                // Resolve account_id from billing_accounts using account_no
                $billingAccount = \App\Models\BillingAccount::where('account_no', $inv->account_no)->first();
                $accountId = $billingAccount ? $billingAccount->id : $inv->account_id;

                // Insert into DC Notice table
                DCNotice::create([
                    'account_id' => $accountId,
                    'account_no' => $inv->account_no,
                    'invoice_id' => $inv->id,
                    'dc_notice_date' => now(),
                    'print_link' => $pdfUrl,
                    'created_by_user_id' => $systemUserId,
                    'updated_by_user_id' => $systemUserId
                ]);

                $cnt++;
                $results['success']++;

            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = "Error processing invoice {$inv->id}: " . $e->getMessage();
                $this->log('error', "ERROR in DC Notice {$inv->account_no}: " . $e->getMessage());
            }
        }

        return $results;
    }
}





