<?php

namespace Tests\Unit;

use App\Services\ManualRadiusOperationsService;
use PHPUnit\Framework\TestCase;

/**
 * A payment reconnects only a customer RADIUS has actually cut off.
 *
 * reconnectUser() writes a reconnection_logs row every time it runs, and billing prorates
 * reconnections. A customer who pays while their router is merely offline, still in their
 * plan group, used to get a reconnection logged anyway, and later a prorated bill for an
 * outage that never happened.
 *
 * The group rule is called directly. Both payment paths are also read to confirm they ask
 * for the group before reconnecting, so this file can't keep passing while one drifts.
 *
 * No database: nothing is written, read or migrated.
 */
class PaymentReconnectGateTest extends TestCase
{
    public function test_plan_groups_count_as_connected(): void
    {
        foreach (['100Mbps', 'SWIFT', 'Mikrotik-Group:50Mbps', ' 800Mbps '] as $group) {
            $this->assertTrue(ManualRadiusOperationsService::isConnectedGroup($group), "'$group' should count as connected");
        }
    }

    public function test_cut_off_groups_do_not(): void
    {
        foreach (['Restricted', 'Disconnected', 'restricted', 'DISCONNECTED', 'Mikrotik-Group:Restricted', 'Mikrotik-Group:Disconnected', ' Restricted '] as $group) {
            $this->assertFalse(ManualRadiusOperationsService::isConnectedGroup($group), "'$group' should count as cut off");
        }
    }

    public function test_an_unknown_group_is_not_counted_as_connected(): void
    {
        // Unreadable (null) or blank: the payment falls back to reconnecting.
        $this->assertFalse(ManualRadiusOperationsService::isConnectedGroup(null));
        $this->assertFalse(ManualRadiusOperationsService::isConnectedGroup(''));
        $this->assertFalse(ManualRadiusOperationsService::isConnectedGroup('Mikrotik-Group:'));
    }

    public function test_a_plan_label_maps_to_its_group(): void
    {
        $this->assertSame('100Mbps', ManualRadiusOperationsService::planGroup('100Mbps - P1200.00'));
        $this->assertSame('SWIFT', ManualRadiusOperationsService::planGroup('SWIFT 1000'));
        $this->assertSame('STARTER', ManualRadiusOperationsService::planGroup('STARTER - P799.00'));
        $this->assertSame('50Mbps', ManualRadiusOperationsService::planGroup('50Mbps'));
    }

    public function test_group_names_compare_without_case_or_prefix(): void
    {
        $this->assertTrue(ManualRadiusOperationsService::sameGroup('Mikrotik-Group:100Mbps', '100mbps'));
        $this->assertTrue(ManualRadiusOperationsService::sameGroup(' SWIFT ', 'swift'));
        $this->assertFalse(ManualRadiusOperationsService::sameGroup('50Mbps', '100Mbps'));
        $this->assertFalse(ManualRadiusOperationsService::sameGroup(null, ''));
    }

    /**
     * @dataProvider paymentPaths
     */
    public function test_each_payment_path_checks_the_group_before_reconnecting(string $file, string $method): void
    {
        $body = $this->methodBody($file, $method);

        $groupCheck = strpos($body, 'isConnectedGroup(');
        $reconnect = strpos($body, '->reconnectUser(');

        $this->assertNotFalse(strpos($body, '->currentGroup('), "$method no longer reads the RADIUS group");
        $this->assertNotFalse($groupCheck, "$method no longer asks whether the group is connected");
        $this->assertNotFalse($reconnect, "$method no longer calls reconnectUser()");
        $this->assertLessThan($reconnect, $groupCheck, "$method reconnects before checking the group");
        $this->assertStringContainsString("'already_connected'", $body);
        $this->assertNotFalse(strpos($body, '->updateGroup('), "$method no longer applies a plan change as a group update");
    }

    public static function paymentPaths(): array
    {
        $root = dirname(__DIR__, 2);

        return [
            'online payment (portal)' => [$root . '/app/Services/PaymentWorkerService.php', 'attemptReconnect'],
            'manual payment (cashier)' => [$root . '/app/Http/Controllers/TransactionController.php', 'attemptReconnectionAfterApproval'],
        ];
    }

    private function methodBody(string $file, string $method): string
    {
        $source = file_get_contents($file);
        $start = strpos($source, "function {$method}(");
        $this->assertNotFalse($start, "$method not found in $file");

        // Up to the next method declaration.
        $next = preg_match('/\n    (?:public|private|protected) function /', $source, $m, PREG_OFFSET_CAPTURE, $start + 1)
            ? $m[0][1]
            : strlen($source);

        return substr($source, $start, $next - $start);
    }
}
