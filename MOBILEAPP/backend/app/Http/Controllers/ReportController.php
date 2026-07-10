<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Report;
use App\Services\GoogleDriveService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReportController extends Controller
{
    public function index()
    {
        $reports = Report::orderBy('created_at', 'desc')->get();
        return response()->json([
            'success' => true,
            'data' => $reports
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'report_name' => 'required|string|max:255',
            'report_type' => 'required|string|max:100',
            'report_schedule' => 'nullable|string|max:100',
            'report_time' => 'nullable|string',
            'day' => 'nullable|string|max:100',
            'send_to' => 'nullable|string|max:255',
            'date_range' => 'nullable|string|max:100',
            'created_by' => 'nullable|string|max:255',
        ]);

        $report = new Report($validated);
        $report->save();

        try {
            $pdfService = new \App\Services\ReportPdfService();
            if (strtolower($report->report_type) === 'summary') {
                $tempPath = $pdfService->generateSummaryPdf($report);
            } else {
                $tempPath = $pdfService->generateTabularPdf($report);
            }
            $fileName = basename($tempPath);

            // Upload to GDrive
            $driveService = resolve(GoogleDriveService::class);
            $folderId = $driveService->findFolder('Reports') ?? $driveService->createFolder('Reports');
            $fileUrl = $driveService->uploadFile($tempPath, $folderId, $fileName, 'application/pdf');

            // Save URLs
            $report->file_url = $fileUrl;
            $report->save();

            // Cleanup
            @unlink($tempPath);

            return response()->json([
                'success' => true,
                'message' => 'Report created successfully.',
                'data' => $report
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Report generated but PDF failed: ' . $e->getMessage(),
            ], 500);
        }
    }

}
