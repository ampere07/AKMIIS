<?php

namespace App\Services;

use App\Models\JobOrder;
use App\Models\RadiusConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Internet access for a new installation while its installation fee is outstanding.
 *
 * The lifecycle:
 *
 *   1. Onsite Done — JobOrderController creates the User Manager account in RESTRICTED_GROUP
 *      instead of the plan group, so the customer has no internet while the job order waits
 *      for approval.
 *   2. Approval — the fee is put on the account balance and the installation invoice is
 *      sent. A job order with no fee to pay is activated here; otherwise the account is
 *      kept in RESTRICTED_GROUP.
 *   3. Payment — PaymentWorkerService::attemptReconnect() (portal) and
 *      TransactionController (cashier) already reconnect any account whose balance settles
 *      and whose session is not Online. RadiusStatusSyncService reports a Restricted-group
 *      account as 'Restricted', so a settled new install is moved to its plan group there,
 *      with no extra hook.
 *
 * Restriction is applied by putting the account in the group directly, never through
 * ManualRadiusOperationsService::restrictedUser(). That method writes a disconnected_logs row
 * and sets the billing status, and a disconnection on file followed by the payment's
 * reconnection is what EnhancedBillingGenerationServiceWithNotifications prorates as a
 * reconnection — the customer's first bill would carry a reconnection charge on top of the
 * new-install proration.
 */
class InstallationAccessService
{
    /** The User Manager group with no internet access; the name RadiusStatusSyncService reports as 'Restricted'. */
    public const RESTRICTED_GROUP = 'Restricted';

    /** radius_operation_queue.source_type for creates queued from a job order. */
    public const QUEUE_SOURCE_JOB_ORDER = 'job_order';

    /** radius_operation_queue.source_type for activations queued at approval. */
    public const QUEUE_SOURCE_APPROVAL = 'job_order_approval';

    /** Balance at or below which an account counts as paid — same tolerance as the payment worker. */
    private const SETTLED_EPSILON = 0.01;

    /**
     * The group a job order's account belongs in on the device right now.
     *
     * Restricted until the job order is approved and the account balance — which carries the
     * installation fee from approval on — is settled. An approved job order whose billing
     * account cannot be found is kept restricted rather than given access by default.
     */
    public function groupFor(JobOrder $jobOrder, string $planGroup): string
    {
        if (empty($jobOrder->account_id)) {
            return self::RESTRICTED_GROUP;
        }

        $balance = DB::table('billing_accounts')->where('id', $jobOrder->account_id)->value('account_balance');

        if ($balance === null || (float) $balance > self::SETTLED_EPSILON) {
            return self::RESTRICTED_GROUP;
        }

        return $planGroup;
    }

    /**
     * Make sure an account that is already on the device sits in $group.
     *
     * RouterosApiService::addUser() reports an existing account as created and leaves it
     * alone, so an account left behind by an earlier attempt — or created in the plan group
     * before this flow existed — would keep its internet access. When the group has to
     * change, the live session is ended so the customer re-authenticates under the new group.
     *
     * @param RadiusConfig|array<string, mixed> $config
     * @return bool whether the account is now in $group
     */
    public function enforceGroup(RouterosApiService $api, $config, string $username, string $group): bool
    {
        $user = $api->findUser($config, $username);

        if ($user === null) {
            return false;
        }

        if (strcasecmp(trim((string) $user['group']), $group) === 0) {
            return true;
        }

        if (!$api->setUserGroup($config, (string) ($user['.id'] ?: $username), $group)) {
            Log::channel('radiusrelated')->error('Could not move User Manager account to its installation group', [
                'username' => $username,
                'from'     => $user['group'],
                'to'       => $group,
                'error'    => $api->getLastError(),
            ]);

            return false;
        }

        $api->killSessionsForUser($config, $username);

        Log::channel('radiusrelated')->info('User Manager account moved to its installation group', [
            'username' => $username,
            'from'     => $user['group'],
            'to'       => $group,
        ]);

        return true;
    }

    /**
     * Queue the account create for a job order whose device did not answer.
     *
     * The queued item is pinned to the server the create was aimed at, and the group is
     * decided again when it runs (RadiusQueueService::retryCreateUser), so a retry that lands
     * after approval and payment does not put a paid customer back in RESTRICTED_GROUP.
     * At most one create per job order is pending at a time.
     */
    public function queueCreate(JobOrder $jobOrder, RadiusConfig $config, string $username, string $password, string $planGroup, ?string $error): bool
    {
        try {
            $pending = DB::table('radius_operation_queue')
                ->where('source_type', self::QUEUE_SOURCE_JOB_ORDER)
                ->where('source_id', $jobOrder->id)
                ->where('operation', 'create_user')
                ->whereIn('status', ['pending', 'processing'])
                ->exists();

            if ($pending) {
                return true;
            }

            return RadiusQueueService::queue([
                'source_type'     => self::QUEUE_SOURCE_JOB_ORDER,
                'source_id'       => $jobOrder->id,
                'account_no'      => null,
                'operation'       => 'create_user',
                'params'          => [
                    'username'         => $username,
                    'password'         => $password,
                    'group'            => $planGroup,
                    'plan_group'       => $planGroup,
                    'job_order_id'     => $jobOrder->id,
                    'radius_config_id' => $config->id,
                    'organization_id'  => $jobOrder->organization_id,
                ],
                'last_error'      => $error,
                'created_by'      => 'Job Order Done',
                'organization_id' => $jobOrder->organization_id,
            ]) !== null;
        } catch (Throwable $e) {
            Log::channel('radiusrelated')->error('Could not queue the RADIUS create for a job order', [
                'job_order_id' => $jobOrder->id,
                'error'        => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Run a queued job-order create: the group is decided now, not when it was queued.
     *
     * Returns true without touching the device when the job order no longer wants this
     * account (re-opened, or given other credentials since), so an obsolete create stops
     * retrying instead of recreating an account that was deliberately removed.
     *
     * @param array<string, mixed> $params the queued create_user params
     */
    public function runQueuedCreate(RouterosApiService $api, array $params, ?callable $log = null): bool
    {
        $log = $log ?? static function (string $message): void {};

        $jobOrder = JobOrder::find($params['job_order_id'] ?? null);
        $username = (string) ($params['username'] ?? '');

        if (!$jobOrder || $jobOrder->onsite_status !== 'Done' || (string) $jobOrder->pppoe_username !== $username) {
            $log("  [SKIP] create_user: job order #" . ($params['job_order_id'] ?? '?') . " no longer needs '{$username}'");
            return true;
        }

        $config = RadiusConfig::find($params['radius_config_id'] ?? null);
        if (!$config) {
            $log("  [ERROR] create_user: RADIUS config #" . ($params['radius_config_id'] ?? '?') . " no longer exists");
            return false;
        }

        $group = $this->groupFor($jobOrder, (string) ($params['plan_group'] ?? $params['group'] ?? ''));
        $log("  [RADIUS] create_user for job order #{$jobOrder->id} at {$config->ip} (Config #{$config->id}) | User: {$username} | Group: {$group}");

        if (!$api->addUser($config, $username, (string) ($params['password'] ?? ''), $group)) {
            $log("  [RADIUS] ✗ create_user FAILED - " . $api->getLastError());
            return false;
        }

        if (!$this->enforceGroup($api, $config, $username, $group)) {
            $log("  [RADIUS] ✗ create_user: account exists but could not be placed in '{$group}'");
            return false;
        }

        $log("  [RADIUS] ✓ create_user SUCCESS");
        return true;
    }

    /**
     * Set internet access once an approval has committed.
     *
     * A balance already settled (no installation fee) is activated now through the existing
     * reconnect path; otherwise the account is held in RESTRICTED_GROUP until the payment
     * worker reconnects it. Best-effort: the approval is durable and must not fail on an
     * unreachable device. An activation that fails is queued (once) for retry.
     *
     * @return array{action: string, success: bool, message: string}
     */
    public function applyAfterApproval(JobOrder $jobOrder, object $billingAccount, string $username, ?string $desiredPlan, ?string $barangay, ?string $city): array
    {
        try {
            $balance = (float) (DB::table('billing_accounts')->where('id', $billingAccount->id)->value('account_balance') ?? 0);

            if ($balance <= self::SETTLED_EPSILON) {
                return $this->activate($jobOrder, $billingAccount, $username, $desiredPlan);
            }

            return $this->holdRestricted($jobOrder, $username, $barangay, $city);
        } catch (Throwable $e) {
            Log::channel('radiusrelated')->error('Setting internet access after approval failed', [
                'job_order_id' => $jobOrder->id,
                'account_no'   => $billingAccount->account_no ?? null,
                'error'        => $e->getMessage(),
            ]);

            return ['action' => 'error', 'success' => false, 'message' => $e->getMessage()];
        }
    }

    private function activate(JobOrder $jobOrder, object $billingAccount, string $username, ?string $desiredPlan): array
    {
        if (empty($desiredPlan)) {
            return ['action' => 'activate', 'success' => false, 'message' => 'No plan on the application; internet access left as is.'];
        }

        $params = [
            'accountNumber' => $billingAccount->account_no,
            'username'      => $username,
            'plan'          => $desiredPlan,
            'updatedBy'     => 'Job Order Approval',
            'remarks'       => 'Job Order Approval - no installation fee due',
        ];

        $result = app(ManualRadiusOperationsService::class)->reconnectUser($params);

        if (($result['status'] ?? '') === 'success') {
            return ['action' => 'activate', 'success' => true, 'message' => 'Internet access activated: no installation fee due.'];
        }

        $queue = RadiusQueueService::queueUnique([
            'source_type'     => self::QUEUE_SOURCE_APPROVAL,
            'source_id'       => $jobOrder->id,
            'account_no'      => $billingAccount->account_no,
            'operation'       => 'reconnect_user',
            'params'          => $params,
            'last_error'      => $result['message'] ?? null,
            'created_by'      => 'Job Order Approval',
            'organization_id' => $jobOrder->organization_id,
        ]);

        return [
            'action'  => 'activate',
            'success' => false,
            'message' => ($queue['queued'] || $queue['duplicate'])
                ? 'Activation queued for retry: ' . ($result['message'] ?? 'RADIUS did not respond')
                : 'Activation failed and could not be queued: ' . ($result['message'] ?? 'RADIUS did not respond'),
        ];
    }

    private function holdRestricted(JobOrder $jobOrder, string $username, ?string $barangay, ?string $city): array
    {
        $config = app(RadiusServerResolver::class)->resolveForBarangay($barangay, $city, $jobOrder->organization_id);

        if (!$config) {
            return ['action' => 'restrict', 'success' => false, 'message' => 'No RADIUS server is assigned to this barangay.'];
        }

        $api = app(RouterosApiService::class);

        if ($this->enforceGroup($api, $config, $username, self::RESTRICTED_GROUP)) {
            return ['action' => 'restrict', 'success' => true, 'message' => 'Internet access restricted until the installation fee is paid.'];
        }

        // Not on the device yet (its create is still queued, and will be decided as
        // restricted when it runs) or the device did not answer.
        return [
            'action'  => 'restrict',
            'success' => false,
            'message' => 'Could not confirm the account is restricted: ' . ($api->getLastError() ?: 'account not found on the RADIUS server'),
        ];
    }
}
