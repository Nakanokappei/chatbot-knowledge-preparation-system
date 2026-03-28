{{-- Usage dashboard: displays token consumption and cost metrics for the last 30 days.
     Includes summary stats, daily bar charts (tokens & cost by category), and breakdowns by endpoint/model. --}}
@extends(($isAdminView ?? false) ? 'layouts.admin' : 'layouts.app')
@section('title', 'Usage — KPS')
@php $showCost = auth()->user()->isSystemAdmin(); @endphp

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
            <h1 style="font-size: 20px; font-weight: 600; margin-bottom: 4px;">{{ __('ui.usage') }}</h1>
            <p style="color: #5f6368; font-size: 13px; margin-bottom: 24px;">{{ __('ui.usage_description') }}</p>

            {{-- Summary stats: 30-day totals --}}
            <div class="stats-grid" style="grid-template-columns: repeat({{ $showCost ? 3 : 2 }}, 1fr);">
                @if($showCost)
                <div class="stat"><div class="stat-value">${{ number_format($monthly['cost'], 4) }}</div><div class="stat-label">{{ __('ui.cost_30days') }}</div></div>
                @endif
                <div class="stat"><div class="stat-value">{{ number_format($monthly['requests']) }}</div><div class="stat-label">{{ __('ui.requests_30days') }}</div></div>
                <div class="stat"><div class="stat-value">{{ number_format($monthly['tokens']) }}</div><div class="stat-label">{{ __('ui.tokens_30days') }}</div></div>
            </div>

            {{-- Daily chart --}}
            <div style="margin-bottom: 20px;">
                <div class="card">
                    @if($showCost)
                    <h2>{{ __('ui.daily_cost') }} / {{ __('ui.daily_tokens') }}</h2>
                    @else
                    <h2>{{ __('ui.daily_tokens') }}</h2>
                    @endif
                    <div class="chart-container" style="height: 280px;"><canvas id="combinedChart"></canvas></div>
                    <div class="chart-legend">
                        @if($showCost)
                        <span style="--c: #0071e3;">{{ __('ui.pipeline') }}</span>
                        <span style="--c: #34c759;">{{ __('ui.chat') }}</span>
                        <span style="--c: #ff9500;">{{ __('ui.embedding') }}</span>
                        @endif
                        @if($showCost)
                        <span style="--c: #8e8e93;">─ {{ __('ui.tokens') }}</span>
                        @else
                        <span style="--c: #0071e3;">{{ __('ui.tokens') }}</span>
                        <span style="display: flex; align-items: center; gap: 4px;"><span style="display: inline-block; width: 14px; height: 2px; background: #ea4335; border-radius: 1px;"></span> {{ __('ui.requests') }}</span>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Chat feedback chart: answers, upvotes, downvotes --}}
            <div style="margin-bottom: 20px;">
                <div class="card">
                    <h2>{{ __('ui.chat_feedback') }}</h2>
                    <div class="chart-container" style="height: 220px;"><canvas id="feedbackChart"></canvas></div>
                    <div class="chart-legend">
                        <span style="--c: #5f6368;">─ {{ __('ui.chat_answers') }}</span>
                        <span style="--c: #34a853;">{{ __('ui.upvotes') }}</span>
                        <span style="--c: #ea4335;">{{ __('ui.downvotes') }}</span>
                    </div>
                </div>
            </div>

            {{-- Breakdown tables --}}
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;">
                <div class="card">
                    <h2>{{ $showCost ? __('ui.cost_by_endpoint') : __('ui.usage_by_endpoint') }}</h2>
                    <table>
                        <thead><tr><th>{{ __('ui.endpoint') }}</th><th>{{ __('ui.requests') }}</th><th>{{ __('ui.tokens') }}</th>@if($showCost)<th>{{ __('ui.cost') }}</th>@endif</tr></thead>
                        <tbody>
                        @forelse($byEndpoint as $row)
                            <tr><td>{{ $row->endpoint }}</td><td>{{ number_format($row->requests) }}</td><td>{{ number_format($row->tokens) }}</td>@if($showCost)<td>${{ number_format($row->cost, 4) }}</td>@endif</tr>
                        @empty
                            <tr><td colspan="{{ $showCost ? 4 : 3 }}" class="empty">{{ __('ui.no_usage_data') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="card">
                    <h2>{{ $showCost ? __('ui.cost_by_model') : __('ui.usage_by_model') }}</h2>
                    <table>
                        <thead><tr><th>{{ __('ui.llm_model') }}</th><th>{{ __('ui.requests') }}</th><th>{{ __('ui.tokens') }}</th>@if($showCost)<th>{{ __('ui.cost') }}</th>@endif</tr></thead>
                        <tbody>
                        @forelse($byModel as $row)
                            <tr><td style="font-size: 12px;">{{ $row->model_id }}</td><td>{{ number_format($row->requests) }}</td><td>{{ number_format($row->tokens) }}</td>@if($showCost)<td>${{ number_format($row->cost, 4) }}</td>@endif</tr>
                        @empty
                            <tr><td colspan="{{ $showCost ? 4 : 3 }}" class="empty">{{ __('ui.no_usage_data') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Top searched Knowledge Units --}}
            @if(isset($topKUs) && $topKUs->isNotEmpty())
            <div class="card" style="margin-bottom: 20px;">
                <h2>{!! __('ui.top_searched_kus') !!}</h2>
                <table>
                    <thead><tr><th>{{ __('ui.topic') }}</th><th>{{ __('ui.intent') }}</th><th>{{ __('ui.search_count') }}</th></tr></thead>
                    <tbody>
                    @foreach($topKUs as $ku)
                        <tr>
                            <td><a href="{{ route('knowledge-units.show', $ku) }}" style="color: #0071e3; text-decoration: none;">{{ $ku->topic }}</a></td>
                            <td style="color: #5f6368; font-size: 13px;">{{ $ku->intent }}</td>
                            <td style="text-align: center; font-weight: 600;">{{ number_format($ku->usage_count) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </div>
    </div>
@endsection

@section('scripts')
    const dailyData = @json($dailyTrend);
    const showCost = @json($showCost);

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
            result.push(map[key] || { date: key, total_tokens: 0, total_cost: 0, pipeline_cost: 0, chat_cost: 0, embedding_cost: 0, request_count: 0 });
        }
        return result;
    }

    // Format helpers
    function fmtTokens(v) {
        if (v >= 1000000) return (v / 1000000).toFixed(1) + 'M';
        if (v >= 1000) return (v / 1000).toFixed(0) + 'K';
        return Math.round(v).toString();
    }
    function fmtCost(v) { return '$' + v.toFixed(4); }

    // Draw combined chart: stacked cost bars (left Y) + token line (right Y)
    function drawCombinedChart(canvasId, days) {
        const canvas = document.getElementById(canvasId);
        const ctx = canvas.getContext('2d');
        const dpr = window.devicePixelRatio || 1;
        const rect = canvas.parentElement.getBoundingClientRect();
        canvas.width = rect.width * dpr;
        canvas.height = rect.height * dpr;
        ctx.scale(dpr, dpr);
        const W = rect.width, H = rect.height;

        const pad = { top: 20, right: 56, bottom: 32, left: 52 };
        const chartW = W - pad.left - pad.right;
        const chartH = H - pad.top - pad.bottom;

        // Round up to a nice number for axis scales (e.g. 1.46 → 2.0, 241000 → 250000)
        function niceMax(value, steps) {
            if (value <= 0) return steps;
            const rawStep = value / steps;
            const magnitude = Math.pow(10, Math.floor(Math.log10(rawStep)));
            const residual = rawStep / magnitude;
            const niceStep = residual <= 1 ? magnitude
                           : residual <= 2 ? 2 * magnitude
                           : residual <= 5 ? 5 * magnitude
                           : 10 * magnitude;
            return niceStep * steps;
        }

        // Compute max values for both axes, rounded to nice tick marks
        const costValues = days.map(d =>
            (Number(d.pipeline_cost) || 0) + (Number(d.chat_cost) || 0) + (Number(d.embedding_cost) || 0)
        );
        const tokenValues = days.map(d => Number(d.total_tokens) || 0);
        const maxCost = niceMax(Math.max(...costValues, 0.0001), 4);
        const maxTokens = niceMax(Math.max(...tokenValues, 1), 4);

        // Draw grid lines based on cost axis (left)
        ctx.strokeStyle = '#f0f0f2';
        ctx.fillStyle = '#5f6368';
        ctx.font = '11px -apple-system, sans-serif';
        for (let i = 0; i <= 4; i++) {
            const y = pad.top + chartH - (chartH * i / 4);
            ctx.beginPath();
            ctx.moveTo(pad.left, y);
            ctx.lineTo(W - pad.right, y);
            ctx.stroke();
            if (showCost) {
                // Left Y-axis: cost
                ctx.textAlign = 'right';
                ctx.fillText(fmtCost(maxCost * i / 4), pad.left - 6, y + 4);
                // Right Y-axis: tokens
                ctx.textAlign = 'left';
                ctx.fillText(fmtTokens(maxTokens * i / 4), W - pad.right + 6, y + 4);
            } else {
                // Only token axis (left)
                ctx.textAlign = 'right';
                ctx.fillText(fmtTokens(maxTokens * i / 4), pad.left - 6, y + 4);
            }
        }

        // Draw bars
        const barW = Math.max(2, (chartW / days.length) - 2);
        const gap = (chartW - barW * days.length) / days.length;

        days.forEach((day, i) => {
            const x = pad.left + i * (barW + gap) + gap / 2;

            if (showCost) {
                // Stacked cost bars
                const segments = [
                    { value: Number(day.pipeline_cost) || 0, color: '#0071e3' },
                    { value: Number(day.chat_cost) || 0, color: '#34c759' },
                    { value: Number(day.embedding_cost) || 0, color: '#ff9500' },
                ];
                let yOffset = 0;
                segments.forEach(seg => {
                    const h = maxCost > 0 ? (seg.value / maxCost) * chartH : 0;
                    const y = pad.top + chartH - yOffset - h;
                    ctx.fillStyle = seg.color;
                    ctx.beginPath();
                    ctx.roundRect(x, y, barW, Math.max(h, h > 0 ? 1 : 0), [2, 2, 0, 0]);
                    ctx.fill();
                    yOffset += h;
                });
            } else {
                // Token bars in blue (no cost mode)
                const tokens = Number(day.total_tokens) || 0;
                const h = maxTokens > 0 ? (tokens / maxTokens) * chartH : 0;
                ctx.fillStyle = '#0071e3';
                ctx.beginPath();
                ctx.roundRect(x, pad.top + chartH - h, barW, Math.max(h, h > 0 ? 1 : 0), [2, 2, 0, 0]);
                ctx.fill();
            }

            // X-axis date labels
            if (i % 5 === 0 || i === days.length - 1) {
                ctx.fillStyle = '#5f6368';
                ctx.font = '10px -apple-system, sans-serif';
                ctx.textAlign = 'center';
                ctx.fillText(day.date.slice(5), x + barW / 2, H - pad.bottom + 14);
            }
        });

        // Non-cost mode: draw request_count line on right Y-axis
        if (!showCost) {
            const requestValues = days.map(d => Number(d.request_count) || 0);
            const maxRequests = niceMax(Math.max(...requestValues, 1), 4);

            // Right Y-axis labels for requests
            ctx.fillStyle = '#5f6368';
            ctx.font = '11px -apple-system, sans-serif';
            for (let i = 0; i <= 4; i++) {
                const y = pad.top + chartH - (chartH * i / 4);
                ctx.textAlign = 'left';
                ctx.fillText(Math.round(maxRequests * i / 4).toString(), W - pad.right + 6, y + 4);
            }

            // Draw request line
            ctx.beginPath();
            ctx.strokeStyle = '#ea4335';
            ctx.lineWidth = 2;
            ctx.lineJoin = 'round';
            days.forEach((day, i) => {
                const x = pad.left + i * (barW + gap) + gap / 2 + barW / 2;
                const requests = Number(day.request_count) || 0;
                const y = pad.top + chartH - (requests / maxRequests) * chartH;
                if (i === 0) ctx.moveTo(x, y);
                else ctx.lineTo(x, y);
            });
            ctx.stroke();

            // Draw request data points
            ctx.fillStyle = '#ea4335';
            days.forEach((day, i) => {
                const requests = Number(day.request_count) || 0;
                if (requests > 0) {
                    const x = pad.left + i * (barW + gap) + gap / 2 + barW / 2;
                    const y = pad.top + chartH - (requests / maxRequests) * chartH;
                    ctx.beginPath();
                    ctx.arc(x, y, 3, 0, Math.PI * 2);
                    ctx.fill();
                }
            });

            return;
        }

        // Draw token line on right Y-axis (only when cost bars are shown)
        ctx.beginPath();
        ctx.strokeStyle = '#8e8e93';
        ctx.lineWidth = 2;
        ctx.lineJoin = 'round';
        days.forEach((day, i) => {
            const x = pad.left + i * (barW + gap) + gap / 2 + barW / 2;
            const tokens = Number(day.total_tokens) || 0;
            const y = pad.top + chartH - (tokens / maxTokens) * chartH;
            if (i === 0) ctx.moveTo(x, y);
            else ctx.lineTo(x, y);
        });
        ctx.stroke();

        // Draw token data points
        ctx.fillStyle = '#8e8e93';
        days.forEach((day, i) => {
            const tokens = Number(day.total_tokens) || 0;
            if (tokens > 0) {
                const x = pad.left + i * (barW + gap) + gap / 2 + barW / 2;
                const y = pad.top + chartH - (tokens / maxTokens) * chartH;
                ctx.beginPath();
                ctx.arc(x, y, 3, 0, Math.PI * 2);
                ctx.fill();
            }
        });
    }

    // Render the combined chart
    const days = buildDateRange(dailyData);
    drawCombinedChart('combinedChart', days);

    // Draw feedback chart: answers as line, upvotes/downvotes as stacked bars
    function drawFeedbackChart(canvasId, days) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        const dpr = window.devicePixelRatio || 1;
        const rect = canvas.parentElement.getBoundingClientRect();
        canvas.width = rect.width * dpr;
        canvas.height = rect.height * dpr;
        ctx.scale(dpr, dpr);
        const W = rect.width, H = rect.height;
        const pad = { top: 20, right: 50, bottom: 30, left: 50 };
        const chartW = W - pad.left - pad.right;
        const chartH = H - pad.top - pad.bottom;

        // Find max values for scale
        const maxAnswers = Math.max(1, ...days.map(d => Number(d.chat_answers) || 0));
        const maxVotes = Math.max(1, ...days.map(d => (Number(d.upvotes) || 0) + (Number(d.downvotes) || 0)));
        const niceMax = (v) => { const p = Math.pow(10, Math.floor(Math.log10(v))); return Math.ceil(v / p) * p; };
        const maxA = niceMax(maxAnswers);
        const maxV = niceMax(maxVotes);

        const gap = 2;
        const barW = Math.max(4, (chartW - gap * days.length) / days.length);

        // Draw Y-axis labels (left: answers, right: votes)
        ctx.fillStyle = '#5f6368';
        ctx.font = '10px -apple-system, sans-serif';
        ctx.textAlign = 'right';
        ctx.fillText(maxA, pad.left - 6, pad.top + 8);
        ctx.fillText('0', pad.left - 6, pad.top + chartH);
        ctx.textAlign = 'left';
        ctx.fillText(maxV, W - pad.right + 6, pad.top + 8);

        // Draw bars: upvotes (green) + downvotes (red) stacked
        days.forEach((day, i) => {
            const up = Number(day.upvotes) || 0;
            const down = Number(day.downvotes) || 0;
            const x = pad.left + i * (barW + gap) + gap / 2;

            // Downvotes bar (bottom)
            if (down > 0) {
                const h = (down / maxV) * chartH;
                ctx.fillStyle = '#ea4335';
                ctx.fillRect(x, pad.top + chartH - h, barW, h);
            }
            // Upvotes bar (stacked on top)
            if (up > 0) {
                const hDown = (down / maxV) * chartH;
                const hUp = (up / maxV) * chartH;
                ctx.fillStyle = '#34a853';
                ctx.fillRect(x, pad.top + chartH - hDown - hUp, barW, hUp);
            }
        });

        // Draw answers line
        ctx.beginPath();
        ctx.strokeStyle = '#5f6368';
        ctx.lineWidth = 2;
        days.forEach((day, i) => {
            const answers = Number(day.chat_answers) || 0;
            const x = pad.left + i * (barW + gap) + gap / 2 + barW / 2;
            const y = pad.top + chartH - (answers / maxA) * chartH;
            i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
        });
        ctx.stroke();

        // Draw X-axis date labels (show every 5th)
        ctx.fillStyle = '#aaa';
        ctx.textAlign = 'center';
        ctx.font = '9px -apple-system, sans-serif';
        days.forEach((day, i) => {
            if (i % 5 === 0 || i === days.length - 1) {
                const x = pad.left + i * (barW + gap) + gap / 2 + barW / 2;
                ctx.fillText(day.date.slice(5), x, H - 6);
            }
        });
    }

    drawFeedbackChart('feedbackChart', days);
@endsection
