<?php

namespace App\Http\Controllers;

use App\Models\SettingsAutoReport;
use Illuminate\Http\Request;

class SettingsAutoReportController extends Controller
{
    public function show()
    {
        $setting = SettingsAutoReport::firstOrCreate(['id' => 1], ['is_enabled' => true]);

        return response()->json([
            'success' => true,
            'data' => $setting,
        ]);
    }

    public function update(Request $request)
    {
        $authUser = auth()->user();
        $roleId = $authUser ? $authUser->role_id : null;
        $isAdmin = in_array($roleId, [1, 7], true);

        if (!$isAdmin) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only administrators can change this setting.',
            ], 403);
        }

        $validated = $request->validate([
            'is_enabled' => 'required|boolean',
        ]);

        $setting = SettingsAutoReport::firstOrCreate(['id' => 1], ['is_enabled' => true]);
        $setting->is_enabled = $validated['is_enabled'];
        $setting->updated_by = $authUser->username ?? $authUser->email_address ?? 'system';
        $setting->save();

        return response()->json([
            'success' => true,
            'message' => 'Auto Send Report setting updated successfully.',
            'data' => $setting,
        ]);
    }
}
