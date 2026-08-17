<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SmartOltReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * HTTP surface for the SmartOLT Tool.
 *
 * Validates, delegates to SmartOltReconciliationService, and formats the response.
 * The permanent-deletion endpoint additionally requires the literal confirmation
 * phrase, so a mis-posted request can never unprovision an ONU.
 */
class SmartOltReconciliationController extends Controller
{
    /** SuperAdmin sees every organization; everyone else is scoped to their own. */
    private const SUPERADMIN_ROLE_ID = 7;

    public function __construct(private SmartOltReconciliationService $service)
    {
    }

    /**
     * GET /api/smartolt-reconciliation/state
     */
    public function state(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->service->getState(
                $request->boolean('include_rows'),
                $this->organizationId($request)
            ),
        ]);
    }

    /**
     * GET /api/smartolt-reconciliation/optical-power
     */
    public function opticalPower(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'external_ids'   => ['nullable', 'array', 'max:200'],
            'external_ids.*' => ['string', 'max:120'],
            'limit'          => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $result = $this->service->getOpticalPower(
            $validated['external_ids'] ?? [],
            $validated['limit'] ?? 25
        );

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * GET /api/smartolt-reconciliation/alignment-preview
     */
    public function alignmentPreview(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->service->getAlignmentPreview($this->organizationId($request)),
        ]);
    }

    /**
     * GET /api/smartolt-reconciliation/mac-alignment
     *
     * The MAC-matched alignment pass: SmartOLT bridge MAC against the RADIUS
     * calling-station-id, proposing the subscriber's RADIUS username as the ONU name.
     */
    public function macAlignment(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->service->getMacAlignmentPreview($this->organizationId($request)),
        ]);
    }

    /**
     * GET /api/smartolt-reconciliation/profile-preview
     */
    public function profilePreview(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->service->getProfilePreview($this->organizationId($request)),
        ]);
    }

    /**
     * GET /api/smartolt-reconciliation/cleanup-preview
     */
    public function cleanupPreview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'offline_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        return response()->json([
            'success' => true,
            'data'    => $this->service->getCleanupPreview(
                $validated['offline_days'] ?? SmartOltReconciliationService::DEFAULT_OFFLINE_DAYS,
                $this->organizationId($request)
            ),
        ]);
    }

    /**
     * POST /api/smartolt-reconciliation/start-job
     */
    public function startJob(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type'                 => ['required', 'string', 'in:' . implode(',', SmartOltReconciliationService::JOB_TYPES)],
            'confirmation'         => ['nullable', 'string', 'max:32'],
            'offline_days'         => ['nullable', 'integer', 'min:1', 'max:3650'],
            // Optical scan only: re-read every ONU instead of just the uncrawled ones.
            'rescan'               => ['nullable', 'boolean'],
            'external_ids'         => ['nullable', 'array', 'max:5000'],
            'external_ids.*'       => ['string', 'max:120'],
            'items'                => ['nullable', 'array', 'max:5000'],
            'items.*.external_id'  => ['required_with:items', 'string', 'max:120'],
            'items.*.new_name'     => ['nullable', 'string', 'max:128'],
            'items.*.new_address'  => ['nullable', 'string', 'max:255'],
            'items.*.new_contact'  => ['nullable', 'string', 'max:100'],
            'items.*.new_latitude'  => ['nullable', 'string', 'max:32'],
            'items.*.new_longitude' => ['nullable', 'string', 'max:32'],
            'items.*.address_changed' => ['nullable', 'boolean'],
            'items.*.contact_changed' => ['nullable', 'boolean'],
            'items.*.coords_changed'  => ['nullable', 'boolean'],
        ]);

        $type = $validated['type'];
        unset($validated['type']);

        $result = $this->service->startJob($type, $validated, $this->organizationId($request));

        return response()->json([
            'success' => (bool) $result['success'],
            'skipped' => (bool) ($result['skipped'] ?? false),
            'message' => $result['message'],
            'job'     => $result['job'] ?? null,
        ], $result['success'] ? 200 : 422);
    }

    /**
     * POST /api/smartolt-reconciliation/process-job
     */
    public function processJob(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'job_id' => ['required', 'integer', 'min:1'],
        ]);

        $result = $this->service->processJob($validated['job_id']);

        return response()->json([
            'success' => (bool) $result['success'],
            'skipped' => (bool) ($result['skipped'] ?? false),
            'message' => $result['message'],
            'job'     => $result['job'] ?? null,
        ], $result['success'] ? 200 : 422);
    }

    /**
     * POST /api/smartolt-reconciliation/abort-job
     */
    public function abortJob(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'job_id' => ['required', 'integer', 'min:1'],
        ]);

        $result = $this->service->abortJob($validated['job_id']);

        return response()->json([
            'success' => (bool) $result['success'],
            'skipped' => (bool) ($result['skipped'] ?? false),
            'message' => $result['message'],
            'job'     => $result['job'] ?? null,
        ], $result['success'] ? 200 : 422);
    }

    /**
     * POST /api/smartolt-reconciliation/undo
     */
    public function undo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'log_id' => ['required', 'integer', 'min:1'],
        ]);

        $result = $this->service->undoOperation($validated['log_id']);

        return response()->json([
            'success' => (bool) $result['success'],
            'skipped' => (bool) ($result['skipped'] ?? false),
            'message' => $result['message'],
        ], $result['success'] ? 200 : 422);
    }

    /**
     * GET /api/smartolt-reconciliation/logs
     */
    public function logs(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        return response()->json([
            'success' => true,
            'data'    => $this->service->getLogs($validated['limit'] ?? 50, $this->organizationId($request)),
        ]);
    }

    /**
     * GET /api/smartolt-reconciliation/export
     */
    public function export(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'dataset' => ['nullable', 'string', 'in:inventory,alignment,profile,cleanup'],
        ]);

        $export = $this->service->exportCsv(
            $validated['dataset'] ?? 'inventory',
            $this->organizationId($request)
        );

        return response()->streamDownload(function () use ($export): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, $export['headers']);
            foreach ($export['rows'] as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        }, $export['filename'], ['Content-Type' => 'text/csv']);
    }

    /**
     * The organization this request is confined to, or null for SuperAdmin.
     */
    private function organizationId(Request $request): ?int
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        if ((int) ($user->role_id ?? 0) === self::SUPERADMIN_ROLE_ID) {
            return null;
        }

        return $user->organization_id !== null ? (int) $user->organization_id : null;
    }
}
