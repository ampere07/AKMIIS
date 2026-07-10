<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\SmartOlt;

class SmartOltService
{
    /**
     * Clear (delete) the ONU name in SmartOLT for a given Serial Number.
     *
     * Mirrors SmartOltController::deleteOnuNameBySn — resolves the SN to the
     * ONU's unique external ID, then posts an empty name to update_location_details.
     *
     * Best-effort: never throws. Returns a status string:
     *   'success'      - name cleared in SmartOLT
     *   'skipped'      - no SN provided or SmartOLT not configured
     *   'not_found'    - SN not found in SmartOLT
     *   'api_error'    - SmartOLT returned an error
     *   'http_error'   - non-2xx HTTP response
     *   'exception'    - connection/other exception
     */
    public function clearOnuNameBySn(?string $sn): string
    {
        if (empty($sn)) {
            Log::channel('smartoltrelated')->info('[SMARTOLT CLEAR NAME] Skipped — no SN provided');
            return 'skipped';
        }

        try {
            $config = SmartOlt::first();

            if (!$config) {
                Log::channel('smartoltrelated')->warning('[SMARTOLT CLEAR NAME] Skipped — SmartOLT not configured');
                return 'skipped';
            }

            $subDomain = $config->sub_domain;
            $token = $config->token;

            // Step 1: Retrieve ONU details using Serial Number
            $getOnuUrl = "https://{$subDomain}.smartolt.com/api/onu/get_onus_details_by_sn/{$sn}";
            Log::channel('smartoltrelated')->info("[SMARTOLT CLEAR NAME] Getting ONU details: $getOnuUrl");

            $getOnuResponse = Http::withHeaders(['X-Token' => $token])->get($getOnuUrl);

            if (!$getOnuResponse->successful()) {
                Log::channel('smartoltrelated')->error('[SMARTOLT CLEAR NAME] Failed to fetch ONU details: ' . $getOnuResponse->status());
                return 'http_error';
            }

            $getOnuData = $getOnuResponse->json();

            if (isset($getOnuData['status']) && $getOnuData['status'] === false) {
                $errorMsg = $getOnuData['error'] ?? 'Unknown error';
                Log::channel('smartoltrelated')->warning('[SMARTOLT CLEAR NAME] Get ONU details API error: ' . $errorMsg, ['sn' => $sn]);
                return 'api_error';
            }

            if (!isset($getOnuData['onus']) || !is_array($getOnuData['onus']) || empty($getOnuData['onus'])) {
                Log::channel('smartoltrelated')->warning('[SMARTOLT CLEAR NAME] SN not found in SmartOLT', ['sn' => $sn]);
                return 'not_found';
            }

            $onuDetails = $getOnuData['onus'][0];
            $onuExternalId = $onuDetails['unique_external_id'] ?? $onuDetails['onu_external_id'] ?? $onuDetails['id'] ?? null;

            if (!$onuExternalId) {
                Log::channel('smartoltrelated')->error('[SMARTOLT CLEAR NAME] Could not determine external ID', ['sn' => $sn]);
                return 'api_error';
            }

            // Step 2: Call update_location_details to set the name to empty
            $updateUrl = "https://{$subDomain}.smartolt.com/api/onu/update_location_details/{$onuExternalId}";
            Log::channel('smartoltrelated')->info("[SMARTOLT CLEAR NAME] Clearing name: $updateUrl for external ID: $onuExternalId");

            $updateResponse = Http::withHeaders(['X-Token' => $token])
                ->asForm()
                ->post($updateUrl, ['name' => '']);

            if (!$updateResponse->successful()) {
                Log::channel('smartoltrelated')->error('[SMARTOLT CLEAR NAME] Clear name HTTP error: ' . $updateResponse->status(), [
                    'sn' => $sn,
                    'onu_external_id' => $onuExternalId,
                ]);
                return 'http_error';
            }

            $updateData = $updateResponse->json();

            if (isset($updateData['status']) && $updateData['status'] === false) {
                $errorMsg = $updateData['error'] ?? 'Failed to clear name';
                Log::channel('smartoltrelated')->warning('[SMARTOLT CLEAR NAME] Clear name API error: ' . $errorMsg, [
                    'sn' => $sn,
                    'onu_external_id' => $onuExternalId,
                ]);
                return 'api_error';
            }

            Log::channel('smartoltrelated')->info('[SMARTOLT CLEAR NAME] Success', [
                'sn' => $sn,
                'onu_external_id' => $onuExternalId,
            ]);

            return 'success';
        } catch (\Exception $e) {
            Log::channel('smartoltrelated')->error('[SMARTOLT CLEAR NAME] Exception: ' . $e->getMessage(), ['sn' => $sn]);
            return 'exception';
        }
    }
}
