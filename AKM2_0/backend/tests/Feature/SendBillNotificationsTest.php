<?php

namespace Tests\Feature;

use App\Services\BillingNotificationService;
use App\Services\EmailQueueService;
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
 * billing:send-bill-notifications sends each account's latest bill again: the SOA SMS text
 * and the SOA_TEMPLATE email, for Active accounts on one billing day.
 *
 * Messages are only queued here; the SMS sender and Google Drive are mocked. Runs on an
 * in-memory SQLite database: phpunit.xml does not override DB_CONNECTION, so without the
 * override below a database test would reach whatever .env points at.
 */
class SendBillNotificationsTest extends TestCase
{
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

        Schema::create('billing_accounts', function (Blueprint $t) {
            $t->id();
            $t->string('account_no');
            $t->unsignedBigInteger('customer_id');
            $t->unsignedBigInteger('plan_id')->nullable();
            $t->integer('billing_day')->nullable();
            $t->unsignedBigInteger('billing_status_id')->default(1);
            $t->decimal('account_balance', 10, 2)->default(0);
            $t->dateTime('date_installed')->nullable();
            $t->timestamps();
        });
        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->string('first_name')->nullable();
            $t->string('middle_initial')->nullable();
            $t->string('last_name')->nullable();
            $t->string('email_address')->nullable();
            $t->string('contact_number_primary')->nullable();
            $t->string('desired_plan')->nullable();
            $t->string('address')->nullable();
        });
        Schema::create('plan_list', function (Blueprint $t) {
            $t->id();
            $t->string('plan_name');
            $t->decimal('price', 10, 2)->default(0);
        });
        Schema::create('statement_of_accounts', function (Blueprint $t) {
            $t->id();
            $t->string('account_no');
            $t->dateTime('statement_date')->nullable();
            $t->dateTime('due_date')->nullable();
            $t->decimal('amount_due', 10, 2)->default(0);
            $t->decimal('total_amount_due', 10, 2)->default(0);
            $t->decimal('balance_from_previous_bill', 10, 2)->default(0);
            $t->decimal('payment_received_previous', 10, 2)->default(0);
            $t->decimal('remaining_balance_previous', 10, 2)->default(0);
            $t->string('print_link')->nullable();
        });
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->string('account_no');
            $t->dateTime('invoice_date')->nullable();
            $t->dateTime('due_date')->nullable();
            $t->decimal('invoice_balance', 10, 2)->default(0);
            $t->decimal('total_amount', 10, 2)->default(0);
            $t->decimal('received_payment', 10, 2)->default(0);
        });
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
            $t->integer('disconnection_day')->nullable();
        });
        // Read by ItexmoSmsService's constructor when artisan builds its commands.
        Schema::create('sms_config', function (Blueprint $t) {
            $t->id();
            $t->string('code')->nullable();
        });

        DB::table('billing_config')->insert(['disconnection_day' => 10]);
        DB::table('sms_templates')->insert([
            'template_type' => 'StatementofAccount', 'is_active' => 1,
            'message_content' => 'Hi {{customer_name}}, your bill for {{soa_date}} is now available. Amount Due: {{amount}}. Due Date: {{due_date}}.',
        ]);
        DB::table('email_templates')->insert([
            'Template_Code' => 'SOA_TEMPLATE', 'Subject_Line' => 'Statement of Account {{account_no}}',
            'email_body' => '<p>Amount due {{Amount_Due}}, due {{Due_Date}}</p>', 'Is_Active' => 1,
        ]);

        $fresh = Carbon::now('Asia/Manila')->subDays(2)->startOfDay();
        // Last month's bill: an account not billed this cycle must not get it sent again.
        $stale = Carbon::now('Asia/Manila')->subDays(32)->startOfDay();
        // [account, billing day, status, balance, statement date]
        foreach ([
            ['1501', 15, 1, 800.00, $fresh],  // unpaid, current bill
            ['1502', 15, 1, 0.00, $fresh],    // paid, current bill
            ['1503', 15, 1, 800.00, $stale],  // only last month's statement
            ['1204', 12, 1, 800.00, $fresh],  // other billing day
            ['1505', 15, 4, 800.00, $fresh],  // not Active
        ] as $i => [$accountNo, $day, $status, $balance, $statementDate]) {
            $customerId = $i + 1;
            DB::table('customers')->insert([
                'id' => $customerId, 'first_name' => 'Customer', 'last_name' => $accountNo,
                'email_address' => "c{$accountNo}@example.com", 'contact_number_primary' => '0917000' . $accountNo,
                'desired_plan' => '100Mbps - P800.00',
            ]);
            DB::table('billing_accounts')->insert([
                'account_no' => $accountNo, 'customer_id' => $customerId, 'billing_day' => $day,
                'billing_status_id' => $status, 'account_balance' => $balance, 'date_installed' => '2026-01-05',
            ]);
            DB::table('statement_of_accounts')->insert([
                'account_no' => $accountNo, 'statement_date' => $statementDate, 'due_date' => $statementDate->copy()->addDay(),
                'amount_due' => 800, 'total_amount_due' => $balance, 'print_link' => 'https://drive.google.com/file/d/pdf' . $accountNo . '/view',
            ]);
            DB::table('invoices')->insert([
                'account_no' => $accountNo, 'invoice_date' => $statementDate, 'due_date' => $statementDate->copy()->addDay(),
                'invoice_balance' => 800, 'total_amount' => 800,
            ]);
        }

        $sms = Mockery::mock(ItexmoSmsService::class);
        $sms->shouldNotReceive('send'); // bill texts are queued, never sent from here
        $drive = Mockery::mock(GoogleDrivePdfGenerationService::class);
        $drive->shouldReceive('downloadPdfFromGoogleDrive')->andReturn(null);

        $this->app->instance(BillingNotificationService::class, new BillingNotificationService(
            new EmailQueueService(new ResendEmailService()),
            new SmsQueueService($sms),
            $sms,
            $drive
        ));
    }

    private function queued(string $table): array
    {
        return DB::table($table)->orderBy('account_no')->pluck('account_no')->all();
    }

    public function test_a_dry_run_sends_nothing(): void
    {
        $this->artisan('billing:send-bill-notifications', ['--billing-day' => 15, '--dry-run' => true])
            ->expectsOutputToContain('Billing day 15: 2 bill(s) to send, 1 account(s) skipped.')
            ->expectsOutputToContain('Dry run: nothing was sent.')
            ->assertExitCode(0);

        $this->assertSame([], $this->queued('sms_queue'));
        $this->assertSame([], $this->queued('email_queue'));
    }

    public function test_it_sends_the_soa_sms_and_soa_template_email_for_the_billing_day(): void
    {
        $this->artisan('billing:send-bill-notifications', ['--billing-day' => 15, '--force' => true])
            ->expectsOutputToContain('Queued 2 SMS and 2 email(s).')
            ->assertExitCode(0);

        // Active billing-day-15 accounts with a current statement; not the old statement, the
        // other billing day, or the inactive account.
        $this->assertSame(['1501', '1502'], $this->queued('sms_queue'));
        $this->assertSame(['1501', '1502'], $this->queued('email_queue'));

        $sms = DB::table('sms_queue')->where('account_no', '1501')->first();
        $this->assertStringStartsWith('Hi Customer 1501, your bill for', $sms->message);
        $this->assertStringContainsString('Amount Due: 800.00', $sms->message);
        $this->assertSame('pending', $sms->status);
        $this->assertLessThanOrEqual(Carbon::now('Asia/Manila')->format('Y-m-d H:i:s'), Carbon::parse($sms->time_sent)->format('Y-m-d H:i:s'));

        $email = DB::table('email_queue')->where('account_no', '1501')->first();
        $this->assertSame('Statement of Account 1501', $email->subject);
        $this->assertSame('c1501@example.com', $email->recipient_email);
    }

    public function test_it_can_be_limited_to_named_accounts(): void
    {
        $this->artisan('billing:send-bill-notifications', ['--billing-day' => 15, '--account' => ['1502'], '--force' => true])
            ->assertExitCode(0);

        $this->assertSame(['1502'], $this->queued('sms_queue'));
        $this->assertSame(['1502'], $this->queued('email_queue'));
    }

    public function test_unpaid_only_skips_settled_accounts(): void
    {
        $this->artisan('billing:send-bill-notifications', ['--billing-day' => 15, '--unpaid-only' => true, '--force' => true])
            ->assertExitCode(0);

        $this->assertSame(['1501'], $this->queued('sms_queue'));
    }

    public function test_declining_the_confirmation_sends_nothing(): void
    {
        $this->artisan('billing:send-bill-notifications', ['--billing-day' => 15])
            ->expectsConfirmation('Send the bill SMS and email to these 2 customer(s)?', 'no')
            ->expectsOutputToContain('Cancelled: nothing was sent.')
            ->assertExitCode(0);

        $this->assertSame([], $this->queued('sms_queue'));
    }

    public function test_it_refuses_to_run_when_the_soa_sms_template_is_off(): void
    {
        DB::table('sms_templates')->update(['is_active' => 0]);

        $this->artisan('billing:send-bill-notifications', ['--billing-day' => 15, '--force' => true])
            ->expectsOutputToContain('The SOA SMS template (sms_templates, type StatementofAccount) is missing or inactive.')
            ->assertExitCode(1);

        $this->assertSame([], $this->queued('sms_queue'));
        $this->assertSame([], $this->queued('email_queue'));
    }

    public function test_it_refuses_to_run_when_the_soa_email_template_is_off(): void
    {
        DB::table('email_templates')->update(['Is_Active' => 0]);

        $this->artisan('billing:send-bill-notifications', ['--billing-day' => 15, '--force' => true])
            ->expectsOutputToContain('The SOA_TEMPLATE email template is missing or inactive.')
            ->assertExitCode(1);

        $this->assertSame([], $this->queued('email_queue'));
    }
}
