<?php

namespace App\Console\Commands;

use App\Models\BillingAccount;
use App\Models\EmailTemplate;
use App\Models\Invoice;
use App\Models\StatementOfAccount;
use App\Services\BillingNotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Send the bill SMS and email of every account on a billing day, on demand.
 *
 * For each Active account on the billing day it takes the latest statement of account
 * (and the invoice raised with it) and sends the same notification the nightly billing
 * run sends: the "SOA SMS" text (sms_templates, type StatementofAccount) and the
 * SOA_TEMPLATE email with the statement PDF. Both go through the SMS and email queues,
 * which the every-minute queue job sends, so a failed text is retried like any other.
 *
 * Run it on the server: the PDF attached to the email is downloaded to a temporary file
 * on the machine running this command, and the server's queue job is what sends it.
 *
 *   php artisan billing:send-bill-notifications --billing-day=15 --dry-run
 *   php artisan billing:send-bill-notifications --billing-day=15 --account=1183
 *   php artisan billing:send-bill-notifications --billing-day=15
 *
 * Every run sends again: running it twice sends each customer two of each.
 */
class SendBillNotifications extends Command
{
    protected $signature = 'billing:send-bill-notifications
        {--billing-day=15 : Billing day whose accounts get their bill SMS and email}
        {--account=* : Only these account numbers (repeat the option for several)}
        {--unpaid-only : Skip accounts whose balance is already settled}
        {--dry-run : List who would get what, send nothing}
        {--force : Send without asking for confirmation}';

    protected $description = 'Send the bill SMS (SOA SMS) and email (SOA_TEMPLATE) of the latest statement for every account on a billing day';

    /**
     * A statement older than this is not the current bill and is not sent. Bills come out
     * monthly, so the current one is never older than 31 days and last month's always is.
     */
    private const MAX_STATEMENT_AGE_DAYS = 31;

    public function handle(BillingNotificationService $notifications): int
    {
        $billingDay = (int) $this->option('billing-day');
        if ($billingDay < 0 || $billingDay > 31) {
            $this->error("Billing day must be 0 (end of month) to 31, got {$billingDay}.");
            return self::FAILURE;
        }

        // This command sends these two templates. If either is off, stop rather than let
        // the notification service fall back to its built-in wording.
        $emailCode = config('billing.templates.soa_email', 'SOA_TEMPLATE');
        $smsActive = DB::table('sms_templates')->where('template_type', 'StatementofAccount')->where('is_active', 1)->exists();
        $emailActive = EmailTemplate::where('Template_Code', $emailCode)->where('Is_Active', true)->exists();
        if (!$smsActive || !$emailActive) {
            if (!$smsActive) {
                $this->error('The SOA SMS template (sms_templates, type StatementofAccount) is missing or inactive.');
            }
            if (!$emailActive) {
                $this->error("The {$emailCode} email template is missing or inactive.");
            }
            $this->error('Nothing was sent. Activate the template(s) and run it again.');
            return self::FAILURE;
        }

        $accounts = BillingAccount::with(['customer', 'plan'])
            ->where('billing_status_id', 1)
            ->where('billing_day', $billingDay)
            ->when($this->option('account'), fn ($query, $accountNos) => $query->whereIn('account_no', $accountNos))
            ->orderBy('account_no')
            ->get();

        $oldestCurrent = Carbon::now('Asia/Manila')->subDays(self::MAX_STATEMENT_AGE_DAYS)->startOfDay();
        $bills = [];
        $skipped = [];

        foreach ($accounts as $account) {
            $customer = $account->customer;
            if (!$customer) {
                $skipped[] = [$account->account_no, 'no customer record'];
                continue;
            }
            if (empty($customer->contact_number_primary) && empty($customer->email_address)) {
                $skipped[] = [$account->account_no, 'no phone number or email'];
                continue;
            }
            if ($this->option('unpaid-only') && (float) $account->account_balance <= 0.01) {
                $skipped[] = [$account->account_no, 'balance already settled'];
                continue;
            }

            $soa = StatementOfAccount::where('account_no', $account->account_no)
                ->orderByDesc('statement_date')
                ->orderByDesc('id')
                ->first();
            if (!$soa || !$soa->statement_date || $soa->statement_date->lt($oldestCurrent)) {
                $skipped[] = [$account->account_no, 'no statement in the last ' . self::MAX_STATEMENT_AGE_DAYS . ' days'];
                continue;
            }

            // The invoice raised in the same run as the statement.
            $invoice = Invoice::where('account_no', $account->account_no)
                ->whereDate('invoice_date', $soa->statement_date->format('Y-m-d'))
                ->orderByDesc('id')
                ->first();

            $bills[] = ['account' => $account, 'soa' => $soa, 'invoice' => $invoice];
        }

        $this->info("Billing day {$billingDay}: " . count($bills) . ' bill(s) to send, ' . count($skipped) . ' account(s) skipped.');
        $this->line("SMS template: SOA SMS (StatementofAccount) | Email template: {$emailCode}");

        if ($bills) {
            $this->table(
                ['Account', 'Statement', 'Amount due', 'Due date', 'SMS to', 'Email to'],
                array_map(fn ($bill) => [
                    $bill['account']->account_no,
                    $bill['soa']->statement_date->format('Y-m-d'),
                    number_format((float) $bill['soa']->amount_due, 2),
                    $bill['soa']->due_date ? $bill['soa']->due_date->format('Y-m-d') : '-',
                    $bill['account']->customer->contact_number_primary ?: '-',
                    $bill['account']->customer->email_address ?: '-',
                ], $bills)
            );
        }
        if ($skipped) {
            $this->warn('Skipped:');
            $this->table(['Account', 'Reason'], $skipped);
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing was sent.');
            return self::SUCCESS;
        }
        if (!$bills) {
            return self::SUCCESS;
        }
        if (!$this->option('force') && !$this->confirm('Send the bill SMS and email to these ' . count($bills) . ' customer(s)?')) {
            $this->info('Cancelled: nothing was sent.');
            return self::SUCCESS;
        }

        // Due now: the every-minute queue job picks them up on its next run.
        $sendAt = Carbon::now('Asia/Manila')->format('Y-m-d H:i:s');
        $smsQueued = $emailsQueued = 0;
        $problems = [];

        $bar = $this->output->createProgressBar(count($bills));
        foreach ($bills as $bill) {
            $result = $notifications->notifyBillingGenerated($bill['account'], $bill['invoice'], $bill['soa'], $sendAt);
            $smsQueued += $result['sms_sent'] ? 1 : 0;
            $emailsQueued += $result['email_queued'] ? 1 : 0;
            if (!empty($result['errors'])) {
                $problems[] = [$bill['account']->account_no, implode('; ', $result['errors'])];
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);

        $this->info("Queued {$smsQueued} SMS and {$emailsQueued} email(s). The queue job sends them within the next few minutes.");
        if ($problems) {
            $this->warn('Some accounts reported problems:');
            $this->table(['Account', 'Problem'], $problems);
        }

        return self::SUCCESS;
    }
}
