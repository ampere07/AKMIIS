<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Summary Report</title>
    <style>
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            color: #333;
            line-height: 1.5;
            margin: 0;
            padding: 20px;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #7c3aed;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .header h1 {
            color: #7c3aed;
            margin: 0;
            font-size: 28px;
            text-transform: uppercase;
        }
        .header p {
            margin: 5px 0 0;
            color: #666;
            font-size: 14px;
        }
        .info-section {
            margin-bottom: 25px;
            background: #f8fafc;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        .info-grid {
            width: 100%;
        }
        .info-grid td {
            padding: 5px 0;
            font-size: 13px;
        }
        .label {
            font-weight: bold;
            color: #4b5563;
            width: 120px;
        }
        .value {
            color: #1f2937;
        }
        .section-title {
            font-size: 18px;
            color: #1f2937;
            margin: 30px 0 15px;
            padding-left: 10px;
            border-left: 4px solid #7c3aed;
        }
        .metrics-grid {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .metrics-grid th, .metrics-grid td {
            text-align: left;
            padding: 12px 15px;
            border-bottom: 1px solid #e2e8f0;
        }
        .metrics-grid th {
            background-color: #f1f5f9;
            color: #475569;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .metrics-grid td {
            font-size: 14px;
        }
        .highlight {
            font-weight: bold;
            color: #7c3aed;
        }
        .footer {
            margin-top: 50px;
            text-align: center;
            font-size: 11px;
            color: #94a3b8;
            border-top: 1px solid #e2e8f0;
            padding-top: 20px;
        }
        .summary-box {
            background-color: #7c3aed;
            color: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 30px;
            text-align: center;
        }
        .summary-box h2 {
            margin: 0 0 10px;
            font-size: 14px;
            opacity: 0.9;
            text-transform: uppercase;
        }
        .summary-box .grand-total {
            font-size: 36px;
            font-weight: bold;
            margin: 0;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>ATSS FIBER</h1>
        <p>Managed Internet Services - Summary Report</p>
    </div>

    <div class="info-section">
        <table class="info-grid">
            <tr>
                <td class="label">Report Name:</td>
                <td class="value">{{ $reportName }}</td>
                <td class="label">Generated:</td>
                <td class="value">{{ now()->format('F d, Y h:i A') }}</td>
            </tr>
            <tr>
                <td class="label">Date Range:</td>
                <td class="value">{{ $dateRange ?: 'All Time' }}</td>
                <td class="label">Created By:</td>
                <td class="value">{{ $createdBy }}</td>
            </tr>
        </table>
    </div>

    <div class="summary-box">
        <h2>Total Sales Value</h2>
        <p class="grand-total">₱{{ number_format($metrics['Sales Total (Value)'], 2) }}</p>
    </div>

    <div class="section-title">Financial Summary</div>
    <table class="metrics-grid">
        <thead>
            <tr>
                <th>Category</th>
                <th>Count</th>
                <th>Total Value</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Manual Transactions</td>
                <td>{{ number_format($metrics['Transactions Count']) }}</td>
                <td>₱{{ number_format($metrics['Transactions Total Received Payment'], 2) }}</td>
            </tr>
            <tr>
                <td>Payment Portal</td>
                <td>{{ number_format($metrics['Payment Portal Logs Count']) }}</td>
                <td>₱{{ number_format($metrics['Payment Portal Logs Total Amount'], 2) }}</td>
            </tr>
            <tr style="background-color: #f8fafc; font-weight: bold;">
                <td>Total Revenue</td>
                <td>{{ number_format($metrics['Sales Total (Count)']) }}</td>
                <td class="highlight">₱{{ number_format($metrics['Sales Total (Value)'], 2) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="section-title">Operational metrics</div>
    <table class="metrics-grid">
        <thead>
            <tr>
                <th>Department</th>
                <th>Metric</th>
                <th>Value</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Inventory</td>
                <td>Good Stock Items</td>
                <td>{{ number_format($metrics['Good Stock Count']) }}</td>
            </tr>
            <tr>
                <td>Inventory</td>
                <td style="color: #ef4444;">Low Stock Items</td>
                <td style="color: #ef4444;">{{ number_format($metrics['Low Stock Count']) }}</td>
            </tr>
            <tr>
                <td>Job Orders</td>
                <td>Completed (Done)</td>
                <td>{{ number_format($metrics['Job Orders (Onsite Status = Done)']) }}</td>
            </tr>
            <tr>
                <td>Job Orders</td>
                <td>In Progress</td>
                <td>{{ number_format($metrics['Job Orders (Onsite Status = In Progress)']) }}</td>
            </tr>
            <tr>
                <td>Service Orders</td>
                <td>Resolved Tickets</td>
                <td>{{ number_format($metrics['Service Orders (Support Status = Resolved)']) }}</td>
            </tr>
            <tr>
                <td>Service Orders</td>
                <td>Successful Visits (Done)</td>
                <td>{{ number_format($metrics['Service Orders (Visit Status = Done)']) }}</td>
            </tr>
            <tr>
                <td>Service Orders</td>
                <td>Visits In Progress</td>
                <td>{{ number_format($metrics['Service Orders (Visit Status = In Progress)']) }}</td>
            </tr>
            <tr>
                <td>Service Orders</td>
                <td>Pullout Requests</td>
                <td>{{ number_format($metrics['Service Orders (Repair Category = Pullout)']) }}</td>
            </tr>
            <tr>
                <td>Work Orders</td>
                <td>Completed</td>
                <td>{{ number_format($metrics['Work Orders (Completed)']) }}</td>
            </tr>
            <tr>
                <td>Work Orders</td>
                <td>Pending</td>
                <td>{{ number_format($metrics['Work Orders (Pending)']) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="footer">
        <p>This report was automatically generated by the ATSS FIBER Management System.</p>
        <p>&copy; {{ date('Y') }} ATSS FIBER. All rights reserved.</p>
    </div>
</body>
</html>
