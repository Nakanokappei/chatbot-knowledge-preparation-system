<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cost Dashboard</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f5; padding: 20px; }
        .container { max-width: 1000px; margin: 0 auto; }
        h1 { margin-bottom: 20px; }
        .nav { margin-bottom: 20px; }
        .nav a { color: #2563eb; text-decoration: none; }
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 20px; }
        .stat { background: white; border-radius: 8px; padding: 16px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .stat-value { font-size: 24px; font-weight: 700; }
        .stat-label { font-size: 12px; color: #6b7280; text-transform: uppercase; margin-top: 4px; }
        .card { background: white; border-radius: 8px; padding: 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 8px 12px; background: #f9fafb; font-size: 13px; color: #6b7280; }
        td { padding: 8px 12px; border-bottom: 1px solid #f3f4f6; font-size: 14px; }
        .budget-ok { background: #d1fae5; color: #065f46; }
        .budget-warning { background: #fef3c7; color: #92400e; }
        .budget-exceeded { background: #fee2e2; color: #991b1b; }
        .budget-hard { background: #991b1b; color: white; }
        .budget-bar { padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-weight: 600; }
    </style>
</head>
<body>
<div class="container">
    <div class="nav">
        <a href="{{ route('dashboard') }}">Dashboard</a> / <strong>Cost</strong>
    </div>

    <h1>Cost Dashboard</h1>

    @php
        $budgetClass = match($budgetStatus) {
            'warning' => 'budget-warning',
            'exceeded' => 'budget-exceeded',
            'hard_limit' => 'budget-hard',
            default => 'budget-ok',
        };
        $budgetMsg = match($budgetStatus) {
            'warning' => 'Budget usage at 80%+ — approaching limit',
            'exceeded' => 'Budget exceeded — Chat API disabled',
            'hard_limit' => 'Budget at 120%+ — All APIs disabled except export',
            default => 'Budget OK',
        };
    @endphp

    <div class="budget-bar {{ $budgetClass }}">
        {{ $budgetMsg }}
        ({{ number_format($monthly['tokens']) }} / {{ number_format($tenant->monthly_token_budget ?? 1000000) }} tokens)
    </div>

    <div class="stats-grid">
        <div class="stat">
            <div class="stat-value">${{ number_format($monthly['cost'], 4) }}</div>
            <div class="stat-label">Monthly Cost</div>
        </div>
        <div class="stat">
            <div class="stat-value">{{ number_format($monthly['tokens']) }}</div>
            <div class="stat-label">Monthly Tokens</div>
        </div>
        <div class="stat">
            <div class="stat-value">{{ number_format($monthly['requests']) }}</div>
            <div class="stat-label">Monthly Requests</div>
        </div>
        <div class="stat">
            <div class="stat-value">{{ number_format($tenant->monthly_token_budget ?? 1000000) }}</div>
            <div class="stat-label">Token Budget</div>
        </div>
    </div>

    <!-- Cost by Endpoint -->
    <div class="card">
        <h2 style="margin-bottom: 12px;">Cost by Endpoint</h2>
        <table>
            <thead><tr><th>Endpoint</th><th>Requests</th><th>Tokens</th><th>Cost</th></tr></thead>
            <tbody>
            @forelse($byEndpoint as $row)
                <tr>
                    <td>{{ $row->endpoint }}</td>
                    <td>{{ number_format($row->requests) }}</td>
                    <td>{{ number_format($row->tokens) }}</td>
                    <td>${{ number_format($row->cost, 4) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" style="text-align: center; color: #6b7280;">No usage data yet</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <!-- Cost by Model -->
    <div class="card">
        <h2 style="margin-bottom: 12px;">Cost by Model</h2>
        <table>
            <thead><tr><th>Model</th><th>Requests</th><th>Tokens</th><th>Cost</th></tr></thead>
            <tbody>
            @forelse($byModel as $row)
                <tr>
                    <td style="font-size: 12px;">{{ $row->model_id }}</td>
                    <td>{{ number_format($row->requests) }}</td>
                    <td>{{ number_format($row->tokens) }}</td>
                    <td>${{ number_format($row->cost, 4) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" style="text-align: center; color: #6b7280;">No usage data yet</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <!-- Daily Trend -->
    <div class="card">
        <h2 style="margin-bottom: 12px;">Daily Trend (Last 30 Days)</h2>
        <table>
            <thead><tr><th>Date</th><th>Embedding</th><th>Chat</th><th>Pipeline</th><th>Total</th><th>Tokens</th></tr></thead>
            <tbody>
            @forelse($dailyTrend as $day)
                <tr>
                    <td>{{ $day->date }}</td>
                    <td>${{ number_format($day->embedding_cost, 4) }}</td>
                    <td>${{ number_format($day->chat_cost, 4) }}</td>
                    <td>${{ number_format($day->pipeline_cost, 4) }}</td>
                    <td><strong>${{ number_format($day->total_cost, 4) }}</strong></td>
                    <td>{{ number_format($day->total_tokens) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" style="text-align: center; color: #6b7280;">No data yet</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
