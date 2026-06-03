<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Weekly Expense Report — {{ $report['company'] }}</title>
    <style>
        body { font-family: Arial, sans-serif; color: #333; line-height: 1.6; }
        .container { max-width: 640px; margin: 40px auto; padding: 0 20px; }
        .header { background: #4f46e5; color: #fff; padding: 24px; border-radius: 6px 6px 0 0; }
        .header h1 { margin: 0; font-size: 20px; }
        .body { background: #fff; border: 1px solid #e5e7eb; padding: 24px; border-radius: 0 0 6px 6px; }
        .summary { background: #f9fafb; border-radius: 4px; padding: 16px; margin: 16px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th { background: #f3f4f6; text-align: left; padding: 8px 12px; font-size: 13px; }
        td { padding: 8px 12px; border-bottom: 1px solid #e5e7eb; font-size: 14px; }
        td:last-child { text-align: right; }
        .footer { text-align: center; color: #6b7280; font-size: 12px; margin-top: 24px; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Weekly Expense Report — {{ $report['company'] }}</h1>
    </div>
    <div class="body">
        <div class="summary">
            <p><strong>Period:</strong> {{ $report['period_start'] }} → {{ $report['period_end'] }}</p>
            <p><strong>Total expenses logged:</strong> {{ $report['count'] }}</p>
            <p><strong>Total amount:</strong> ${{ number_format($report['total_amount'], 2) }}</p>
        </div>

        <h3>Breakdown by Category</h3>
        <table>
            <thead>
                <tr>
                    <th>Category</th>
                    <th style="text-align:right">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report['by_category'] as $category => $amount)
                <tr>
                    <td>{{ $category }}</td>
                    <td>${{ number_format($amount, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="footer">
        <p>{{ config('app.name') }} — automated weekly report. Do not reply to this email.</p>
    </div>
</div>
</body>
</html>
