<?php

namespace Tests\Feature;

use App\Http\Controllers\TransactionController;
use App\Services\ManualRadiusOperationsService;
use App\Services\PaymentWorkerService;
use App\Services\PulloutServiceOrderCloser;
use App\Services\RadiusQueueService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Monolog\Handler\NullHandler;
use Tests\TestCase;

/**
 * A payment, or a queued reconnect, reconnects only a customer RADIUS has actually cut off.
 *
 * reconnectUser() writes a reconnection_logs row every time it runs, and billing prorates
 * reconnections. A customer who paid while their router was merely offline, still in their
 * plan group, used to get a reconnection logged anyway.
 *
 *   - Restricted / Disconnected / group unreadable: reconnect, as before.
 *   - Connected on their plan: skip (payment) or fail the job (queue). Nothing logged.
 *   - Connected on another plan (a plan change): update the group. Nothing logged.
 *
 * RADIUS is a fake bound in the container, so nothing reaches a real server. Runs on an
 * in-memory SQLite database: phpunit.xml does not override DB_CONNECTION, so without the
 * override below a database test would reach whatever .env points at.
 */
class ReconnectGateTest extends TestCase
{
    private FakeRadiusOperations $radius;

    private object $closer;

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
        foreach (['stack', 'single', 'radiusrelated'] as $channel) {
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
            $t->decimal('account_balance', 10, 2)->default(0);
            $t->unsignedBigInteger('billing_status_id')->default(1);
            $t->string('updated_by')->nullable();
            $t->timestamps();
        });
        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->string('first_name')->nullable();
            $t->string('middle_initial')->nullable();
            $t->string('last_name')->nullable();
            $t->string('desired_plan')->nullable();
            $t->string('email_address')->nullable();
            $t->string('contact_number_primary')->nullable();
        });
        Schema::create('technical_details', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('account_id');
            $t->string('username')->nullable();
        });
        Schema::create('online_status', function (Blueprint $t) {
            $t->id();
            $t->string('account_no');
            $t->string('session_status')->nullable();
        });
        Schema::create('radius_operation_queue', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id')->nullable();
            $t->string('source_type');
            $t->unsignedBigInteger('source_id');
            $t->string('account_no')->nullable();
            $t->string('operation');
            $t->text('params');
            $t->string('status');
            $t->integer('attempts')->default(0);
            $t->integer('max_attempts')->nullable();
            $t->text('last_error')->nullable();
            $t->dateTime('next_retry_at')->nullable();
            $t->string('created_by')->nullable();
            $t->dateTime('completed_at')->nullable();
            $t->timestamps();
        });

        // Account 1183: paid up, 100Mbps plan, router offline.
        DB::table('customers')->insert(['id' => 10, 'first_name' => 'Test', 'last_name' => 'Customer', 'desired_plan' => '100Mbps - P1200.00']);
        DB::table('billing_accounts')->insert(['id' => 1, 'account_no' => '1183', 'customer_id' => 10, 'account_balance' => 0, 'billing_status_id' => 1]);
        DB::table('technical_details')->insert(['account_id' => 1, 'username' => 'u1183']);
        DB::table('online_status')->insert(['account_no' => '1183', 'session_status' => 'Offline']);

        $this->radius = new FakeRadiusOperations();
        $this->app->instance(ManualRadiusOperationsService::class, $this->radius);

        // The pullout closer is final and touches service orders; record the call instead.
        $this->closer = new class {
            public int $calls = 0;
            public function closeIfSettled(...$args): array
            {
                $this->calls++;
                return [];
            }
        };
        $this->app->instance(PulloutServiceOrderCloser::class, $this->closer);
    }

    // ---- Online payment (PaymentWorkerService) --------------------------------------

    private function payOnline(): string
    {
        $worker = new PaymentWorkerService();
        $property = new \ReflectionProperty(PaymentWorkerService::class, 'manualRadiusService');
        $property->setAccessible(true);
        $property->setValue($worker, $this->radius);

        $method = new \ReflectionMethod(PaymentWorkerService::class, 'attemptReconnect');
        $method->setAccessible(true);

        return $method->invoke($worker, (object) ['account_id' => 1, 'account_no' => '1183'], 'REF-1');
    }

    public function test_online_payment_skips_a_customer_already_connected_on_their_plan(): void
    {
        $this->radius->group = '100Mbps';

        $this->assertSame('already_connected', $this->payOnline());
        $this->assertSame(0, $this->radius->count('reconnectUser'));
        $this->assertSame(0, $this->radius->count('updateGroup'));
    }

    public function test_online_payment_applies_a_plan_change_without_reconnecting(): void
    {
        $this->radius->group = '50Mbps';

        $this->assertSame('plan_updated', $this->payOnline());
        $this->assertSame(0, $this->radius->count('reconnectUser'));
        $this->assertSame(1, $this->radius->count('updateGroup'));
        $this->assertSame('100Mbps - P1200.00', $this->radius->lastParams('updateGroup')['plan']);
    }

    public function test_online_payment_queues_a_plan_change_radius_did_not_confirm(): void
    {
        $this->radius->group = '50Mbps';
        $this->radius->applies = false;

        $this->assertSame('plan_updated', $this->payOnline());
        $this->assertSame(1, DB::table('radius_operation_queue')->where('operation', 'reconnect_user')->where('status', 'pending')->count());
    }

    public function test_online_payment_reconnects_a_restricted_customer(): void
    {
        $this->radius->group = 'Mikrotik-Group:Restricted';
        DB::table('billing_accounts')->where('id', 1)->update(['billing_status_id' => 4]);

        $this->assertSame('success', $this->payOnline());
        $this->assertSame(1, $this->radius->count('reconnectUser'));
        $this->assertSame(1, (int) DB::table('billing_accounts')->where('id', 1)->value('billing_status_id'));
    }

    public function test_online_payment_reconnects_when_the_group_cannot_be_read(): void
    {
        $this->radius->group = null;

        $this->assertSame('success', $this->payOnline());
        $this->assertSame(1, $this->radius->count('reconnectUser'));
    }

    public function test_online_payment_sets_a_connected_inactive_account_back_to_active(): void
    {
        $this->radius->group = '100Mbps';
        DB::table('billing_accounts')->where('id', 1)->update(['billing_status_id' => 4]);

        $this->assertSame('already_connected', $this->payOnline());
        $this->assertSame(0, $this->radius->count('reconnectUser'));
        $this->assertSame(1, (int) DB::table('billing_accounts')->where('id', 1)->value('billing_status_id'));
    }

    // ---- Cashier approval (TransactionController) -----------------------------------

    private function approveAtCashier(): string
    {
        $method = new \ReflectionMethod(TransactionController::class, 'attemptReconnectionAfterApproval');
        $method->setAccessible(true);

        return $method->invoke(new TransactionController(), (object) ['id' => 1], 'cashier@test', 'TX-1');
    }

    public function test_cashier_approval_skips_a_customer_already_connected_on_their_plan(): void
    {
        $this->radius->group = '100Mbps';

        $this->assertSame('already_connected', $this->approveAtCashier());
        $this->assertSame(0, $this->radius->count('reconnectUser'));
    }

    public function test_cashier_approval_applies_a_plan_change_without_reconnecting(): void
    {
        $this->radius->group = '50Mbps';

        $this->assertSame('plan_updated', $this->approveAtCashier());
        $this->assertSame(0, $this->radius->count('reconnectUser'));
        $this->assertSame(1, $this->radius->count('updateGroup'));
    }

    public function test_cashier_approval_reconnects_a_disconnected_customer(): void
    {
        $this->radius->group = 'Disconnected';

        $this->assertSame('success', $this->approveAtCashier());
        $this->assertSame(1, $this->radius->count('reconnectUser'));
    }

    // ---- Queued reconnects (RadiusQueueService) -------------------------------------

    private function queueReconnect(string $plan = '100Mbps - P1200.00'): int
    {
        DB::table('radius_operation_queue')->insert([
            'source_type' => 'service_order', 'source_id' => 7, 'account_no' => '1183', 'operation' => 'reconnect_user',
            'params' => json_encode(['accountNumber' => '1183', 'username' => 'u1183', 'plan' => $plan, 'updatedBy' => 'test']),
            'status' => 'pending', 'attempts' => 0, 'max_attempts' => 10, 'next_retry_at' => now()->subMinute(),
            'created_by' => 'test', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('radius_operation_queue')->max('id');
    }

    private function job(int $id): object
    {
        return DB::table('radius_operation_queue')->where('id', $id)->first();
    }

    public function test_a_queued_reconnect_fails_when_the_customer_is_already_connected_on_their_plan(): void
    {
        $this->radius->group = '100Mbps';
        $id = $this->queueReconnect();

        (new RadiusQueueService())->processQueue();

        $this->assertSame('failed', $this->job($id)->status);
        $this->assertStringContainsString('already connected', $this->job($id)->last_error);
        $this->assertSame(0, $this->radius->count('reconnectUser'));
        $this->assertSame(1, $this->closer->calls, 'Open pullouts are still closed for a settled account');
    }

    public function test_a_queued_reconnect_applies_a_plan_change_as_a_group_update(): void
    {
        $this->radius->group = '50Mbps';
        $id = $this->queueReconnect();

        (new RadiusQueueService())->processQueue();

        $this->assertSame('success', $this->job($id)->status);
        $this->assertSame(0, $this->radius->count('reconnectUser'));
        $this->assertSame(1, $this->radius->count('updateGroup'));
    }

    public function test_a_queued_plan_change_radius_did_not_confirm_is_retried(): void
    {
        $this->radius->group = '50Mbps';
        $this->radius->applies = false;
        $id = $this->queueReconnect();

        (new RadiusQueueService())->processQueue();

        $this->assertSame('pending', $this->job($id)->status);
        $this->assertSame(1, (int) $this->job($id)->attempts);
    }

    public function test_a_queued_reconnect_still_runs_for_a_restricted_customer(): void
    {
        $this->radius->group = 'Restricted';
        $id = $this->queueReconnect();

        (new RadiusQueueService())->processQueue();

        $this->assertSame('success', $this->job($id)->status);
        $this->assertSame(1, $this->radius->count('reconnectUser'));
    }

    public function test_a_queued_reconnect_still_runs_when_the_group_cannot_be_read(): void
    {
        $this->radius->group = null;
        $id = $this->queueReconnect();

        (new RadiusQueueService())->processQueue();

        $this->assertSame('success', $this->job($id)->status);
        $this->assertSame(1, $this->radius->count('reconnectUser'));
    }
}

/**
 * Stands in for the RADIUS servers: reports a set group and records what was asked of it.
 */
class FakeRadiusOperations extends ManualRadiusOperationsService
{
    public ?string $group = null;

    public bool $applies = true;

    /** @var array<int, array{0: string, 1: mixed}> */
    public array $calls = [];

    public function currentGroup(string $username): ?string
    {
        $this->calls[] = ['currentGroup', $username];
        return $this->group;
    }

    public function reconnectUser(array $params): array
    {
        $this->calls[] = ['reconnectUser', $params];
        return ['status' => 'success', 'message' => 'reconnected'];
    }

    public function updateGroup(array $params): array
    {
        $this->calls[] = ['updateGroup', $params];
        return ['status' => 'success', 'message' => 'group updated', 'applied' => $this->applies];
    }

    public function count(string $method): int
    {
        return count(array_filter($this->calls, fn ($call) => $call[0] === $method));
    }

    public function lastParams(string $method): ?array
    {
        $matching = array_values(array_filter($this->calls, fn ($call) => $call[0] === $method));
        return $matching ? end($matching)[1] : null;
    }
}
