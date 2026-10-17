<?php

namespace Tests\Feature;

use App\Models\BillingAccount;
use App\Services\BillingNotificationService;
use App\Services\EnhancedBillingGenerationServiceWithNotifications;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Monolog\Handler\NullHandler;
use Tests\TestCase;

/**
 * Reconnection proration charges one reconnection per billing cycle.
 *
 * One outage can leave many reconnection logs: ManualRadiusOperationsService writes a log
 * before the RADIUS call, so every failed auto-reconnect retry adds one, and a manual
 * reconnect service order adds another. Account 4512 had ten for a single Oct 2 2026 payment
 * and was billed 10 x 266.67 = 2666.70 instead of 266.67.
 *
 * Runs on an in-memory SQLite database. phpunit.xml does not override DB_CONNECTION, so
 * without the override below a database test would reach whatever .env points at.
 */
class ReconnectionProrateTest extends TestCase
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
        $app['config']->set('logging.channels.billing', ['driver' => 'monolog', 'handler' => NullHandler::class]);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->fail('Refusing to run: the test database is not SQLite.');
        }

        Schema::create('billing_config', function (Blueprint $t) {
            $t->id();
            $t->integer('due_date_day')->nullable();
            $t->integer('disconnection_day')->nullable();
            $t->integer('grace_charge_day')->nullable();
            $t->timestamps();
        });
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->string('account_no');
            $t->dateTime('invoice_date');
            $t->dateTime('due_date')->nullable();
            $t->timestamps();
        });
        Schema::create('disconnected_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('account_id');
            $t->string('remarks')->nullable();
            $t->dateTime('created_at')->nullable();
        });
        Schema::create('reconnection_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('account_id');
            $t->boolean('pro_rate_applied')->nullable();
            $t->string('billing_status')->nullable();
            $t->dateTime('created_at')->nullable();
        });
        Schema::create('transactions', function (Blueprint $t) {
            $t->id();
            $t->string('account_no');
            $t->string('status')->nullable();
            $t->dateTime('payment_date')->nullable();
        });
        Schema::create('payment_portal_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('account_id');
            $t->string('status')->nullable();
            $t->dateTime('date_time')->nullable();
        });

        // Account 4512's September cycle: due Sep 12, Auto DC Sep 22, pullout Sep 29
        // (threshold Sep 12 + 10 + 7), paid online Oct 2.
        DB::table('billing_config')->insert(['due_date_day' => 1, 'disconnection_day' => 10, 'grace_charge_day' => 7]);
        DB::table('invoices')->insert(['account_no' => '4512', 'invoice_date' => '2026-09-11 00:00:00', 'due_date' => '2026-09-12 00:00:00']);
        DB::table('disconnected_logs')->insert([
            ['account_id' => 3495, 'remarks' => 'Auto DC', 'created_at' => '2026-09-22 04:08:27'],
            ['account_id' => 3495, 'remarks' => 'Pullout', 'created_at' => '2026-09-29 04:00:54'],
        ]);
        DB::table('payment_portal_logs')->insert(['account_id' => 3495, 'status' => 'PAID', 'date_time' => '2026-10-02 20:40:02']);
    }

    private function account(): BillingAccount
    {
        return (new BillingAccount())->forceFill(['id' => 3495, 'account_no' => '4512', 'billing_day' => 12]);
    }

    /**
     * Account 3068: installed Jun 12, billing day 12, made Inactive by the sync script with no
     * bill and no disconnection on file, then reactivated on Sep 22.
     */
    private function reactivatedAccount(string $installed = '2026-06-12'): BillingAccount
    {
        return (new BillingAccount())->forceFill(['id' => 2061, 'account_no' => '3068', 'billing_day' => 12, 'date_installed' => $installed]);
    }

    private function addReconnections(array $timestamps, int $accountId = 3495): void
    {
        DB::table('reconnection_logs')->insert(array_map(
            fn ($at) => ['account_id' => $accountId, 'billing_status' => 'Unused', 'pro_rate_applied' => 0, 'created_at' => $at],
            $timestamps
        ));
    }

    private function prorate(string $generationDate = '2026-10-11', ?BillingAccount $account = null): array
    {
        // Mocked so the SMS/email services behind it are never built.
        $service = new EnhancedBillingGenerationServiceWithNotifications($this->createMock(BillingNotificationService::class));

        return $service->calculateReconnectionProrate($account ?? $this->account(), Carbon::parse($generationDate), 800.00);
    }

    public function test_a_single_reconnection_is_prorated_from_the_payment_date(): void
    {
        $this->addReconnections(['2026-10-02 12:40:03']);

        $result = $this->prorate();

        // Oct 2 -> Oct 12 = 10 days at 800 / 30.
        $this->assertSame(266.67, $result['total_prorate']);
        $this->assertSame('2026-10-02', $result['pro_rate_start']);
        $this->assertCount(1, $result['log_ids']);
    }

    public function test_duplicate_reconnection_logs_for_one_outage_are_charged_once(): void
    {
        $this->addReconnections([
            '2026-10-02 12:40:03', '2026-10-02 12:40:18', '2026-10-02 12:40:46', '2026-10-02 12:42:01',
            '2026-10-02 12:44:01', '2026-10-02 12:46:01', '2026-10-02 12:48:01', '2026-10-02 12:50:02',
            '2026-10-03 04:16:16', // manual reconnect service order
            '2026-10-09 04:36:18', // RADIUS queue retry that finally succeeded
        ]);

        $result = $this->prorate();

        $this->assertSame(266.67, $result['total_prorate'], 'Each duplicate log was charged again');
        $this->assertSame('2026-10-02', $result['pro_rate_start']);
        // Every log is still consumed so none carries over into the next bill.
        $this->assertCount(10, $result['log_ids']);
    }

    public function test_a_duplicate_log_written_after_the_bill_is_not_charged_next_cycle(): void
    {
        // The October bill already prorated the Oct 2 payment. A RADIUS retry that only succeeds
        // on Oct 13 lands in the next cycle and is measured against the October invoice's due date.
        DB::table('invoices')->insert(['account_no' => '4512', 'invoice_date' => '2026-10-11 00:00:00', 'due_date' => '2026-10-12 00:00:00']);
        $this->addReconnections(['2026-10-13 04:36:18']);

        $result = $this->prorate('2026-11-11');

        $this->assertSame(0.0, $result['total_prorate']);
        $this->assertNull($result['pro_rate_start']);
        $this->assertCount(1, $result['log_ids']);
    }

    public function test_an_account_returning_to_billing_is_prorated_from_the_reconnection_date(): void
    {
        $this->addReconnections(['2026-09-22 11:26:02'], 2061);

        $result = $this->prorate('2026-10-11', $this->reactivatedAccount());

        // Sep 22 -> Oct 12 = 20 days at 800 / 30, not the full month.
        $this->assertSame(533.33, $result['total_prorate']);
        $this->assertSame('2026-09-22', $result['pro_rate_start']);
        $this->assertCount(1, $result['log_ids']);
    }

    public function test_a_returning_account_is_prorated_even_with_a_disconnection_and_an_early_payment(): void
    {
        // A pullout is on file but no bill is, so the threshold would fall back to Sep 12 + 17 =
        // Sep 29 and a Sep 22 payment would have been billed a full month.
        DB::table('disconnected_logs')->insert(['account_id' => 2061, 'remarks' => 'Pullout', 'created_at' => '2026-08-01 04:00:00']);
        DB::table('payment_portal_logs')->insert(['account_id' => 2061, 'status' => 'PAID', 'date_time' => '2026-09-22 11:50:02']);
        $this->addReconnections(['2026-09-22 11:26:02'], 2061);

        $result = $this->prorate('2026-10-11', $this->reactivatedAccount());

        $this->assertSame(533.33, $result['total_prorate']);
        $this->assertSame('2026-09-22', $result['pro_rate_start']);
    }

    public function test_an_account_whose_last_bill_predates_this_cycle_is_returning_to_billing(): void
    {
        // Billed for the August cycle, then skipped on Sep 11 while it was Inactive.
        DB::table('invoices')->insert(['account_no' => '3068', 'invoice_date' => '2026-08-11 00:00:00', 'due_date' => '2026-08-12 00:00:00']);
        $this->addReconnections(['2026-09-22 11:26:02'], 2061);

        $result = $this->prorate('2026-10-11', $this->reactivatedAccount());

        $this->assertSame(533.33, $result['total_prorate']);
    }

    public function test_a_reconnection_on_an_account_billed_this_cycle_is_not_prorated(): void
    {
        // A plan change on an Active account: the Sep 11 bill opened the cycle and nothing was
        // cut, so the next bill stays a full month.
        DB::table('invoices')->insert(['account_no' => '3068', 'invoice_date' => '2026-09-11 00:00:00', 'due_date' => '2026-09-12 00:00:00']);
        $this->addReconnections(['2026-09-22 11:26:02'], 2061);

        $result = $this->prorate('2026-10-11', $this->reactivatedAccount());

        $this->assertSame(0.0, $result['total_prorate']);
        $this->assertNull($result['pro_rate_start']);
        $this->assertCount(0, $result['log_ids']);
    }

    public function test_a_new_installation_is_left_to_the_first_bill_proration(): void
    {
        // Installed during the previous cycle and not billed yet: calculateNewInstallProrate owns it.
        $this->addReconnections(['2026-09-22 11:26:02'], 2061);

        $result = $this->prorate('2026-10-11', $this->reactivatedAccount('2026-08-20'));

        $this->assertSame(0.0, $result['total_prorate']);
        $this->assertCount(0, $result['log_ids']);
    }
}
