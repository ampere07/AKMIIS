<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReportCsvService
{
    public function generateFile($reportType, $dateRange = null)
    {
        $fileName = 'Report_' . time() . '_' . str_replace(' ', '_', $reportType) . '.csv';
        $tempPath = sys_get_temp_dir() . '/' . $fileName;
        
        $fileHandle = fopen($tempPath, 'w');

        [$startDate, $endDate] = $this->parseDateRange($dateRange);

        if (strtolower($reportType) === 'summary') {
            $this->generateSummaryCSV($fileHandle, $dateRange);
        } else {
            $tableName = $this->getTableNameForType($reportType);
            if ($tableName) {
                $this->generateTableCSV($fileHandle, $tableName, $startDate, $endDate);
            } else {
                fclose($fileHandle);
                @unlink($tempPath);
                throw new \Exception('Unknown report type.');
            }
        }

        fclose($fileHandle);
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

    public function parseDateRange($dateRange)
    {
        $startDate = null;
        $endDate = null;
        if ($dateRange && strpos($dateRange, ' to ') !== false) {
            $parts = explode(' to ', $dateRange);
            if (count($parts) === 2) {
                $startDate = trim($parts[0]);
                $endDate = trim($parts[1]);
            }
        }
        return [$startDate, $endDate];
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

    private function generateTableCSV($fileHandle, $tableName, $startDate = null, $endDate = null)
    {
        // For work order, fallback to work_orders if work_order doesn't exist
        if ($tableName === 'work_order' && !Schema::hasTable($tableName)) {
            $tableName = 'work_orders';
        }

        if (!Schema::hasTable($tableName)) {
            fputcsv($fileHandle, ['Error: Table not found in database.']);
            return;
        }

        $columns = Schema::getColumnListing($tableName);
        fputcsv($fileHandle, $columns);

        $query = DB::table($tableName);

        if ($startDate && $endDate) {
            $dateCol = $this->getDateColumnForTable($tableName);
            $query->whereBetween($dateCol, [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
        }

        $query->orderBy('id')->chunk(500, function ($rows) use ($fileHandle, $columns) {
            foreach ($rows as $row) {
                $rowArray = (array) $row;
                $csvRow = [];
                foreach ($columns as $column) {
                    $csvRow[] = $rowArray[$column] ?? null;
                }
                fputcsv($fileHandle, $csvRow);
            }
        });
    }

    private function generateSummaryCSV($fileHandle, $dateRange = null)
    {
        $metrics = $this->getSummaryMetrics($dateRange);

        // Write to CSV (Header Row)
        fputcsv($fileHandle, array_keys($metrics));
        
        // Write to CSV (Data Row)
        fputcsv($fileHandle, array_values($metrics));
    }

    public function getSummaryMetrics($dateRange = null)
    {
        [$startDate, $endDate] = $this->parseDateRange($dateRange);
        $metrics = [];
        $hasRange = $startDate && $endDate;
        $range = $hasRange ? [$startDate . ' 00:00:00', $endDate . ' 23:59:59'] : null;

        // 1. Invoices
        if (Schema::hasTable('invoices')) {
            $qUnpaid = DB::table('invoices')->where('status', 'Unpaid');
            if ($hasRange) $qUnpaid->whereBetween('invoice_date', $range);
            $metrics['Total Unpaid Invoices (Count)'] = $qUnpaid->count();
            $metrics['Total Unpaid Invoices (Amount)'] = floatval($qUnpaid->sum('total_amount'));

            $qPaid = DB::table('invoices')->where('status', 'Paid');
            if ($hasRange) $qPaid->whereBetween('invoice_date', $range);
            $metrics['Total Paid Invoices (Count)'] = $qPaid->count();
            $metrics['Total Paid Invoices (Amount)'] = floatval($qPaid->sum('total_amount'));
        } else {
            $metrics['Total Unpaid Invoices (Count)'] = 0;
            $metrics['Total Unpaid Invoices (Amount)'] = 0.0;
            $metrics['Total Paid Invoices (Count)'] = 0;
            $metrics['Total Paid Invoices (Amount)'] = 0.0;
        }

        // 2. Service Orders (Pullout concern)
        if (Schema::hasTable('service_orders')) {
            $qPullAll = DB::table('service_orders')->where('repair_category', 'pullout');
            $qPullProg = DB::table('service_orders')->where('repair_category', 'pullout')->where('visit_status', 'In Progress');
            $qPullDone = DB::table('service_orders')->where('repair_category', 'pullout')->where('visit_status', 'Done');
            $qPullFail = DB::table('service_orders')->where('repair_category', 'pullout')->where('visit_status', 'Failed');

            if ($hasRange) {
                $qPullAll->whereBetween('created_at', $range);
                $qPullProg->whereBetween('created_at', $range);
                $qPullDone->whereBetween('created_at', $range);
                $qPullFail->whereBetween('created_at', $range);
            }

            $metrics['Number of Pull Out Concern Service Orders'] = $qPullAll->count();
            $metrics['Pull Out In Progress'] = $qPullProg->count();
            $metrics['Pull Out Done'] = $qPullDone->count();
            $metrics['Pull Out Failed'] = $qPullFail->count();
        } else {
            $metrics['Number of Pull Out Concern Service Orders'] = 0;
            $metrics['Pull Out In Progress'] = 0;
            $metrics['Pull Out Done'] = 0;
            $metrics['Pull Out Failed'] = 0;
        }

        // 3. Payment Portal Logs
        if (Schema::hasTable('payment_portal_logs')) {
            $qPPL = DB::table('payment_portal_logs');
            if ($hasRange) $qPPL->whereBetween('created_at', $range);
            $metrics['Payment Portal Logs Count'] = $qPPL->count();
            $metrics['Payment Portal Logs Total Amount'] = floatval($qPPL->sum('total_amount'));
        } else {
            $metrics['Payment Portal Logs Count'] = 0;
            $metrics['Payment Portal Logs Total Amount'] = 0.0;
        }

        // 4. Transactions & Payment Methods
        if (Schema::hasTable('transactions')) {
            $qTx = DB::table('transactions');
            if ($hasRange) $qTx->whereBetween('payment_date', $range);
            $metrics['Transactions Count'] = $qTx->count();
            $metrics['Transactions Total Amount'] = floatval($qTx->sum('received_payment'));

            // Payment Methods breakdown
            $qPM = DB::table('transactions')
                ->select('payment_method', DB::raw('count(*) as count'), DB::raw('sum(received_payment) as amount'));
            if ($hasRange) $qPM->whereBetween('payment_date', $range);
            $pmList = $qPM->groupBy('payment_method')->get();

            foreach ($pmList as $pm) {
                $method = $pm->payment_method ?: 'Unknown';
                $metrics["Payment Method: {$method} (Count)"] = $pm->count;
                $metrics["Payment Method: {$method} (Amount)"] = floatval($pm->amount);
            }
        } else {
            $metrics['Transactions Count'] = 0;
            $metrics['Transactions Total Amount'] = 0.0;
        }

        // 5. Job Orders
        if (Schema::hasTable('job_orders')) {
            $qJO = DB::table('job_orders');
            $qJODone = DB::table('job_orders')->where('onsite_status', 'Done');
            $qJOProg = DB::table('job_orders')->where('onsite_status', 'In Progress');
            $qJOFail = DB::table('job_orders')->where('onsite_status', 'Failed');

            if ($hasRange) {
                $qJO->whereBetween('created_at', $range);
                $qJODone->whereBetween('created_at', $range);
                $qJOProg->whereBetween('created_at', $range);
                $qJOFail->whereBetween('created_at', $range);
            }

            $metrics['Total Job Orders'] = $qJO->count();
            $metrics['Job Orders - Done'] = $qJODone->count();
            $metrics['Job Orders - In Progress'] = $qJOProg->count();
            $metrics['Job Orders - Failed'] = $qJOFail->count();
        } else {
            $metrics['Total Job Orders'] = 0;
            $metrics['Job Orders - Done'] = 0;
            $metrics['Job Orders - In Progress'] = 0;
            $metrics['Job Orders - Failed'] = 0;
        }

        // 6. Service Orders (overall)
        if (Schema::hasTable('service_orders')) {
            $qSO = DB::table('service_orders');
            $qSODone = DB::table('service_orders')->where('visit_status', 'Done');
            $qSOProg = DB::table('service_orders')->where('visit_status', 'In Progress');
            $qSOFail = DB::table('service_orders')->where('visit_status', 'Failed');

            if ($hasRange) {
                $qSO->whereBetween('created_at', $range);
                $qSODone->whereBetween('created_at', $range);
                $qSOProg->whereBetween('created_at', $range);
                $qSOFail->whereBetween('created_at', $range);
            }

            $metrics['Total Service Orders'] = $qSO->count();
            $metrics['Service Orders - Done'] = $qSODone->count();
            $metrics['Service Orders - In Progress'] = $qSOProg->count();
            $metrics['Service Orders - Failed'] = $qSOFail->count();

            // Service Orders per Concern
            $qSOC = DB::table('service_orders')->select('concern', DB::raw('count(*) as count'));
            if ($hasRange) $qSOC->whereBetween('created_at', $range);
            $socList = $qSOC->groupBy('concern')->get();

            foreach ($socList as $soc) {
                $concern = $soc->concern ?: 'Unknown';
                $metrics["Service Orders per Concern: {$concern}"] = $soc->count;
            }
        } else {
            $metrics['Total Service Orders'] = 0;
            $metrics['Service Orders - Done'] = 0;
            $metrics['Service Orders - In Progress'] = 0;
            $metrics['Service Orders - Failed'] = 0;
        }

        // 7. LCP/NAP
        if (Schema::hasTable('lcpnap')) {
            $metrics['Total LCP/NAP'] = DB::table('lcpnap')->count();
        } else {
            $metrics['Total LCP/NAP'] = 0;
        }

        // 8. Inventory
        $goodStock = 0;
        $badStock = 0;
        if (Schema::hasTable('inventory_items')) {
            $items = DB::table('inventory_items')->get();
            foreach ($items as $item) {
                $qty = $item->total_quantity ?? 0;
                $alert = $item->quantity_alert ?? 0;
                if ($qty > $alert) {
                    $goodStock++;
                } else {
                    $badStock++;
                }
            }
        }
        $metrics['Good Stock (Inventory Items)'] = $goodStock;
        $metrics['Bad Stock (Inventory Items)'] = $badStock;

        // 9. Applications per Barangay
        if (Schema::hasTable('applications')) {
            $qApp = DB::table('applications')->select('barangay', DB::raw('count(*) as count'));
            if ($hasRange) $qApp->whereBetween('created_at', $range);
            $appList = $qApp->groupBy('barangay')->get();

            foreach ($appList as $app) {
                $barangay = $app->barangay ?: 'Unknown';
                $metrics["Applications: {$barangay}"] = $app->count;
            }
        }

        // 10. Subscribers Online per Barangay
        if (Schema::hasTable('online_status') && Schema::hasTable('billing_accounts') && Schema::hasTable('customers')) {
            $onlineList = DB::table('online_status')
                ->join('billing_accounts', 'online_status.account_id', '=', 'billing_accounts.id')
                ->join('customers', 'billing_accounts.customer_id', '=', 'customers.id')
                ->select('customers.barangay', DB::raw('count(*) as count'))
                ->groupBy('customers.barangay')
                ->get();

            foreach ($onlineList as $online) {
                $barangay = $online->barangay ?: 'Unknown';
                $metrics["Subscribers Online: {$barangay}"] = $online->count;
            }
        }

        return $metrics;
    }
}
