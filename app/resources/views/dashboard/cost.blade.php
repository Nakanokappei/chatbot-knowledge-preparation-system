@extends('layouts.app')
@section('title', 'Usage — KPS')

@section('extra-styles')
        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 20px; }
        .stat { background: #fff; border-radius: 12px; padding: 16px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
        .stat-value { font-size: 24px; font-weight: 700; }
        .stat-label { font-size: 12px; color: #5f6368; text-transform: uppercase; margin-top: 4px; }
        .chart-container { position: relative; width: 100%; height: 220px; }
        .chart-container canvas { width: 100% !important; height: 100% !important; }
        .chart-legend { display: flex; gap: 16px; justify-content: center; margin-top: 8px; font-size: 12px; color: #5f6368; }
        .chart-legend span { display: flex; align-items: center; gap: 4px; }
        .chart-legend span::before { content: ''; display: inline-block; width: 10px; height: 10px; border-radius: 2px; background: var(--c); }
@endsection

@section('body')
    <div class="page-content">
        <div class="page-container">
            <h1 style="font-size: 20px; font-weight: 600; margin-bottom: 4px;">Usage</h1>
            <p style="color: #5f6368; font-size: 13px; margin-bottom: 24px;">Token usage and estimated costs (last 30 days)</p>

            <div class="stats-grid">
                <div class="stat"><div class="stat-value">${{ number_format($monthly['cost'], 4) }}</div><div class="stat-label">Cost (30 days)</div></div>
                <div class="stat"><div class="stat-value">{{ number_format($monthly['tokens']) }}</div><div class="stat-label">Tokens (30 days)</div></div>
                <div class="stat"><div class="stat-value">{{ number_format($monthly['requests']) }}</div><div class="stat-label">Requests (30 days)</div></div>
            </div>

            <!-- Daily Charts -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;">
                <div class="card">
                    <h2>Daily Tokens</h2>
                    <div class="chart-container"><canvas id="tokensChart"></canvas></div>
                </div>
                <div class="card">
                    <h2>Daily Cost</h2>
                    <div class="chart-container"><canvas id="costChart"></canvas></div>
                    <div class="chart-legend">
                        <span style="--c: #0071e3;">Pipeline</span>
                        <span style="--c: #34c759;">Chat</span>
                        <span style="--c: #ff9500;">Embedding</span>
                    </div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;">
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
            </div>
        </div>
    </div>
@endsection

@section('scripts')
    const dailyData = @json($dailyTrend);

    // Fill missing dates in the last 30 days with zero values
    function buildDateRange(data) {
        const map = {};
        data.forEach(d => { map[d.date] = d; });
        const result = [];
        const today = new Date();
        for (let i = 29; i >= 0; i--) {
            const dt = new Date(today);
            dt.setDate(dt.getDate() - i);
            const key = dt.toISOString().slice(0, 10);
            result.push(map[key] || { date: key, total_tokens: 0, total_cost: 0, pipeline_cost: 0, chat_cost: 0, embedding_cost: 0 });
        }
        return result;
    }

    // Draw a bar chart on a canvas element
    function drawBarChart(canvasId, days, getValue, getSegments, formatValue) {
        const canvas = document.getElementById(canvasId);
        const ctx = canvas.getContext('2d');
        const dpr = window.devicePixelRatio || 1;
        const rect = canvas.parentElement.getBoundingClientRect();
        canvas.width = rect.width * dpr;
        canvas.height = rect.height * dpr;
        ctx.scale(dpr, dpr);
        const W = rect.width, H = rect.height;

        const pad = { top: 20, right: 12, bottom: 32, left: 52 };
        const chartW = W - pad.left - pad.right;
        const chartH = H - pad.top - pad.bottom;

        const values = days.map(getValue);
        const maxVal = Math.max(...values, 0.001);

        // Grid lines and Y-axis labels
        ctx.strokeStyle = '#f0f0f2';
        ctx.fillStyle = '#5f6368';
        ctx.font = '11px -apple-system, sans-serif';
        ctx.textAlign = 'right';
        for (let i = 0; i <= 4; i++) {
            const y = pad.top + chartH - (chartH * i / 4);
            ctx.beginPath();
            ctx.moveTo(pad.left, y);
            ctx.lineTo(W - pad.right, y);
            ctx.stroke();
            ctx.fillText(formatValue(maxVal * i / 4), pad.left - 6, y + 4);
        }

        // Bars
        const barW = Math.max(2, (chartW / days.length) - 2);
        const gap = (chartW - barW * days.length) / days.length;

        days.forEach((day, i) => {
            const x = pad.left + i * (barW + gap) + gap / 2;
            const segments = getSegments ? getSegments(day) : [{ value: getValue(day), color: '#0071e3' }];
            let yOffset = 0;

            segments.forEach(seg => {
                const h = maxVal > 0 ? (seg.value / maxVal) * chartH : 0;
                const y = pad.top + chartH - yOffset - h;
                ctx.fillStyle = seg.color;
                ctx.beginPath();
                ctx.roundRect(x, y, barW, Math.max(h, h > 0 ? 1 : 0), [2, 2, 0, 0]);
                ctx.fill();
                yOffset += h;
            });

            // X-axis labels (show every 5th date)
            if (i % 5 === 0 || i === days.length - 1) {
                ctx.fillStyle = '#5f6368';
                ctx.font = '10px -apple-system, sans-serif';
                ctx.textAlign = 'center';
                ctx.fillText(day.date.slice(5), x + barW / 2, H - pad.bottom + 14);
            }
        });
    }

    // Format helpers
    function fmtTokens(v) {
        if (v >= 1000000) return (v / 1000000).toFixed(1) + 'M';
        if (v >= 1000) return (v / 1000).toFixed(0) + 'K';
        return Math.round(v).toString();
    }
    function fmtCost(v) { return '$' + v.toFixed(4); }

    // Render charts
    const days = buildDateRange(dailyData);

    drawBarChart('tokensChart', days,
        d => Number(d.total_tokens) || 0,
        null,
        fmtTokens
    );

    drawBarChart('costChart', days,
        d => (Number(d.pipeline_cost) || 0) + (Number(d.chat_cost) || 0) + (Number(d.embedding_cost) || 0),
        d => [
            { value: Number(d.pipeline_cost) || 0, color: '#0071e3' },
            { value: Number(d.chat_cost) || 0, color: '#34c759' },
            { value: Number(d.embedding_cost) || 0, color: '#ff9500' },
        ],
        fmtCost
    );

    // Set legend colors via CSS custom property
    document.querySelectorAll('.chart-legend span').forEach(el => {
        el.style.setProperty('--c', el.style.getPropertyValue('--c'));
        el.querySelector('::before') // CSS handles this via var(--c)
    });
@endsection
