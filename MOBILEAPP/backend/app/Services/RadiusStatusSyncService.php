<?php

namespace App\Services;

use App\Models\OnlineStatus;
use App\Models\BillingAccount;
use App\Models\TechnicalDetail;
use App\Models\RadiusConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RadiusStatusSyncService
{
    private const MAX_RETRIES = 3;
    private const RETRY_DELAY = 2;

    public function syncRadiusStatus(): array
    {
        $stats = [
            'synced' => 0,
            'inserted' => 0,
            'updated' => 0,
            'not_found' => 0,
            'offline' => 0,
            'online' => 0,
            'restricted' => 0,
            'disconnected' => 0,
            'errors' => 0
        ];

        try {
            // Step 1: Sync billing accounts to online_status quickly (outside of a long transaction)
            $this->syncAccountsToOnlineStatus($stats);
            
            $radiusConfigs = RadiusConfig::all();
            if ($radiusConfigs->isEmpty()) {
                throw new \Exception('RADIUS configuration not found');
            }

            // Step 2: Fetch RADIUS data (potentially slow I/O)
            $radiusUsers = $this->fetchRadiusUsers($radiusConfigs);
            $radiusSessions = $this->fetchRadiusSessions($radiusConfigs);

            // Anti-timeout: ensure DB connection is alive after API calls
            try {
                DB::connection()->getPdo()->query('SELECT 1');
            } catch (\Throwable $e) {
                Log::warning('DB connection lost before processing accounts, attempting reconnect', [
                    'error' => $e->getMessage(),
                ]);
                $default = config('database.default');
                DB::purge($default);
                DB::reconnect($default);
            }

            // Step 3: Process and update DB within a short transaction
            DB::beginTransaction();

            $this->processAccounts($radiusUsers, $radiusSessions, $stats);

            // Update the radius config timestamp to reflect last sync
            if ($radiusConfigs->first()) {
                $radiusConfigs->first()->touch();
            }

            DB::commit();

            return $stats;

        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            Log::error('RADIUS Status Sync Failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            \Log::channel('radiusrelated')->error('[STATUS SYNC CRITICAL] Global failure: ' . $e->getMessage());
            throw $e;
        }
    }

    private function syncAccountsToOnlineStatus(array &$stats): void
    {
        $inserted = DB::statement("
            INSERT IGNORE INTO online_status (account_id, account_no, username, created_at, updated_at)
            SELECT ba.id, ba.account_no, td.username, NOW(), NOW()
            FROM billing_accounts ba
            LEFT JOIN technical_details td ON ba.id = td.account_id
            WHERE td.username IS NOT NULL AND TRIM(td.username) != ''
        ");

        if ($inserted) {
            $insertedCount = DB::select("SELECT ROW_COUNT() as count")[0]->count ?? 0;
            $stats['inserted'] = $insertedCount;
            Log::info('Synced new accounts to online_status', ['count' => $insertedCount]);
        }
    }

    private function fetchRadiusUsers($radiusConfigs): array
    {
        $users = [];
        $response = $this->callRadiusApi('/rest/user-manage/user', 'GET', $radiusConfigs);

        if ($response && is_array($response)) {
            foreach ($response as $user) {
                $username = $user['name'] ?? null;
                if ($username) {
                    $users[$username] = [
                        'id' => $user['.id'] ?? '',
                        'group' => $user['group'] ?? '',
                        'disabled' => ($user['disabled'] ?? 'false') === 'true'
                    ];
                }
            }
            Log::info('Fetched RADIUS users', ['count' => count($users)]);
        }

        return $users;
    }

    private function fetchRadiusSessions($radiusConfigs): array
    {
        $sessions = [];
        $response = $this->callRadiusApi('/rest/user-manage/session', 'GET', $radiusConfigs);

        if ($response && is_array($response)) {
            foreach ($response as $session) {
                $username = $session['user'] ?? null;
                if ($username) {
                    if (!isset($sessions[$username])) {
                        $sessions[$username] = [
                            'active_count' => 0,
                            'last_session' => null
                        ];
                    }
                    
                    $sessions[$username]['active_count']++;
                    $sessions[$username]['last_session'] = [
                        'session_id' => $session['.id'] ?? '',
                        'ip' => $session['user-address'] ?? '',
                        'mac' => $session['calling-station-id'] ?? '',
                        'upload' => $session['upload'] ?? 0,
                        'download' => $session['download'] ?? 0
                    ];
                }
            }
            Log::info('Fetched RADIUS sessions', ['count' => count($sessions)]);
        }

        return $sessions;
    }

    private function processAccounts(array $radiusUsers, array $radiusSessions, array &$stats): void
    {
        $accounts = DB::table('billing_accounts as ba')
            ->leftJoin('technical_details as td', 'ba.id', '=', 'td.account_id')
            ->select('ba.id as account_id', 'ba.account_no', 'td.username')
            ->whereNotNull('td.username')
            ->whereRaw("TRIM(td.username) <> ''")
            ->get();

        Log::info('Processing accounts for RADIUS sync', ['count' => count($accounts)]);

        foreach ($accounts as $account) {
            try {
                $username = trim($account->username ?? '');
                if ($username === '') {
                    // Skip records with empty usernames
                    continue;
                }
                $accountNo = $account->account_no;

                $status = 'Offline';
                $group = null;
                $sessionId = null;
                $ip = null;
                $mac = null;
                $download = null;
                $mac = null;
                $download = null;
                $upload = null;
                $activeSessions = 0;

                if (isset($radiusUsers[$username])) {
                    $user = $radiusUsers[$username];
                    $group = $user['group'];
                    $hasSession = isset($radiusSessions[$username]);

                    // NEW ALGO
                    $isRestricted = ($group === 'Restricted' || $group === 'Mikrotik-Group:Restricted');
                    $isDisconnected = ($group === 'Disconnected' || $group === 'Mikrotik-Group:Disconnected');

                    if ($isRestricted) {
                        $status = 'Restricted';
                        $stats['restricted']++;
                    } elseif ($isDisconnected) {
                        $status = 'Disconnected';
                        $stats['disconnected']++;
                    } else {
                        if ($hasSession) {
                            $status = 'Online';
                            $stats['online']++;
                        } else {
                            $status = 'Offline';
                            $stats['offline']++;
                        }
                    }

                    if ($hasSession) {
                        $sessionInfo = $radiusSessions[$username];
                        $activeSessions = $sessionInfo['active_count'];
                        $session = $sessionInfo['last_session'];
                        
                        $sessionId = $session['session_id'];
                        $ip = $session['ip'];
                        $mac = $session['mac'];
                        $download = $session['download'];
                        $upload = $session['upload'];
                    }
                } else {
                    $status = 'Not Found';
                    $stats['not_found']++;
                }

                DB::table('online_status')
                    ->updateOrInsert(
                        ['account_id' => $account->account_id],
                        [
                            'account_no' => $accountNo,
                            'username' => $username,
                            'session_status' => $status,
                            'session_group' => $group,
                            'session_id' => $sessionId,
                            'ip_address' => $ip,
                            'session_mac_address' => $mac,
                            'total_download' => $download,
                            'total_upload' => $upload,
                            'active_sessions' => $activeSessions,
                            'updated_at' => now(),
                            'updated_by_user' => 'system'
                        ]
                    );

                $stats['updated']++;

            } catch (\Exception $e) {
                $stats['errors']++;
                Log::error('Error processing account for RADIUS sync', [
                    'account_no' => $account->account_no ?? 'unknown',
                    'username' => $account->username ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
                \Log::channel('radiusrelated')->error('[STATUS SYNC ACCOUNT ERROR] Account: ' . ($account->account_no ?? 'Unknown') . ' - Error: ' . $e->getMessage());
            }
        }

        $stats['synced'] = $stats['updated'];
    }

    private function callRadiusApi(string $path, string $method, $radiusConfigs): ?array
    {
        foreach ($radiusConfigs as $config) {
            $protocols = ['https', 'http'];

            foreach ($protocols as $protocol) {
                $url = sprintf('%s://%s:%s%s', $protocol, $config->ip, $config->port, $path);

                for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
                    try {
                        $response = Http::withBasicAuth($config->username, $config->password)
                            ->withOptions([
                                'verify' => false,
                                'timeout' => 5,
                            ])
                            ->$method($url);

                        if ($response->successful()) {
                            return $response->json();
                        }

                        Log::warning('RADIUS API request failed', [
                            'url' => $url,
                            'attempt' => $attempt,
                            'status' => $response->status(),
                            'body' => $response->body()
                        ]);

                    } catch (\Exception $e) {
                        Log::warning('RADIUS API request exception', [
                            'url' => $url,
                            'attempt' => $attempt,
                            'error' => $e->getMessage()
                        ]);
                    }

                    if ($attempt < self::MAX_RETRIES) {
                        sleep(self::RETRY_DELAY);
                    }
                }
            }
        }

        $errorMsg = 'Failed to connect to RADIUS API after trying all configs and protocols. Path: ' . $path;
        \Log::channel('radiusrelated')->error('[STATUS SYNC API FAILED] ' . $errorMsg);
        throw new \RuntimeException($errorMsg);
    }
}


