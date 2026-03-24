@extends('layouts.app')
@section('title', 'Cost Dashboard — KPS')

@section('extra-styles')
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 20px; }
        .stat { background: #fff; border-radius: 12px; padding: 16px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
        .stat-value { font-size: 24px; font-weight: 700; }
        .stat-label { font-size: 12px; color: #86868b; text-transform: uppercase; margin-top: 4px; }
        .budget-ok { background: #d4edda; color: #155724; }
        .budget-warning { background: #fff3cd; color: #856404; }
        .budget-exceeded { background: #f8d7da; color: #721c24; }
        .budget-hard { background: #721c24; color: #fff; }
        .budget-bar { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; font-size: 14px; }
@endsection

@section('body')
    <div class="page-content">
        <div class="page-container">
            <h1 style="font-size: 20px; font-weight: 600; margin-bottom: 4px;">Cost Dashboard</h1>
            <p style="color: #86868b; font-size: 13px; margin-bottom: 24px;">Token usage, costs, and budget status</p>

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
                <div class="stat"><div class="stat-value">${{ number_format($monthly['cost'], 4) }}</div><div class="stat-label">Monthly Cost</div></div>
                <div class="stat"><div class="stat-value">{{ number_format($monthly['tokens']) }}</div><div class="stat-label">Monthly Tokens</div></div>
                <div class="stat"><div class="stat-value">{{ number_format($monthly['requests']) }}</div><div class="stat-label">Monthly Requests</div></div>
                <div class="stat"><div class="stat-value">{{ number_format($tenant->monthly_token_budget ?? 1000000) }}</div><div class="stat-label">Token Budget</div></div>
            </div>

            <div class="card">
                <h2>Cost by Endpoint</h2>
                <table>
                    <thead><tr><th>Endpoint</th><th>Requests</th><th>Tokens</th><th>Cost</th></tr></thead>
                    <tbody>
                    @forelse($byEndpoint as $row)
                        <tr><td>{{ $row->endpoint }}</td><td>{{ number_format($row->requests) }}</td><td>{{ number_format($row->tokens) }}</td><td>${{ number_format($row->cost, 4) }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="empty">No usage data yet</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <div class="card">
                <h2>Cost by Model</h2>
                <table>
                    <thead><tr><th>Model</th><th>Requests</th><th>Tokens</th><th>Cost</th></tr></thead>
                    <tbody>
                    @forelse($byModel as $row)
                        <tr><td style="font-size: 12px;">{{ $row->model_id }}</td><td>{{ number_format($row->requests) }}</td><td>{{ number_format($row->tokens) }}</td><td>${{ number_format($row->cost, 4) }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="empty">No usage data yet</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <div class="card">
                <h2>Daily Trend (Last 30 Days)</h2>
                <table>
                    <thead><tr><th>Date</th><th>Embedding</th><th>Chat</th><th>Pipeline</th><th>Total</th><th>Tokens</th></tr></thead>
                    <tbody>
                    @forelse($dailyTrend as $day)
                        <tr><td>{{ $day->date }}</td><td>${{ number_format($day->embedding_cost, 4) }}</td><td>${{ number_format($day->chat_cost, 4) }}</td><td>${{ number_format($day->pipeline_cost, 4) }}</td><td><strong>${{ number_format($day->total_cost, 4) }}</strong></td><td>{{ number_format($day->total_tokens) }}</td></tr>
                    @empty
                        <tr><td colspan="6" class="empty">No data yet</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
