<?php

namespace Tests\Feature;

use App\Events\PaymentUpdated;
use App\Http\Controllers\BillingRecordsController;
use App\Http\Controllers\JobOrderController;
use App\Models\JobOrder;
use App\Models\RadiusConfig;
use App\Services\EmailQueueService;
use App\Services\InstallationAccessService;
use App\Services\InstallationFeeNotificationService;
use App\Services\ItexmoSmsService;
use App\Services\ManualRadiusOperationsService;
use App\Services\PaymentWorkerService;
use App\Services\RadiusServerResolver;
use App\Services\RouterosApiService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Monolog\Handler\NullHandler;
use Tests\TestCase;

/**
 * Onsite Done -> Restricted RADIUS account -> approval -> installation invoice -> portal
 * payment -> automatic activation.
 *
 * Runs on an in-memory SQLite database with every device, SMS, email, Drive and broadcast
 * dependency replaced: .env points at the production database and services, so nothing here
 * may reach them. Payments go through the real PaymentWorkerService and webhook controller.
 */
class InstallationAccessFlowTest extends TestCase
{
    private const PLAN = 'SWIFT - P1,299.00';
    private const PLAN_GROUP = 'SWIFT';
    private const USERNAME = 'delacruz09171234567';

    public function createApplication()
    {
        foreach (['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'BROADCAST_DRIVER' => 'null'] as $key => $value) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        $app = parent::createApplication();
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        $app['config']->set('broadcasting.default', 'null');
        $app['config']->set('services.xendit.api_key', 'test-key');
        $app['config']->set('services.xendit.callback_token', 'test-callback-token');
        foreach (['radiusrelated', 'billing', 'single', 'daily', 'stack'] as $channel) {
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

        Http::preventStrayRequests();
        Event::fake([PaymentUpdated::class]);

        // MySQL functions the payment worker's SQL uses.
        DB::connection()->getPdo()->sqliteCreateFunction('CONCAT', fn (...$parts) => implode('', $parts), -1);

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // =========================================================================
    // 1. Onsite Done: the RADIUS account is created restricted
    // =========================================================================

    public function test_onsite_done_creates_the_account_in_the_restricted_group(): void
    {
        $jobOrder = $this->makeJobOrder();
        $this->bindResolver();

        $api = $this->mockApi();
        $api->shouldReceive('addUser')->once()
            ->with(Mockery::any(), self::USERNAME, 'secret123', InstallationAccessService::RESTRICTED_GROUP)
            ->andReturn(true);
        $api->shouldReceive('findUser')->once()->andReturn($this->deviceUser(InstallationAccessService::RESTRICTED_GROUP));
        $api->shouldNotReceive('setUserGroup');

        $response = app(JobOrderController::class)->createRadiusAccount($jobOrder->id);
        $data = $response->getData(true)['data'];

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(InstallationAccessService::RESTRICTED_GROUP, $data['group']);
        $this->assertSame(self::PLAN_GROUP, $data['plan_group']);
        $this->assertSame('success', $data['radius_response']['status']);
    }

    public function test_an_account_already_on_the_device_is_moved_to_restricted_and_its_session_ended(): void
    {
        $jobOrder = $this->makeJobOrder();
        $this->bindResolver();

        $api = $this->mockApi();
        $api->shouldReceive('addUser')->once()->andReturn(true); // exists: addUser leaves it alone
        $api->shouldReceive('findUser')->once()->andReturn($this->deviceUser(self::PLAN_GROUP));
        $api->shouldReceive('setUserGroup')->once()->with(Mockery::any(), '*1A', InstallationAccessService::RESTRICTED_GROUP)->andReturn(true);
        $api->shouldReceive('killSessionsForUser')->once()->with(Mockery::any(), self::USERNAME)->andReturn(1);

        $response = app(JobOrderController::class)->createRadiusAccount($jobOrder->id);

        $this->assertSame('success', $response->getData(true)['data']['radius_response']['status']);
    }

    public function test_an_unreachable_device_queues_one_create_pinned_to_the_barangay_server(): void
    {
        $jobOrder = $this->makeJobOrder();
        $config = $this->bindResolver();

        $api = $this->mockApi();
        $api->shouldReceive('addUser')->andReturn(false);
        $api->shouldReceive('getLastError')->andReturn('Connection timed out');
        $api->shouldReceive('isConnected')->andReturn(false);

        // The web form calls create-radius-account again after the Done save.
        app(JobOrderController::class)->createRadiusAccount($jobOrder->id);
        $response = app(JobOrderController::class)->createRadiusAccount($jobOrder->id);

        $this->assertSame('queued', $response->getData(true)['data']['radius_response']['status']);

        $rows = DB::table('radius_operation_queue')->get();
        $this->assertCount(1, $rows, 'A second call must not queue a second create.');
        $params = json_decode($rows[0]->params, true);
        $this->assertSame('create_user', $rows[0]->operation);
        $this->assertSame($jobOrder->id, $params['job_order_id']);
        $this->assertSame($config->id, $params['radius_config_id']);
    }

    public function test_a_queued_create_decides_the_group_when_it_runs(): void
    {
        $config = $this->bindResolver();
        $service = app(InstallationAccessService::class);

        // Still awaiting approval: restricted.
        $jobOrder = $this->makeJobOrder();
        $api = $this->mockApi();
        $api->shouldReceive('addUser')->once()->with(Mockery::any(), self::USERNAME, 'secret123', InstallationAccessService::RESTRICTED_GROUP)->andReturn(true);
        $api->shouldReceive('findUser')->andReturn($this->deviceUser(InstallationAccessService::RESTRICTED_GROUP));
        $this->assertTrue($service->runQueuedCreate($api, $this->queuedParams($jobOrder, $config)));

        // Approved and paid before the retry ran: straight to the plan group.
        $paid = $this->makeJobOrder(['account_id' => $this->makeAccount(0)]);
        $api = $this->mockApi();
        $api->shouldReceive('addUser')->once()->with(Mockery::any(), self::USERNAME, 'secret123', self::PLAN_GROUP)->andReturn(true);
        $api->shouldReceive('findUser')->andReturn($this->deviceUser(self::PLAN_GROUP));
        $this->assertTrue($service->runQueuedCreate($api, $this->queuedParams($paid, $config)));

        // Re-opened since: nothing to create, and the item stops retrying.
        $reopened = $this->makeJobOrder(['onsite_status' => 'In Progress']);
        $api = $this->mockApi();
        $api->shouldNotReceive('addUser');
        $this->assertTrue($service->runQueuedCreate($api, $this->queuedParams($reopened, $config)));
    }

    public function test_access_stays_restricted_until_the_balance_is_settled(): void
    {
        $service = app(InstallationAccessService::class);

        $this->assertSame('Restricted', $service->groupFor($this->makeJobOrder(), self::PLAN_GROUP), 'awaiting approval');
        $this->assertSame('Restricted', $service->groupFor($this->makeJobOrder(['account_id' => $this->makeAccount(1500)]), self::PLAN_GROUP), 'fee unpaid');
        $this->assertSame('Restricted', $service->groupFor($this->makeJobOrder(['account_id' => 999]), self::PLAN_GROUP), 'billing account missing');
        $this->assertSame(self::PLAN_GROUP, $service->groupFor($this->makeJobOrder(['account_id' => $this->makeAccount(0)]), self::PLAN_GROUP), 'fee paid');
    }

    // =========================================================================
    // 2. Approval: access and the installation invoice
    // =========================================================================

    public function test_approval_with_a_fee_due_keeps_the_account_restricted(): void
    {
        $accountId = $this->makeAccount(1500);
        $jobOrder = $this->makeJobOrder(['account_id' => $accountId]);
        $this->bindResolver();

        $radius = Mockery::mock(ManualRadiusOperationsService::class);
        $radius->shouldNotReceive('reconnectUser');
        $this->app->instance(ManualRadiusOperationsService::class, $radius);

        $api = $this->mockApi();
        $api->shouldReceive('findUser')->once()->andReturn($this->deviceUser(InstallationAccessService::RESTRICTED_GROUP));

        $result = app(InstallationAccessService::class)->applyAfterApproval(
            $jobOrder, DB::table('billing_accounts')->find($accountId), self::USERNAME, self::PLAN, 'Poblacion', 'Tanay'
        );

        $this->assertSame(['action' => 'restrict', 'success' => true], array_intersect_key($result, ['action' => 1, 'success' => 1]));
    }

    public function test_approval_with_no_fee_activates_and_a_failed_activation_is_queued_once(): void
    {
        $accountId = $this->makeAccount(0);
        $jobOrder = $this->makeJobOrder(['account_id' => $accountId, 'installation_fee' => 0]);
        $account = DB::table('billing_accounts')->find($accountId);

        $radius = Mockery::mock(ManualRadiusOperationsService::class);
        $radius->shouldReceive('reconnectUser')->once()
            ->with(Mockery::on(fn ($p) => $p['username'] === self::USERNAME && $p['plan'] === self::PLAN))
            ->andReturn(['status' => 'success']);
        $this->app->instance(ManualRadiusOperationsService::class, $radius);

        $result = app(InstallationAccessService::class)->applyAfterApproval($jobOrder, $account, self::USERNAME, self::PLAN, 'Poblacion', 'Tanay');
        $this->assertTrue($result['success']);
        $this->assertSame(0, DB::table('radius_operation_queue')->count());

        // Device down: queued, and only once however often it is retried.
        $radius = Mockery::mock(ManualRadiusOperationsService::class);
        $radius->shouldReceive('reconnectUser')->twice()->andReturn(['status' => 'error', 'message' => 'Connection timed out']);
        $this->app->instance(ManualRadiusOperationsService::class, $radius);

        app(InstallationAccessService::class)->applyAfterApproval($jobOrder, $account, self::USERNAME, self::PLAN, 'Poblacion', 'Tanay');
        $result = app(InstallationAccessService::class)->applyAfterApproval($jobOrder, $account, self::USERNAME, self::PLAN, 'Poblacion', 'Tanay');

        $this->assertFalse($result['success']);
        $this->assertSame(1, DB::table('radius_operation_queue')->where('operation', 'reconnect_user')->where('status', 'pending')->count());
    }

    public function test_the_installation_invoice_is_due_on_the_installation_date(): void
    {
        $accountId = $this->makeAccount(1500, 'ACC-0042');
        $jobOrder = $this->makeJobOrder(['account_id' => $accountId, 'date_installed' => '2026-10-13 14:35:00']);

        $service = new InstallationFeeNotificationService(Mockery::mock(EmailQueueService::class), Mockery::mock(ItexmoSmsService::class));
        $prepare = new \ReflectionMethod($service, 'prepareData');
        $prepare->setAccessible(true);
        $data = $prepare->invoke($service, $jobOrder->fresh('application'), $jobOrder->application, 1500.0);

        $this->assertSame('October 13, 2026', $data['Invoice_Date']);
        $this->assertSame('October 13, 2026', $data['Due_Date']);
        $this->assertSame('Oct 13, 2026', $data['due_date']);
        $this->assertSame('ACC-0042', $data['Account_No']);

        // The staff Job Order Invoice list agrees.
        $records = app(BillingRecordsController::class)->getJobOrderInvoiceRecords(new Request())->getData(true)['data'];
        $this->assertSame('2026-10-13', $records[0]['due_date']);
    }

    public function test_the_installation_invoice_is_sent_only_once(): void
    {
        $jobOrder = $this->makeJobOrder(['invoice_url' => 'https://drive.example/INSTALLATION-JO1.pdf']);

        $email = Mockery::mock(EmailQueueService::class);
        $email->shouldNotReceive('queueFromTemplate');
        $sms = Mockery::mock(ItexmoSmsService::class);
        $sms->shouldNotReceive('send');

        $result = (new InstallationFeeNotificationService($email, $sms))->notify($jobOrder);

        $this->assertTrue($result['skipped']);
    }

    public function test_an_invoice_already_on_file_is_regenerated_with_the_new_due_date_without_notifying_the_customer(): void
    {
        $this->seedInvoiceTemplate();
        $jobOrder = $this->makeJobOrder([
            'account_id' => $this->makeAccount(1500), 'date_installed' => '2026-10-13 14:35:00',
            'invoice_url' => 'https://drive.example/old.pdf',
        ]);

        $drive = Mockery::mock(\App\Services\GoogleDriveService::class);
        $drive->shouldReceive('createFolder')->once()->andReturn('folder-1');
        $drive->shouldReceive('uploadFile')->once()
            ->with(Mockery::on(fn ($path) => is_file($path)), 'folder-1', "INSTALLATION-JO{$jobOrder->id}.pdf", 'application/pdf')
            ->andReturn('https://drive.example/new.pdf');
        $drive->shouldNotReceive('deleteFile');
        $this->app->instance(\App\Services\GoogleDriveService::class, $drive);

        $email = Mockery::mock(EmailQueueService::class);
        $email->shouldNotReceive('queueFromTemplate');
        $sms = Mockery::mock(ItexmoSmsService::class);
        $sms->shouldNotReceive('send');

        $result = (new InstallationFeeNotificationService($email, $sms))->regenerateInvoice($jobOrder);

        $this->assertTrue($result['success'], (string) $result['error']);
        $this->assertSame('October 13, 2026', $result['due_date']);
        $this->assertSame('https://drive.example/old.pdf', $result['old_url']);
        $this->assertSame('https://drive.example/new.pdf', DB::table('job_orders')->where('id', $jobOrder->id)->value('invoice_url'));
    }

    public function test_the_regenerate_command_dry_run_changes_nothing(): void
    {
        $jobOrder = $this->makeJobOrder(['date_installed' => '2026-10-09 12:22:46', 'invoice_url' => 'https://drive.example/old.pdf']);
        $this->makeJobOrder(['invoice_url' => null]); // no PDF on file: not listed

        $drive = Mockery::mock(\App\Services\GoogleDriveService::class);
        $drive->shouldNotReceive('uploadFile');
        $this->app->instance(\App\Services\GoogleDriveService::class, $drive);
        $this->app->instance(EmailQueueService::class, Mockery::mock(EmailQueueService::class));
        $this->app->instance(ItexmoSmsService::class, Mockery::mock(ItexmoSmsService::class));

        // Run on its own: booting the full console constructs every command, and some of
        // them read tables this test does not have.
        $command = app(\App\Console\Commands\RegenerateInstallationInvoices::class);
        $command->setLaravel($this->app);
        $tester = new \Symfony\Component\Console\Tester\CommandTester($command);

        $this->assertSame(0, $tester->execute(['--dry-run' => true]));
        $this->assertStringContainsString('1 installation invoice(s)', $tester->getDisplay());
        $this->assertStringContainsString("JO-{$jobOrder->id} | installed 2026-10-09 | due 2026-10-09", $tester->getDisplay());

        $this->assertSame('https://drive.example/old.pdf', DB::table('job_orders')->where('id', $jobOrder->id)->value('invoice_url'));
    }

    // =========================================================================
    // 3. Payment: verified payments activate, nothing else does
    // =========================================================================

    public function test_a_paid_installation_fee_activates_the_restricted_customer(): void
    {
        $accountId = $this->makeApprovedCustomer(1500);
        $paymentId = $this->makePayment('REF-PAID', 1500, 'QUEUED', ['status' => 'PAID', 'id' => 'inv-1']);

        $radius = Mockery::mock(ManualRadiusOperationsService::class);
        // Held in the Restricted group until the fee is paid, so the payment reconnects it.
        $radius->shouldReceive('currentGroup')->andReturn(InstallationAccessService::RESTRICTED_GROUP);
        $radius->shouldReceive('reconnectUser')->once()
            ->with(Mockery::on(fn ($p) => $p['username'] === self::USERNAME && $p['plan'] === self::PLAN))
            ->andReturn(['status' => 'success']);
        $worker = $this->workerWith($radius);

        $result = $worker->postPayment($paymentId);

        $this->assertSame('PAID', $result['status']);
        $this->assertEquals(0.0, (float) DB::table('billing_accounts')->where('id', $accountId)->value('account_balance'));
        $this->assertSame(1, DB::table('payment_portal_logs')->where('reference_no', 'REF-PAID')->count());
        $this->assertSame('success', DB::table('pending_payments')->where('id', $paymentId)->value('reconnect_status'));
    }

    public function test_failed_pending_and_unverified_payments_do_not_activate(): void
    {
        $accountId = $this->makeApprovedCustomer(1500);
        $failed = $this->makePayment('REF-FAILED', 1500, 'QUEUED', ['status' => 'FAILED']);
        $unverified = $this->makePayment('REF-NOPAYLOAD', 1500, 'QUEUED', null);
        $pending = $this->makePayment('REF-PENDING', 1500, 'PENDING', null);

        $radius = Mockery::mock(ManualRadiusOperationsService::class);
        $radius->shouldNotReceive('reconnectUser');
        $worker = $this->workerWith($radius);

        $worker->postPayment($failed);
        $worker->postPayment($unverified);
        $worker->processPayments(); // the PENDING row has no paid callback and is not picked up

        $this->assertSame('FAILED', DB::table('pending_payments')->where('id', $failed)->value('status'));
        $this->assertSame('FAILED', DB::table('pending_payments')->where('id', $unverified)->value('status'));
        $this->assertSame('PENDING', DB::table('pending_payments')->where('id', $pending)->value('status'));
        $this->assertEquals(1500.0, (float) DB::table('billing_accounts')->where('id', $accountId)->value('account_balance'));
        $this->assertSame(0, DB::table('payment_portal_logs')->count());
    }

    public function test_duplicate_and_out_of_order_callbacks_post_the_payment_once(): void
    {
        $accountId = $this->makeApprovedCustomer(1500);
        $paymentId = $this->makePayment('REF-DUP', 1500, 'PENDING', null);

        $this->webhook('REF-DUP', 'PAID')->assertOk();
        $this->webhook('REF-DUP', 'PAID')->assertOk();
        // A late EXPIRED must not strand a payment the gateway already confirmed.
        $this->webhook('REF-DUP', 'EXPIRED')->assertOk();
        $this->assertSame('QUEUED', DB::table('pending_payments')->where('id', $paymentId)->value('status'));

        $radius = Mockery::mock(ManualRadiusOperationsService::class);
        $radius->shouldReceive('currentGroup')->andReturn(InstallationAccessService::RESTRICTED_GROUP);
        $radius->shouldReceive('reconnectUser')->once()->andReturn(['status' => 'success']);
        $worker = $this->workerWith($radius);

        $worker->postPayment($paymentId);
        $this->webhook('REF-DUP', 'PAID')->assertOk();
        $this->webhook('REF-DUP', 'FAILED')->assertOk();
        $second = $worker->postPayment($paymentId);

        $this->assertTrue($second['skipped']);
        $this->assertSame('PAID', DB::table('pending_payments')->where('id', $paymentId)->value('status'));
        $this->assertEquals(0.0, (float) DB::table('billing_accounts')->where('id', $accountId)->value('account_balance'));
        $this->assertSame(1, DB::table('payment_portal_logs')->count());
    }

    public function test_a_radius_failure_after_payment_is_queued_once_for_retry(): void
    {
        $this->makeApprovedCustomer(1500, 'ACC-0077');
        // A reconnect from an earlier settlement is still waiting.
        DB::table('radius_operation_queue')->insert([
            'source_type' => 'payment_worker', 'source_id' => 1, 'account_no' => 'ACC-0077', 'operation' => 'reconnect_user',
            'params' => '{}', 'status' => 'pending', 'attempts' => 1, 'max_attempts' => 10, 'next_retry_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $paymentId = $this->makePayment('REF-RADIUS-DOWN', 1500, 'QUEUED', ['status' => 'PAID'], 'ACC-0077');

        $radius = Mockery::mock(ManualRadiusOperationsService::class);
        // RADIUS is down: the group can't be read, so the payment still tries to reconnect.
        $radius->shouldReceive('currentGroup')->andReturn(null);
        $radius->shouldReceive('reconnectUser')->times(3)->andReturn(['status' => 'error', 'message' => 'Connection timed out']);
        $worker = $this->workerWith($radius);

        $worker->postPayment($paymentId);

        // The payment stands even though RADIUS failed; the retry is not duplicated.
        $this->assertSame('PAID', DB::table('pending_payments')->where('id', $paymentId)->value('status'));
        $this->assertSame('queued', DB::table('pending_payments')->where('id', $paymentId)->value('reconnect_status'));
        $this->assertSame(1, DB::table('radius_operation_queue')->where('operation', 'reconnect_user')->where('status', 'pending')->count());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeJobOrder(array $overrides = []): JobOrder
    {
        static $applicationId = 0;
        $applicationId++;

        DB::table('applications')->insert([
            'id' => $applicationId, 'first_name' => 'Juan', 'middle_initial' => 'P', 'last_name' => 'Dela Cruz',
            'mobile_number' => '09171234567', 'email_address' => 'juan@example.com', 'installation_address' => '12 Rizal St',
            'location' => '', 'barangay' => 'Poblacion', 'city' => 'Tanay', 'region' => 'Rizal', 'desired_plan' => self::PLAN,
        ]);

        $id = DB::table('job_orders')->insertGetId(array_merge([
            'application_id' => $applicationId, 'account_id' => null, 'onsite_status' => 'Done', 'billing_status' => 'In Progress',
            'pppoe_username' => self::USERNAME, 'pppoe_password' => 'secret123', 'installation_fee' => 1500,
            'date_installed' => '2026-10-13 09:00:00', 'lcpnap' => 'LCP-1 NAP-1', 'port' => 'P01', 'invoice_url' => null,
            'organization_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ], $overrides));

        return JobOrder::with('application')->findOrFail($id);
    }

    private function makeAccount(float $balance, ?string $accountNo = null): int
    {
        static $n = 0;
        $n++;

        return DB::table('billing_accounts')->insertGetId([
            'customer_id' => null, 'account_no' => $accountNo ?? sprintf('ACC-%04d', $n), 'account_balance' => $balance,
            'billing_status_id' => 1, 'date_installed' => '2026-10-13', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** An approved new install: customer, billing account carrying the fee, technical details, fresh online status. */
    private function makeApprovedCustomer(float $fee, string $accountNo = 'ACC-0100'): int
    {
        $customerId = DB::table('customers')->insertGetId([
            'first_name' => 'Juan', 'middle_initial' => 'P', 'last_name' => 'Dela Cruz', 'contact_number_primary' => '09171234567',
            'email_address' => 'juan@example.com', 'desired_plan' => self::PLAN, 'account_no' => $accountNo,
        ]);
        $accountId = DB::table('billing_accounts')->insertGetId([
            'customer_id' => $customerId, 'account_no' => $accountNo, 'account_balance' => $fee, 'billing_status_id' => 1,
            'date_installed' => '2026-10-13', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('technical_details')->insert(['account_id' => $accountId, 'account_no' => $accountNo, 'username' => self::USERNAME]);
        // Approval creates it blank; RadiusStatusSyncService later reports the Restricted group as 'Restricted'.
        DB::table('online_status')->insert(['account_id' => $accountId, 'account_no' => $accountNo, 'username' => self::USERNAME, 'session_status' => '']);

        return $accountId;
    }

    private function makePayment(string $ref, float $amount, string $status, ?array $payload, string $accountNo = 'ACC-0100'): int
    {
        return DB::table('pending_payments')->insertGetId([
            'account_no' => $accountNo, 'reference_no' => $ref, 'amount' => $amount, 'status' => $status, 'payment_date' => now(),
            'provider' => 'Xendit', 'payment_id' => 'inv-' . $ref, 'callback_payload' => $payload ? json_encode($payload + ['external_id' => $ref]) : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function webhook(string $ref, string $status)
    {
        return $this->postJson('/api/payments/webhook', ['external_id' => $ref, 'status' => $status, 'id' => 'inv-' . $ref], [
            'X-Callback-Token' => 'test-callback-token',
        ]);
    }

    private function workerWith(ManualRadiusOperationsService $radius): PaymentWorkerService
    {
        $worker = new PaymentWorkerService();
        $this->setPrivate($worker, 'manualRadiusService', $radius);

        return $worker;
    }

    private function setPrivate(object $object, string $property, $value): void
    {
        $ref = new \ReflectionProperty($object, $property);
        $ref->setAccessible(true);
        $ref->setValue($object, $value);
    }

    private function bindResolver(): RadiusConfig
    {
        $config = RadiusConfig::query()->first() ?? RadiusConfig::query()->forceCreate([
            'ssl_type' => 'none', 'ip' => '10.0.0.1', 'port' => 8728, 'username' => 'api', 'password' => 'x',
        ]);

        $resolver = Mockery::mock(RadiusServerResolver::class);
        $resolver->shouldReceive('resolveForBarangay')->andReturn($config);
        $this->app->instance(RadiusServerResolver::class, $resolver);

        return $config;
    }

    private function mockApi(): Mockery\MockInterface
    {
        $api = Mockery::mock(RouterosApiService::class);
        $api->shouldReceive('getLastError')->andReturn('')->byDefault();
        $api->shouldReceive('isConnected')->andReturn(true)->byDefault();
        $this->app->instance(RouterosApiService::class, $api);

        return $api;
    }

    private function deviceUser(string $group): array
    {
        return ['.id' => '*1A', 'username' => self::USERNAME, 'group' => $group, 'disabled' => false, 'password' => 'secret123'];
    }

    private function seedInvoiceTemplate(): void
    {
        Schema::create('email_templates', function (Blueprint $t) {
            $t->string('Template_Code')->primary();
            $t->string('Subject_Line')->nullable();
            $t->longText('Body_HTML')->nullable();
            $t->boolean('Is_Active')->default(true);
        });
        DB::table('email_templates')->insert([
            'Template_Code' => InstallationFeeNotificationService::EMAIL_TEMPLATE_CODE,
            'Body_HTML' => '<p>{{Invoice_No}} for {{Account_No}}: {{Amount_Due}} due {{Due_Date}}</p>',
            'Is_Active' => true,
        ]);
    }

    private function queuedParams(JobOrder $jobOrder, RadiusConfig $config): array
    {
        return [
            'username' => self::USERNAME, 'password' => 'secret123', 'group' => self::PLAN_GROUP, 'plan_group' => self::PLAN_GROUP,
            'job_order_id' => $jobOrder->id, 'radius_config_id' => $config->id,
        ];
    }

    private function createSchema(): void
    {
        Schema::create('applications', function (Blueprint $t) {
            $t->id();
            foreach (['first_name', 'middle_initial', 'last_name', 'mobile_number', 'email_address', 'installation_address',
                'location', 'barangay', 'city', 'region', 'desired_plan'] as $column) {
                $t->string($column)->nullable();
            }
            $t->timestamps();
        });
        Schema::create('job_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('application_id')->nullable();
            $t->unsignedBigInteger('account_id')->nullable();
            foreach (['onsite_status', 'billing_status', 'pppoe_username', 'pppoe_password', 'username', 'lcpnap', 'port',
                'invoice_url', 'created_by_user_email', 'updated_by_user_email'] as $column) {
                $t->string($column)->nullable();
            }
            $t->decimal('installation_fee', 10, 2)->nullable();
            $t->dateTime('date_installed')->nullable();
            $t->unsignedBigInteger('organization_id')->nullable();
            $t->timestamps();
        });
        Schema::create('lcpnap', function (Blueprint $t) {
            $t->id();
            $t->string('lcpnap_name')->nullable();
            $t->string('lcp')->nullable();
            $t->string('nap')->nullable();
            $t->timestamps();
        });
        Schema::create('radius_config', function (Blueprint $t) {
            $t->id();
            foreach (['ssl_type', 'ip', 'username', 'password'] as $column) {
                $t->string($column)->nullable();
            }
            $t->integer('port')->nullable();
            $t->unsignedBigInteger('organization_id')->nullable();
            $t->timestamps();
        });
        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            foreach (['first_name', 'middle_initial', 'last_name', 'contact_number_primary', 'email_address', 'desired_plan',
                'account_no', 'address', 'location', 'barangay', 'city', 'region'] as $column) {
                $t->string($column)->nullable();
            }
            $t->timestamps();
        });
        Schema::create('billing_accounts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->string('account_no')->unique();
            $t->decimal('account_balance', 12, 2)->default(0);
            $t->dateTime('balance_update_date')->nullable();
            $t->unsignedBigInteger('billing_status_id')->nullable();
            $t->date('date_installed')->nullable();
            $t->string('updated_by')->nullable();
            $t->timestamps();
        });
        Schema::create('technical_details', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('account_id')->nullable();
            $t->string('account_no')->nullable();
            $t->string('username')->nullable();
            $t->timestamps();
        });
        Schema::create('online_status', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('account_id')->nullable();
            $t->string('account_no')->nullable();
            $t->string('username')->nullable();
            $t->string('session_status')->nullable();
            $t->timestamps();
        });
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->string('account_no');
            $t->dateTime('invoice_date')->nullable();
            $t->decimal('total_amount', 12, 2)->default(0);
            $t->decimal('received_payment', 12, 2)->default(0);
            $t->dateTime('due_date')->nullable();
            $t->string('status')->nullable();
            $t->string('transaction_id')->nullable();
            $t->string('updated_by')->nullable();
            $t->timestamps();
        });
        Schema::create('pending_payments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id')->nullable();
            $t->string('account_no');
            $t->string('reference_no')->unique();
            $t->decimal('amount', 12, 2);
            $t->string('status')->default('PENDING');
            $t->dateTime('payment_date')->nullable();
            foreach (['provider', 'plan', 'payment_id', 'payment_method_id', 'payment_url', 'reconnect_status'] as $column) {
                $t->string($column)->nullable();
            }
            $t->longText('json_payload')->nullable();
            $t->longText('callback_payload')->nullable();
            $t->dateTime('last_attempt_at')->nullable();
            $t->timestamps();
        });
        Schema::create('payment_portal_logs', function (Blueprint $t) {
            $t->id();
            $t->string('reference_no')->unique();
            $t->unsignedBigInteger('account_id')->nullable();
            $t->decimal('total_amount', 12, 2)->nullable();
            $t->decimal('account_balance_before', 12, 2)->nullable();
            $t->dateTime('date_time')->nullable();
            foreach (['checkout_id', 'status', 'transaction_status', 'ewallet_type', 'payment_channel', 'type', 'payment_url'] as $column) {
                $t->string($column)->nullable();
            }
            $t->longText('json_payload')->nullable();
            $t->longText('callback_payload')->nullable();
            $t->timestamps();
        });
        Schema::create('radius_operation_queue', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id')->nullable();
            $t->string('source_type');
            $t->unsignedInteger('source_id');
            $t->string('account_no')->nullable();
            $t->string('operation');
            $t->longText('params');
            $t->string('status')->default('pending');
            $t->integer('attempts')->default(0);
            $t->integer('max_attempts')->default(10);
            $t->text('last_error')->nullable();
            $t->dateTime('next_retry_at')->nullable();
            $t->string('created_by')->nullable();
            $t->dateTime('completed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('worker_locks', function (Blueprint $t) {
            $t->id();
            $t->string('lock_name')->unique();
            $t->dateTime('locked_at')->nullable();
            $t->string('locked_by')->nullable();
            $t->timestamps();
        });
        Schema::create('form_ui', function (Blueprint $t) {
            $t->id();
            $t->string('brand_name')->nullable();
        });
    }
}
