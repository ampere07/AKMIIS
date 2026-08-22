<?php

namespace App\Http\Controllers;

use App\Support\ReportSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The legacy `/settings/auto-report` endpoint, kept alive as a bridge.
 *
 * The reporting engine's master switch now lives in `system_config` behind
 * {@see ReportSettings}, which is what QueueScheduledReports consults and what the
 * Reports screen reads and writes through `/reports/settings`. This controller used
 * to own a second copy of that flag in the `settings_auto_report` table.
 *
 * Two switches for one behaviour is worse than either: whichever one an operator
 * happened to toggle, the other kept its own opinion, and only one of them actually
 * decided whether clients got their reports. So this now reads and writes the same
 * setting as the new screen. The `settings_auto_report` table is deliberately left in
 * place but dormant - dropping it would discard the historical `updated_by` trail, and
 * nothing reads it any more.
 *
 * Kept registered rather than deleted so any client still pointed at the old path
 * keeps working instead of receiving a 404 and silently failing to change anything.
 */
class SettingsAutoReportController extends Controller
{
    /** Role ids permitted to change the master switch: Administrator and SuperAdmin. */
    private const ADMIN_ROLE_IDS = [1, 7];

    public function show(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'is_enabled' => ReportSettings::autoSendEnabled(),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $authUser = auth()->user();
        $roleId = $authUser ? (int) ($authUser->role_id ?? 0) : null;

        if (!in_array($roleId, self::ADMIN_ROLE_IDS, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only administrators can change this setting.',
            ], 403);
        }

        $validated = $request->validate([
            'is_enabled' => 'required|boolean',
        ]);

        ReportSettings::setAutoSendEnabled(
            (bool) $validated['is_enabled'],
            (string) ($authUser->username ?? $authUser->email_address ?? 'system')
        );

        return response()->json([
            'success' => true,
            'message' => 'Auto Send Report setting updated successfully.',
            'data'    => [
                'is_enabled' => ReportSettings::autoSendEnabled(),
            ],
        ]);
    }
}
