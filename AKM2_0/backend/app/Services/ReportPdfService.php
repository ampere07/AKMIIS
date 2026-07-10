<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReportPdfService
{
    public function generateSummaryPdf($report)
    {
        $csvService = new ReportCsvService();
        $metrics = $csvService->getSummaryMetrics($report->date_range);

        $data = [
            'reportName' => $report->report_name,
            'reportType' => $report->report_type,
            'dateRange'  => $report->date_range,
            'createdBy'  => $report->created_by,
            'metrics'    => $metrics,
            'generatedAt'=> now()->format('F d, Y h:i A')
        ];

        $pdf = Pdf::loadView('pdf.summary_report', $data)->setPaper('a4', 'portrait');
        
        $fileName = 'Summary_Report_' . time() . '.pdf';
        $tempPath = sys_get_temp_dir() . '/' . $fileName;
        $pdf->save($tempPath);

        return $tempPath;
    }

    public function generateTabularPdf($report)
    {
        $csvService = new ReportCsvService();
        [$startDate, $endDate] = $csvService->parseDateRange($report->date_range);
        
        $tableName = $this->getTableNameForType($report->report_type);
        if (!$tableName) {
            throw new \Exception('Unknown report type: ' . $report->report_type);
        }

        // For work order, fallback to work_orders if work_order doesn't exist
        if ($tableName === 'work_order' && !Schema::hasTable($tableName)) {
            $tableName = 'work_orders';
        }

        if (!Schema::hasTable($tableName)) {
            throw new \Exception('Table not found: ' . $tableName);
        }

        $columns = Schema::getColumnListing($tableName);
        $query = DB::table($tableName);

        if ($startDate && $endDate) {
            $dateCol = $this->getDateColumnForTable($tableName);
            $query->whereBetween($dateCol, [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
        }

        // Limit to 2000 records for PDF to avoid memory issues (tabluar PDF can be heavy)
        $rows = $query->orderBy('id', 'desc')->limit(2000)->get();

        $data = [
            'reportName' => $report->report_name,
            'reportType' => $report->report_type,
            'dateRange'  => $report->date_range,
            'createdBy'  => $report->created_by,
            'headers'    => $columns,
            'rows'       => $rows,
            'generatedAt'=> now()->format('F d, Y h:i A')
        ];

        $pdf = Pdf::loadView('pdf.tabular_report', $data)->setPaper('a4', 'landscape');
        
        $fileName = str_replace(' ', '_', $report->report_type) . '_Report_' . time() . '.pdf';
        $tempPath = sys_get_temp_dir() . '/' . $fileName;
        $pdf->save($tempPath);

        return $tempPath;
    }

    private function getTableNameForType($type)
    {
        $map = [
            'manual transaction' => 'transactions',
            'payment portal' => 'payment_portal_logs',
            'inventory' => 'inventory_logs',
            'job order' => 'job_orders',
            'service order' => 'service_orders',
            'work order' => 'work_order',
        ];

        $key = strtolower($type);
        return $map[$key] ?? null;
    }

    private function getDateColumnForTable($tableName)
    {
        $map = [
            'transactions' => 'payment_date',
            'payment_portal_logs' => 'created_at',
            'inventory_logs' => 'created_at',
            'job_orders' => 'created_at',
            'service_orders' => 'created_at',
            'work_order' => 'created_at',
            'work_orders' => 'created_at',
        ];
        return $map[$tableName] ?? 'created_at';
    }
}
