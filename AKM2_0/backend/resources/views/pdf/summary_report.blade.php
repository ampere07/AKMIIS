<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Summary Report</title>
    <style>
        body {
            font-family: 'Helvetica Neue', 'Helvetica', 'Arial', sans-serif;
            color: #1e293b;
            line-height: 1.4;
            margin: 0;
            padding: 10px;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #7c3aed;
            padding-bottom: 12px;
            margin-bottom: 20px;
        }
        .header h1 {
            color: #7c3aed;
            margin: 0;
            font-size: 24px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .header p {
            margin: 4px 0 0;
            color: #64748b;
            font-size: 12px;
        }
        .info-section {
            margin-bottom: 20px;
            background: #f8fafc;
            padding: 12px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
        }
        .info-grid {
            width: 100%;
        }
        .info-grid td {
            padding: 4px 0;
            font-size: 11px;
            vertical-align: top;
        }
        .label {
            font-weight: bold;
            color: #475569;
            width: 100px;
        }
        .value {
            color: #0f172a;
        }
        
        .metrics-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
            font-size: 11px;
        }
        .metrics-table th, .metrics-table td {
            padding: 6px 10px;
            border: 1px solid #e2e8f0;
            text-align: left;
        }
        .metrics-table th {
            background-color: #7c3aed;
            color: white;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 10px;
            letter-spacing: 0.05em;
        }
        .metrics-table .number {
            text-align: right;
            font-weight: 500;
            font-variant-numeric: tabular-nums;
        }
        .section-header {
            background-color: #f1f5f9;
            color: #1e293b;
            font-weight: bold;
            font-size: 11px;
            padding: 8px 10px;
            border-bottom: 2px solid #cbd5e1;
        }
        .indent {
            padding-left: 25px !important;
            color: #475569;
        }
        .highlight {
            font-weight: bold;
            background-color: #faf5ff;
        }
        
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 9px;
            color: #94a3b8;
            border-top: 1px solid #e2e8f0;
            padding-top: 12px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>SYNC</h1>
        <p>Reporting</p>
    </div>

    <div class="info-section">
        <table class="info-grid">
            <tr>
                <td class="label">Report Name:</td>
                <td class="value">{{ $reportName }}</td>
                <td class="label">Generated:</td>
                <td class="value">{{ $generatedAt }}</td>
            </tr>
            <tr>
                <td class="label">Date Range:</td>
                <td class="value">{{ $dateRange ?: 'All Time' }}</td>
                <td class="label">Created By:</td>
                <td class="value">{{ $createdBy }}</td>
            </tr>
        </table>
    </div>

    <table class="metrics-table">
        <thead>
            <tr>
                <th style="width: 70%;">Report</th>
                <th style="width: 30%; text-align: right;">Value</th>
            </tr>
        </thead>
        <tbody>
            <!-- ─── Financial Status ─── -->
            <tr>
                <td colspan="2" class="section-header">Invoices &amp; Billing</td>
            </tr>
            <tr>
                <td>Total Unpaid Invoices (Count)</td>
                <td class="number">{{ number_format($metrics['Total Unpaid Invoices (Count)'] ?? 0) }}</td>
            </tr>
            <tr class="highlight">
                <td>Total Unpaid Invoices (Amount)</td>
                <td class="number">PHP {{ number_format($metrics['Total Unpaid Invoices (Amount)'] ?? 0, 2) }}</td>
            </tr>
            <tr>
                <td>Total Paid Invoices (Count)</td>
                <td class="number">{{ number_format($metrics['Total Paid Invoices (Count)'] ?? 0) }}</td>
            </tr>
            <tr class="highlight">
                <td>Total Paid Invoices (Amount)</td>
                <td class="number">PHP {{ number_format($metrics['Total Paid Invoices (Amount)'] ?? 0, 2) }}</td>
            </tr>

            <!-- ─── Payment Portal & Transactions ─── -->
            <tr>
                <td colspan="2" class="section-header">Payments &amp; Collections</td>
            </tr>
            <tr>
                <td>Payment Portal Logs (Count)</td>
                <td class="number">{{ number_format($metrics['Payment Portal Logs Count'] ?? 0) }}</td>
            </tr>
            <tr class="highlight">
                <td>Payment Portal Logs Total Amount</td>
                <td class="number">PHP {{ number_format($metrics['Payment Portal Logs Total Amount'] ?? 0, 2) }}</td>
            </tr>
            <tr>
                <td>Transactions Table (Count)</td>
                <td class="number">{{ number_format($metrics['Transactions Count'] ?? 0) }}</td>
            </tr>
            <tr class="highlight">
                <td>Transactions Table Total Amount</td>
                <td class="number">PHP {{ number_format($metrics['Transactions Total Amount'] ?? 0, 2) }}</td>
            </tr>
            
            <!-- Payment Methods -->
            @php
                $hasPaymentMethods = false;
                foreach($metrics as $k => $v) {
                    if(strpos($k, 'Payment Method: ') === 0) {
                        $hasPaymentMethods = true;
                        break;
                    }
                }
            @endphp
            @if($hasPaymentMethods)
                <tr>
                    <td colspan="2" class="section-header" style="font-size: 10px; background-color: #f8fafc;">Payment Methods Breakdown</td>
                </tr>
                @foreach($metrics as $k => $v)
                    @if(strpos($k, 'Payment Method: ') === 0)
                        <tr>
                            <td class="indent">{{ str_replace('Payment Method: ', '', $k) }}</td>
                            <td class="number">
                                @if(strpos($k, '(Amount)') !== false)
                                    PHP {{ number_format($v, 2) }}
                                @else
                                    {{ number_format($v) }}
                                @endif
                            </td>
                        </tr>
                    @endif
                @endforeach
            @endif

            <!-- ─── Pull Out Concern Service Orders ─── -->
            <tr>
                <td colspan="2" class="section-header">Pull Out Service Orders</td>
            </tr>
            <tr>
                <td>Number of Pull Out Concern Service Orders (Total)</td>
                <td class="number">{{ number_format($metrics['Number of Pull Out Concern Service Orders'] ?? 0) }}</td>
            </tr>
            <tr>
                <td class="indent">In Progress Pullout</td>
                <td class="number">{{ number_format($metrics['Pull Out In Progress'] ?? 0) }}</td>
            </tr>
            <tr>
                <td class="indent">Done Pullout</td>
                <td class="number">{{ number_format($metrics['Pull Out Done'] ?? 0) }}</td>
            </tr>
            <tr>
                <td class="indent">Failed Pullout</td>
                <td class="number">{{ number_format($metrics['Pull Out Failed'] ?? 0) }}</td>
            </tr>

            <!-- ─── Job Orders ─── -->
            <tr>
                <td colspan="2" class="section-header">Job Orders</td>
            </tr>
            <tr>
                <td>Total Job Orders</td>
                <td class="number">{{ number_format($metrics['Total Job Orders'] ?? 0) }}</td>
            </tr>
            <tr>
                <td class="indent">Done</td>
                <td class="number">{{ number_format($metrics['Job Orders - Done'] ?? 0) }}</td>
            </tr>
            <tr>
                <td class="indent">In Progress</td>
                <td class="number">{{ number_format($metrics['Job Orders - In Progress'] ?? 0) }}</td>
            </tr>
            <tr>
                <td class="indent">Failed</td>
                <td class="number">{{ number_format($metrics['Job Orders - Failed'] ?? 0) }}</td>
            </tr>

            <!-- ─── Service Orders ─── -->
            <tr>
                <td colspan="2" class="section-header">Service Orders</td>
            </tr>
            <tr>
                <td>Total Service Orders</td>
                <td class="number">{{ number_format($metrics['Total Service Orders'] ?? 0) }}</td>
            </tr>
            <tr>
                <td class="indent">Done</td>
                <td class="number">{{ number_format($metrics['Service Orders - Done'] ?? 0) }}</td>
            </tr>
            <tr>
                <td class="indent">In Progress</td>
                <td class="number">{{ number_format($metrics['Service Orders - In Progress'] ?? 0) }}</td>
            </tr>
            <tr>
                <td class="indent">Failed</td>
                <td class="number">{{ number_format($metrics['Service Orders - Failed'] ?? 0) }}</td>
            </tr>
            
            <!-- Service Orders per Concern -->
            @php
                $hasConcerns = false;
                foreach($metrics as $k => $v) {
                    if(strpos($k, 'Service Orders per Concern: ') === 0) {
                        $hasConcerns = true;
                        break;
                    }
                }
            @endphp
            @if($hasConcerns)
                <tr>
                    <td colspan="2" class="section-header" style="font-size: 10px; background-color: #f8fafc;">Service Orders per Concern</td>
                </tr>
                @foreach($metrics as $k => $v)
                    @if(strpos($k, 'Service Orders per Concern: ') === 0)
                        <tr>
                            <td class="indent">{{ str_replace('Service Orders per Concern: ', '', $k) }}</td>
                            <td class="number">{{ number_format($v) }}</td>
                        </tr>
                    @endif
                @endforeach
            @endif

            <!-- ─── Network Infrastructure ─── -->
            <tr>
                <td colspan="2" class="section-header">Network Infrastructure</td>
            </tr>
            <tr>
                <td>Total LCP/NAP Locations</td>
                <td class="number">{{ number_format($metrics['Total LCP/NAP'] ?? 0) }}</td>
            </tr>

            <!-- ─── Inventory ─── -->
            <tr>
                <td colspan="2" class="section-header">Inventory Status</td>
            </tr>
            <tr>
                <td>Good Stock (Inventory Items)</td>
                <td class="number">{{ number_format($metrics['Good Stock (Inventory Items)'] ?? 0) }}</td>
            </tr>
            <tr style="color: #b91c1c;">
                <td>Bad Stock / Low Quantity Alerts (Inventory Items)</td>
                <td class="number">{{ number_format($metrics['Bad Stock (Inventory Items)'] ?? 0) }}</td>
            </tr>

            <!-- ─── Applications per Barangay ─── -->
            @php
                $hasBarangayApps = false;
                foreach($metrics as $k => $v) {
                    if(strpos($k, 'Applications: ') === 0) {
                        $hasBarangayApps = true;
                        break;
                    }
                }
            @endphp
            @if($hasBarangayApps)
                <tr>
                    <td colspan="2" class="section-header">Total Applications per Barangay</td>
                </tr>
                @foreach($metrics as $k => $v)
                    @if(strpos($k, 'Applications: ') === 0)
                        <tr>
                            <td class="indent">{{ str_replace('Applications: ', '', $k) }}</td>
                            <td class="number">{{ number_format($v) }}</td>
                        </tr>
                    @endif
                @endforeach
            @endif

            <!-- ─── Online Subscribers per Barangay ─── -->
            @php
                $hasBarangayOnline = false;
                foreach($metrics as $k => $v) {
                    if(strpos($k, 'Subscribers Online: ') === 0) {
                        $hasBarangayOnline = true;
                        break;
                    }
                }
            @endphp
            @if($hasBarangayOnline)
                <tr>
                    <td colspan="2" class="section-header">Online Subscribers per Barangay</td>
                </tr>
                @foreach($metrics as $k => $v)
                    @if(strpos($k, 'Subscribers Online: ') === 0)
                        <tr>
                            <td class="indent">{{ str_replace('Subscribers Online: ', '', $k) }}</td>
                            <td class="number">{{ number_format($v) }}</td>
                        </tr>
                    @endif
                @endforeach
            @endif
        </tbody>
    </table>

    <div class="footer">
        <p>This report was automatically generated by the Sync.</p>
        <p>&copy; {{ date('Y') }} Sync. All rights reserved.</p>
    </div>
</body>
</html>
