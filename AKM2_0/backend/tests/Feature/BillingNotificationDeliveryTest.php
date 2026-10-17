<?php

namespace Tests\Feature;

use App\Models\BillingAccount;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\BillingNotificationService;
use App\Services\EmailQueueService;
use App\Services\EnhancedBillingGenerationServiceWithNotifications;
use App\Services\GoogleDrivePdfGenerationService;
use App\Services\ItexmoSmsService;
use App\Services\ResendEmailService;
use App\Services\SmsQueueService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Monolog\Handler\NullHandler;
use Tests\TestCase;

/**
 * Every bill sends its SMS and email, and overdue / DC notices go out every day.
 *
 *   - A missing or inactive SOA SMS template used to skip the bill SMS without a trace
 *     (bills of Sep 19 - Oct 4 2026 got no text); the built-in text is sent instead.
 *   - A missing or inactive email template now sends a plain bill email instead of none.
 *   - A failed SMS is retried, like a failed email already was.
 *   - Overdue / DC notices used to run only after a billing run, and the days they fall on
 *     have no bills, so none went out after Sep 2 2026.
 *
 * Messages are only queued here; nothing is sent. Runs on an in-memory SQLite database:
 * phpunit.xml does not override DB_CONNECTION, so without the override below a database
 * test would reach whatever .env points at.
 */
class BillingNotificationDeliveryTest extends TestCase
{
    private const SOA_SMS = 'Hi {{customer_name}}, your bill for {{soa_date}} is now available. Amount Due: {{amount}}. Due Date: {{due_date}}.';

    public function createApplication()
    {
        foreach (['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:'] as $key => $value) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        $app = parent::createApplication();
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        foreach (['stack', 'single', 'billing'] as $channel) {
            $app['config']->set("logging.channels.$channel", ['driver' => 'monolog', 'handler' => NullHandler::class]);
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->fail('Refusing to run: the test database is not SQLite.');
        }

        Event::fake();
        Http::preventStrayRequests();

        Schema::create('sms_templates', function (Blueprint $t) {
            $t->id();
            $t->string('template_type');
            $t->text('message_content')->nullable();
            $t->boolean('is_active')->default(true);
        });
        Schema::create('sms_queue', function (Blueprint $t) {
            $t->id();
            $t->string('account_no')->nullable();
            $t->string('contact_no');
            $t->text('message');
            $t->string('status')->default('pending');
            $t->dateTime('sent_at')->nullable();
            $t->dateTime('time_sent')->nullable();
            $t->integer('attempts')->default(0);
            $t->text('error_message')->nullable();
            $t->timestamps();
        });
        Schema::create('email_queue', function (Blueprint $t) {
            $t->id();
            $t->string('account_no')->nullable();
            $t->string('recipient_email');
            $t->text('cc')->nullable();
            $t->text('bcc')->nullable();
            $t->string('subject')->nullable();
            $t->longText('body_html')->nullable();
            $t->string('attachment_path')->nullable();
            $t->string('status')->default('pending');
            $t->dateTime('sent_at')->nullable();
            $t->integer('attempts')->default(0);
            $t->text('error_message')->nullable();
            $t->string('email_sender')->nullable();
            $t->string('reply_to')->nullable();
            $t->string('sender_name')->nullable();
            $t->dateTime('time_sent')->nullable();
            $t->timestamps();
        });
        Schema::create('email_templates', function (Blueprint $t) {
            $t->string('Template_Code')->primary();
            $t->string('Subject_Line')->nullable();
            $t->longText('email_body')->nullable();
            $t->longText('Body_HTML')->nullable();
            $t->boolean('Is_Active')->default(true);
            $t->string('cc')->nullable();
            $t->string('bcc')->nullable();
            $t->string('email_sender')->nullable();
            $t->string('reply_to')->nullable();
            $t->string('sender_name')->nullable();
        });
        Schema::create('form_ui', function (Blueprint $t) {
            $t->id();
            $t->string('brand_name')->nullable();
        });
        Schema::create('billing_config', function (Blueprint $t) {
            $t->id();
            $t->integer('advance_generation_day')->nullable();
            $t->integer('due_date_day')->nullable();
            $t->integer('disconnection_day')->nullable();
            $t->timestamps();
        });
        Schema::create('statement_of_accounts', function (Blueprint $t) {
            $t->id();
            $t->string('account_no');
            $t->dateTime('statement_date')->nullable();
        });
        // Read by ItexmoSmsService's constructor when artisan builds its commands.
        Schema::create('sms_config', function (Blueprint $t) {
            $t->id();
            $t->string('code')->nullable();
            $t->string('sender')->nullable();
            $t->string('provider')->nullable();
        });
        Schema::create('billing_accounts', function (Blueprint $t) {
            $t->id();
            $t->string('account_no')->nullable();
            $t->unsignedBigInteger('billing_status_id')->default(1);
            $t->integer('billing_day')->nullable();
            $t->dateTime('date_installed')->nullable();
            $t->timestamps();
        });

        DB::table('billing_config')->insert(['advance_generation_day' => 1, 'due_date_day' => 0, 'disconnection_day' => 10]);
        DB::table('sms_templates')->insert([
            ['template_type' => 'StatementofAccount', 'message_content' => self::SOA_SMS, 'is_active' => 1],
            ['template_type' => 'Overdue', 'message_content' => 'Hi {{customer_name}}, your bill of {{amount}} was due {{due_date}}.', 'is_active' => 1],
        ]);
        // config/billing.php sends both SOA and invoice bills with SOA_TEMPLATE.
        DB::table('email_templates')->insert([
            'Template_Code' => 'SOA_TEMPLATE', 'Subject_Line' => 'Your bill, account {{account_no}}',
            'email_body' => '<p>Amount due {{Amount_Due}}</p>', 'Is_Active' => 1,
        ]);
    }

    private function account(?string $email = 'juan@example.com'): BillingAccount
    {
        $customer = (new Customer())->forceFill([
            'id' => 1, 'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'email_address' => $email,
            'contact_number_primary' => '09171234567', 'desired_plan' => '100Mbps - P800.00', 'address' => 'Brgy 1',
        ]);
        $account = (new BillingAccount())->forceFill(['id' => 1, 'account_no' => '1183', 'date_installed' => '2026-01-05']);
        $account->setRelation('customer', $customer);
        $account->setRelation('plan', null);

        return $account;
    }

    private function invoice(BillingAccount $account): Invoice
    {
        $invoice = (new Invoice())->forceFill([
            'id' => 10, 'account_no' => '1183', 'invoice_date' => '2026-10-04', 'due_date' => '2026-10-05',
            'invoice_balance' => 800, 'total_amount' => 800, 'received_payment' => 0,
        ]);
        $invoice->setRelation('billingAccount', $account);

        return $invoice;
    }

    private function service(?ItexmoSmsService $sms = null): BillingNotificationService
    {
        $sms = $sms ?? Mockery::mock(ItexmoSmsService::class);

        return new BillingNotificationService(
            new EmailQueueService(new ResendEmailService()),
            new SmsQueueService($sms),
            $sms,
            Mockery::mock(GoogleDrivePdfGenerationService::class)
        );
    }

    private function billNow(?string $email = 'juan@example.com'): array
    {
        $account = $this->account($email);

        return $this->service()->notifyBillingGenerated($account, $this->invoice($account), null, '2026-10-04 08:00:00');
    }

    // ---- Bill SMS and email -------------------------------------------------------------

    public function test_a_bill_queues_its_sms_and_its_email(): void
    {
        $result = $this->billNow();

        $this->assertTrue($result['sms_sent']);
        $this->assertTrue($result['email_queued']);

        $sms = DB::table('sms_queue')->first();
        $this->assertSame('pending', $sms->status);
        $this->assertSame('09171234567', $sms->contact_no);
        $this->assertStringContainsString('Juan Dela Cruz', $sms->message);
        $this->assertStringContainsString('800.00', $sms->message);
        $this->assertStringContainsString('Oct 05, 2026', $sms->message);

        $email = DB::table('email_queue')->first();
        $this->assertSame('juan@example.com', $email->recipient_email);
        $this->assertSame('Your bill, account 1183', $email->subject);
    }

    public function test_an_inactive_sms_template_still_sends_the_bill_sms(): void
    {
        DB::table('sms_templates')->where('template_type', 'StatementofAccount')->update(['is_active' => 0]);

        $this->billNow();

        $sms = DB::table('sms_queue')->first();
        $this->assertNotNull($sms, 'The bill SMS was skipped');
        $this->assertStringContainsString('your bill for', $sms->message);
        $this->assertStringContainsString('800.00', $sms->message);
        $this->assertStringContainsString('Oct 05, 2026', $sms->message);
    }

    public function test_a_missing_sms_template_still_sends_the_bill_sms(): void
    {
        DB::table('sms_templates')->where('template_type', 'StatementofAccount')->delete();

        $this->billNow();

        $this->assertSame(1, DB::table('sms_queue')->count(), 'The bill SMS was skipped');
    }

    public function test_a_missing_email_template_still_sends_the_bill_email(): void
    {
        DB::table('email_templates')->delete();

        $result = $this->billNow();

        $this->assertTrue($result['email_queued']);
        $email = DB::table('email_queue')->first();
        $this->assertNotNull($email, 'The bill email was skipped');
        $this->assertSame('Billing Invoice - Account 1183', $email->subject);
        $this->assertStringContainsString('800.00', $email->body_html);
        $this->assertStringContainsString('Juan Dela Cruz', $email->body_html);
        $this->assertSame('2026-10-04 08:00:00', Carbon::parse($email->time_sent)->format('Y-m-d H:i:s'));
    }

    // ---- Overdue notice SMS without an email address ------------------------------------

    public function test_an_overdue_notice_texts_a_customer_with_no_email(): void
    {
        $account = $this->account(null);

        $result = $this->service()->notifyOverdue($this->invoice($account));

        $this->assertTrue($result['sms_sent']);
        $sms = DB::table('sms_queue')->first();
        $this->assertNotNull($sms, 'The overdue SMS was skipped for a customer without email');
        $this->assertStringContainsString('was due Oct 05, 2026', $sms->message);
        $this->assertSame(0, DB::table('email_queue')->count());
    }

    // ---- Failed SMS retry ----------------------------------------------------------------

    public function test_a_failed_sms_is_retried_up_to_three_times_and_old_ones_are_left_alone(): void
    {
        $now = Carbon::now();
        $row = fn (array $over) => array_merge([
            'account_no' => '1183', 'contact_no' => '09171234567', 'message' => 'bill', 'status' => 'failed',
            'attempts' => 1, 'error_message' => 'provider timeout',
            'created_at' => $now->copy()->subHour(), 'updated_at' => $now->copy()->subMinutes(10),
        ], $over);
        DB::table('sms_queue')->insert([
            $row(['message' => 'due for retry']),
            $row(['message' => 'out of attempts', 'attempts' => 3]),
            $row(['message' => 'failed a minute ago', 'updated_at' => $now->copy()->subMinute()]),
            $row(['message' => 'two days old', 'created_at' => $now->copy()->subDays(2)]),
        ]);

        $sms = Mockery::mock(ItexmoSmsService::class);
        $sms->shouldReceive('send')->once()
            ->with(Mockery::on(fn ($data) => $data['message'] === 'due for retry'))
            ->andReturn(['success' => true]);

        $stats = (new SmsQueueService($sms))->retryFailedSms();

        $this->assertSame(['processed' => 1, 'sent' => 1, 'failed' => 0], $stats);
        $this->assertSame('sent', DB::table('sms_queue')->where('message', 'due for retry')->value('status'));
        $this->assertSame(3, DB::table('sms_queue')->where('status', 'failed')->count());
    }

    // ---- Daily billing job: notices run every day ----------------------------------------

    private function billingServiceExpecting(bool $billsToday): void
    {
        $billing = Mockery::mock(EnhancedBillingGenerationServiceWithNotifications::class);
        $noNotices = ['success' => 0, 'failed' => 0, 'errors' => []];
        $billing->shouldReceive('generateOverdueNotices')->once()->andReturn($noNotices);
        $billing->shouldReceive('generateDCNotices')->once()->andReturn($noNotices);

        if ($billsToday) {
            $billing->shouldReceive('generateUnifiedBilling')->once()->andReturn([
                'statements' => ['success' => 1, 'failed' => 0, 'skipped' => 0],
                'invoices' => ['success' => 1, 'failed' => 0, 'skipped' => 0],
                'notifications' => [['account_no' => '1183']],
            ]);
        } else {
            $billing->shouldNotReceive('generateUnifiedBilling');
        }

        $this->app->instance(EnhancedBillingGenerationServiceWithNotifications::class, $billing);
    }

    public function test_notices_still_run_on_a_day_with_no_bills(): void
    {
        $this->billingServiceExpecting(false);

        $this->artisan('cron:generate-daily-billings')->assertExitCode(0);
    }

    public function test_bills_and_notices_both_run_on_a_billing_day(): void
    {
        DB::table('billing_accounts')->insert([
            'account_no' => '1183', 'billing_status_id' => 1, 'date_installed' => '2026-01-05',
            'billing_day' => Carbon::now()->addDay()->day,
        ]);
        $this->billingServiceExpecting(true);

        $this->artisan('cron:generate-daily-billings')->assertExitCode(0);
    }
}
