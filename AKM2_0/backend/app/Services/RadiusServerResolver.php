<?php

namespace App\Services;

use App\Models\RadiusConfig;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Central place for deciding WHICH radius_config a RADIUS operation should hit.
 *
 * Two strategies live here so the rest of the app never re-implements them:
 *
 *  1. City-based selection (resolveForCity) — used when CREATING an account, where
 *     the account does not exist on any server yet so we must place it deliberately.
 *  2. Failover lookup (findConfigForAccount) — used for operations on an EXISTING
 *     account: search radius_config #1, then #2, ... and operate only where the
 *     account is actually found.
 *
 * "radius_config #1 / #2" means the records ordered by id (see orderedConfigs()).
 */
class RadiusServerResolver
{
    /**
     * City => radius_config position (1-based) mapping.
     *
     * Keys are compared case-insensitively and whitespace-normalized (see normalizeCity).
     * Add new cities here — no business logic elsewhere needs to change.
     */
    private const CITY_SERVER_MAP = [
        'santa maria' => 1,
        'norzagaray'  => 1,
        'angat'       => 2,
    ];

    /**
     * Fallback position used when a city has no explicit mapping (or is unknown).
     */
    private const DEFAULT_SERVER_POSITION = 1;

    private string $logName = 'Radius_Resolver';

    /**
     * All radius_config records that apply to the given organization, ordered by id.
     *
     * Prefers organization-specific configs and falls back to the shared (null-org)
     * ones when none exist — mirroring the selection pattern used elsewhere in the app.
     * The returned collection is zero-indexed; position #1 is index 0, #2 is index 1, etc.
     */
    public function orderedConfigs(?int $organizationId = null): Collection
    {
        if ($organizationId !== null) {
            $configs = RadiusConfig::where('organization_id', $organizationId)->orderBy('id')->get();
            if ($configs->isNotEmpty()) {
                return $configs->values();
            }
        }

        return RadiusConfig::whereNull('organization_id')->orderBy('id')->get()->values();
    }

    /**
     * Get the radius_config at a 1-based position (#1, #2, ...).
     */
    public function configByPosition(int $position, ?int $organizationId = null): ?RadiusConfig
    {
        $configs = $this->orderedConfigs($organizationId);
        return $configs->get($position - 1);
    }

    /**
     * Pick the radius_config for a NEW account based on the customer's city.
     *
     * Falls back to the default position when the city is unknown/unmapped, and to
     * the first available config if the mapped position does not exist.
     */
    public function resolveForCity(?string $city, ?int $organizationId = null): ?RadiusConfig
    {
        $configs = $this->orderedConfigs($organizationId);

        if ($configs->isEmpty()) {
            $this->log('error', 'No radius_config records available for city selection', ['city' => $city]);
            return null;
        }

        $normalized = $this->normalizeCity($city);
        $position = self::CITY_SERVER_MAP[$normalized] ?? self::DEFAULT_SERVER_POSITION;
        $mapped = $normalized !== '' && isset(self::CITY_SERVER_MAP[$normalized]);

        // Requested position may not exist (e.g. only one server configured) — fall back to #1.
        $config = $configs->get($position - 1) ?? $configs->first();

        $this->log('info', 'Selected RADIUS server by city', [
            'city'              => $city,
            'normalized_city'   => $normalized,
            'mapped'            => $mapped,
            'position'          => $position,
            'radius_config_id'  => $config->id ?? null,
            'radius_ip'         => $config->ip ?? null,
        ]);

        return $config;
    }

    /**
     * Locate an existing account across the configured RADIUS servers (#1, then #2, ...).
     *
     * Returns details of the FIRST server where the account exists, or null if it is
     * not found on any of them. A connection/API error on one server is logged and
     * skipped so the remaining servers are still checked. Only a lookup (GET) is
     * performed here — no mutating call — so this never duplicates the actual operation.
     *
     * @return array{config: RadiusConfig, position: int, base_url: string, radius_id: string, group: string}|null
     */
    public function findConfigForAccount(string $username, ?int $organizationId = null): ?array
    {
        $configs = $this->orderedConfigs($organizationId);

        if ($configs->isEmpty()) {
            $this->log('error', 'No radius_config records available for account lookup', ['username' => $username]);
            return null;
        }

        foreach ($configs as $index => $config) {
            $position = $index + 1;
            $found = $this->lookupOnConfig($config, $username, $position);

            if ($found !== null) {
                $this->log('info', 'Account located on RADIUS server', [
                    'username'         => $username,
                    'position'         => $position,
                    'radius_config_id' => $config->id,
                    'radius_ip'        => $config->ip,
                ]);

                return [
                    'config'    => $config,
                    'position'  => $position,
                    'base_url'  => $found['base_url'],
                    'radius_id' => $found['radius_id'],
                    'group'     => $found['group'],
                ];
            }
        }

        $this->log('warning', 'Account not found on any RADIUS server', ['username' => $username]);
        return null;
    }

    /**
     * Attempt to find the user on a single config, trying the configured protocol first
     * then the alternate (https <-> http), tolerant of connection failures.
     *
     * @return array{base_url: string, radius_id: string, group: string}|null
     */
    private function lookupOnConfig(RadiusConfig $config, string $username, int $position): ?array
    {
        $path = '/rest/user-manage/user/' . urlencode($username);

        foreach ($this->baseUrlsFor($config) as $baseUrl) {
            $this->log('info', 'Searching for account on RADIUS server', [
                'username'  => $username,
                'position'  => $position,
                'base_url'  => $baseUrl,
            ]);

            try {
                $response = Http::withOptions(['verify' => false])
                    ->withBasicAuth($config->username, $config->password)
                    ->connectTimeout(2)
                    ->timeout(4)
                    ->get($baseUrl . $path);

                if ($response->successful()) {
                    $data = $response->json();
                    if (is_array($data) && isset($data['.id'])) {
                        return [
                            'base_url'  => $baseUrl,
                            'radius_id' => $data['.id'],
                            'group'     => $data['group'] ?? '',
                        ];
                    }
                    // Reachable but the user is not here — no need to try the alternate protocol.
                    return null;
                }

                // 404 etc. — server reachable, user absent on this config.
                if ($response->status() === 404) {
                    return null;
                }

                $this->log('warning', 'Unexpected HTTP status during account lookup', [
                    'username' => $username,
                    'base_url' => $baseUrl,
                    'status'   => $response->status(),
                ]);
            } catch (Throwable $e) {
                // Connection/timeout error — try the alternate protocol / next server.
                $this->log('error', 'Connection error during account lookup', [
                    'username' => $username,
                    'base_url' => $baseUrl,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    /**
     * Build the base URL(s) to try for a config: configured protocol first, then the alternate.
     */
    public function baseUrlsFor(RadiusConfig $config): array
    {
        $protocol = strtolower($config->ssl_type ?: 'https');
        $primary = "{$protocol}://{$config->ip}:{$config->port}";
        $alternate = $protocol === 'https'
            ? "http://{$config->ip}:{$config->port}"
            : "https://{$config->ip}:{$config->port}";

        return [$primary, $alternate];
    }

    /**
     * Normalize a city name for map lookups (trim, collapse whitespace, lowercase).
     */
    private function normalizeCity(?string $city): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $city ?? '')));
    }

    private function log(string $level, string $message, array $context = []): void
    {
        Log::channel('radiusrelated')->{$level}("[{$this->logName}] {$message}", $context);
    }
}
