{{-- Usage dashboard: displays token consumption and cost metrics for a configurable date range.
     Includes GA-style period dropdown with calendar, summary stats, daily/weekly/monthly charts,
     breakdowns by endpoint/model, and chat analytics. --}}
@extends(($isAdminView ?? false) ? 'layouts.admin' : 'layouts.app')
@section('title', 'Usage — KPS')
@php
    $showCost = auth()->user()->isSystemAdmin();
    $currentPeriod = $period ?? 'last_30';
    $periodStart = $startDate ?? now()->subDays(29);
    $periodEnd = $endDate ?? now();
    $gran = $granularity ?? 'day';
    $periods = [
        'today'    => __('ui.period_today'),
        'yesterday'=> __('ui.period_yesterday'),
        'this_week'=> __('ui.period_this_week'),
        'last_week'=> __('ui.period_last_week'),
        'last_7'   => __('ui.period_last_7'),
        'last_28'  => __('ui.period_last_28'),
        'last_30'  => __('ui.period_last_30'),
        'last_90'  => __('ui.period_last_90'),
        'last_12m' => __('ui.period_last_12m'),
    ];
@endphp

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

        /* GA-style period dropdown */
        .period-trigger { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border: 1px solid #d2d2d7; border-radius: 8px; background: #fff; font-size: 13px; cursor: pointer; color: #1d1d1f; }
        .period-trigger:hover { background: #f5f5f7; }
        .period-dropdown { display: none; position: absolute; right: 0; top: calc(100% + 4px); background: #fff; border-radius: 12px; box-shadow: 0 8px 32px rgba(0,0,0,0.16); z-index: 200; min-width: 480px; }
        .period-dropdown.show { display: flex; }
        .period-presets { border-right: 1px solid #f0f0f2; padding: 12px 0; min-width: 150px; }
        .period-presets a { display: block; padding: 6px 16px; font-size: 13px; color: #1d1d1f; text-decoration: none; white-space: nowrap; }
        .period-presets a:hover { background: #f5f5f7; }
        .period-presets a.active { background: #e8f0fe; color: #0071e3; font-weight: 500; }
        .period-calendar { padding: 16px; }
        .period-cal-header { font-size: 13px; font-weight: 500; color: #1d1d1f; margin-bottom: 8px; text-align: center; }
        .period-cal-grid { display: grid; grid-template-columns: repeat(7, 28px); gap: 2px; font-size: 11px; text-align: center; }
        .period-cal-dow { color: #5f6368; font-weight: 500; padding: 4px 0; }
        .period-cal-day { padding: 4px 0; border-radius: 4px; color: #1d1d1f; }
        .period-cal-day.in-range { background: #e8f0fe; color: #0071e3; font-weight: 500; }
        .period-cal-day.outside { color: #ccc; }
        .period-cal-day.today { outline: 1px solid #0071e3; }
@endsection

@section('body')
    <div class="page-content">
        <div class="page-container">
            {{-- Page header with period dropdown on the right --}}
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;">
                <div>
                    <h1 style="font-size: 20px; font-weight: 600; margin-bottom: 4px;">{{ __('ui.usage') }}</h1>
                    <p style="color: #5f6368; font-size: 13px;">{{ __('ui.usage_description') }}</p>
                </div>
                {{-- Period selector trigger --}}
                <div style="position: relative;" id="period-wrapper">
                    <button class="period-trigger" onclick="document.getElementById('period-dd').classList.toggle('show')">
                        {{ $periodStart->format('Y/m/d') }} 〜 {{ $periodEnd->format('Y/m/d') }}
                        <svg width="10" height="6" viewBox="0 0 10 6" fill="none"><path d="M1 1L5 5L9 1" stroke="#5f6368" stroke-width="1.5" stroke-linecap="round"/></svg>
                    </button>
                    <div class="period-dropdown" id="period-dd">
                        {{-- Left: preset list --}}
                        <div class="period-presets">
                            @foreach($periods as $key => $label)
                                <a href="{{ request()->fullUrlWithQuery(['period' => $key]) }}"
                                   class="{{ $currentPeriod === $key ? 'active' : '' }}">{{ $label }}</a>
                            @endforeach
                        </div>
                        {{-- Right: calendar --}}
                        <div class="period-calendar">
                            @php
                                $calMonth = $periodEnd->copy()->startOfMonth();
                                $calEnd = $periodEnd->copy()->endOfMonth();
                                $calStart = $calMonth->copy()->startOfWeek(Carbon\Carbon::MONDAY);
                                $todayStr = now('Asia/Tokyo')->toDateString();
                                $rangeStartStr = $periodStart->toDateString();
                                $rangeEndStr = $periodEnd->toDateString();
                            @endphp
                            <div class="period-cal-header">{{ $calMonth->format('Y年n月') }}</div>
                            <div class="period-cal-grid">
                                <div class="period-cal-dow">月</div>
                                <div class="period-cal-dow">火</div>
                                <div class="period-cal-dow">水</div>
                                <div class="period-cal-dow">木</div>
                                <div class="period-cal-dow">金</div>
                                <div class="period-cal-dow" style="color: #4285f4;">土</div>
                                <div class="period-cal-dow" style="color: #ea4335;">日</div>
                                @php $cursor = $calStart->copy(); @endphp
                                @while($cursor->lte($calEnd) || $cursor->dayOfWeek !== 1)
                                    @php
                                        $dateStr = $cursor->toDateString();
                                        $inMonth = $cursor->month === $calMonth->month;
                                        $inRange = $dateStr >= $rangeStartStr && $dateStr <= $rangeEndStr;
                                        $isToday = $dateStr === $todayStr;
                                        $classes = 'period-cal-day';
                                        if (!$inMonth) $classes .= ' outside';
                                        if ($inRange && $inMonth) $classes .= ' in-range';
                                        if ($isToday) $classes .= ' today';
                                    @endphp
                                    <div class="{{ $classes }}">{{ $cursor->day }}</div>
                                    @php $cursor->addDay(); @endphp
                                @endwhile
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Summary stats --}}
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
                        <span style="--c: #8e8e93;">─ {{ __('ui.tokens') }}</span>
                        @else
                        <span style="--c: #0071e3;">{{ __('ui.tokens') }}</span>
                        <span style="display: flex; align-items: center; gap: 4px;"><span style="display: inline-block; width: 14px; height: 2px; background: #ff9500; border-radius: 1px;"></span> {{ __('ui.requests') }}</span>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Chat feedback chart --}}
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

            {{-- Chat Analytics section --}}
            @if(isset($chatAnalytics))
            <h2 style="font-size: 17px; font-weight: 600; margin-bottom: 12px; margin-top: 8px;">{{ __('ui.chat_analytics') }}</h2>

            <div class="stats-grid" style="grid-template-columns: repeat(4, 1fr); margin-bottom: 20px;">
                <div class="stat"><div class="stat-value">{{ number_format($chatAnalytics['total_sessions']) }}</div><div class="stat-label">{{ __('ui.chat_sessions_30days') }}</div></div>
                <div class="stat"><div class="stat-value">{{ $chatAnalytics['avg_turns'] }}</div><div class="stat-label">{{ __('ui.avg_turns_per_session') }}</div></div>
                <div class="stat"><div class="stat-value">{{ $chatAnalytics['avg_response_ms'] > 0 ? number_format($chatAnalytics['avg_response_ms'] / 1000, 1) . 's' : '—' }}</div><div class="stat-label">{{ __('ui.avg_response_time') }}</div></div>
                <div class="stat"><div class="stat-value">{{ $chatAnalytics['resolution_rate'] }}%</div><div class="stat-label">{{ __('ui.resolution_rate') }}</div></div>
            </div>

            <div style="margin-bottom: 20px;">
                <div class="card">
                    <h2>{{ __('ui.chat_action_breakdown') }}</h2>
                    <div class="chart-container" style="height: 240px;"><canvas id="actionChart"></canvas></div>
                    <div class="chart-legend">
                        <span style="--c: #34c759;">{{ __('ui.action_answer') }}</span>
                        <span style="--c: #ff9f0a;">{{ __('ui.action_answer_broad') }}</span>
                        <span style="--c: #8e8e93;">{{ __('ui.action_no_match') }}</span>
                        <span style="--c: #ff3b30;">{{ __('ui.action_rejected') }}</span>
                        <span style="display: flex; align-items: center; gap: 4px;"><span style="display: inline-block; width: 14px; height: 2px; background: #0071e3; border-radius: 1px;"></span> {{ __('ui.sessions') }}</span>
                    </div>
                </div>
            </div>

            <div class="card" style="margin-bottom: 20px;">
                <h2>{{ __('ui.chat_by_channel') }}</h2>
                <table>
                    <thead><tr><th>{{ __('ui.channel') }}</th><th>{{ __('ui.sessions') }}</th><th>{{ __('ui.turns') }}</th><th>{{ __('ui.resolution_rate') }}</th><th>{{ __('ui.avg_response_time') }}</th></tr></thead>
                    <tbody>
                    @forelse($chatAnalytics['channels'] as $ch)
                        @php
                            $chResolution = ($ch->total_actionable ?? 0) > 0 ? round(($ch->answered / $ch->total_actionable) * 100, 1) : 0;
                            $chAvgMs = $ch->avg_ms ?? 0;
                        @endphp
                        <tr>
                            <td>{{ $ch->channel === 'embed' ? __('ui.channel_embed') : __('ui.channel_workspace') }}</td>
                            <td>{{ number_format($ch->sessions) }}</td>
                            <td>{{ number_format($ch->turns) }}</td>
                            <td>{{ $chResolution }}%</td>
                            <td>{{ $chAvgMs > 0 ? number_format($chAvgMs / 1000, 1) . 's' : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="empty">{{ __('ui.no_chat_data') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @endif

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
    const granularity = @json($gran);

    // Close period dropdown when clicking outside
    document.addEventListener('click', function(e) {
        const wrapper = document.getElementById('period-wrapper');
        const dd = document.getElementById('period-dd');
        if (wrapper && dd && !wrapper.contains(e.target)) dd.classList.remove('show');
    });

    // Format helpers
    function fmtTokens(v) {
        if (v >= 1000000) return (v / 1000000).toFixed(1) + 'M';
        if (v >= 1000) return (v / 1000).toFixed(0) + 'K';
        return Math.round(v).toString();
    }
    function fmtCost(v) { return '$' + v.toFixed(4); }

    // X-axis label formatter based on granularity
    function fmtDateLabel(dateStr) {
        if (granularity === 'month') return dateStr.slice(0, 7);  // YYYY-MM
        return dateStr.slice(5);  // MM-DD (for day and week)
    }

    // Adaptive label interval
    function labelInterval(numItems) {
        if (numItems <= 14) return 1;
        if (numItems <= 31) return 5;
        if (numItems <= 60) return 4;
        return 3;
    }

    // Use dailyData directly (already aggregated by controller)
    const days = dailyData.map(d => ({
        date: d.date, total_tokens: Number(d.total_tokens) || 0,
        total_cost: Number(d.total_cost) || 0, pipeline_cost: Number(d.pipeline_cost) || 0,
        chat_cost: Number(d.chat_cost) || 0, embedding_cost: Number(d.embedding_cost) || 0,
        request_count: Number(d.request_count) || 0, chat_answers: Number(d.chat_answers) || 0,
        upvotes: Number(d.upvotes) || 0, downvotes: Number(d.downvotes) || 0,
    }));

    // Draw combined chart
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

        function niceMax(value, steps) {
            if (value <= 0) return steps;
            const rawStep = value / steps;
            const mag = Math.pow(10, Math.floor(Math.log10(rawStep)));
            const res = rawStep / mag;
            const nice = res <= 1 ? mag : res <= 2 ? 2*mag : res <= 5 ? 5*mag : 10*mag;
            return nice * steps;
        }

        const costValues = days.map(d => d.pipeline_cost + d.chat_cost + d.embedding_cost);
        const tokenValues = days.map(d => d.total_tokens);
        const maxCost = niceMax(Math.max(...costValues, 0.0001), 4);
        const maxTokens = niceMax(Math.max(...tokenValues, 1), 4);

        // Grid lines + Y-axis labels
        ctx.strokeStyle = '#f0f0f2'; ctx.fillStyle = '#5f6368'; ctx.font = '11px -apple-system, sans-serif';
        for (let i = 0; i <= 4; i++) {
            const y = pad.top + chartH - (chartH * i / 4);
            ctx.beginPath(); ctx.moveTo(pad.left, y); ctx.lineTo(W - pad.right, y); ctx.stroke();
            if (showCost) {
                ctx.textAlign = 'right'; ctx.fillText(fmtCost(maxCost * i / 4), pad.left - 6, y + 4);
                ctx.textAlign = 'left'; ctx.fillText(fmtTokens(maxTokens * i / 4), W - pad.right + 6, y + 4);
            } else {
                ctx.textAlign = 'right'; ctx.fillText(fmtTokens(maxTokens * i / 4), pad.left - 6, y + 4);
            }
        }

        const barW = Math.max(2, (chartW / days.length) - 2);
        const gap = (chartW - barW * days.length) / days.length;
        const interval = labelInterval(days.length);

        days.forEach((day, i) => {
            const x = pad.left + i * (barW + gap) + gap / 2;
            if (showCost) {
                const segs = [{v: day.pipeline_cost, c: '#0071e3'}, {v: day.chat_cost, c: '#34c759'}, {v: day.embedding_cost, c: '#ff9500'}];
                let yOff = 0;
                segs.forEach(s => { const h = maxCost > 0 ? (s.v / maxCost) * chartH : 0; ctx.fillStyle = s.c; ctx.beginPath(); ctx.roundRect(x, pad.top + chartH - yOff - h, barW, Math.max(h, h > 0 ? 1 : 0), [2,2,0,0]); ctx.fill(); yOff += h; });
            } else {
                const h = maxTokens > 0 ? (day.total_tokens / maxTokens) * chartH : 0;
                ctx.fillStyle = '#0071e3'; ctx.beginPath(); ctx.roundRect(x, pad.top + chartH - h, barW, Math.max(h, h > 0 ? 1 : 0), [2,2,0,0]); ctx.fill();
            }
            if (i % interval === 0 || i === days.length - 1) {
                ctx.fillStyle = '#5f6368'; ctx.font = '10px -apple-system, sans-serif'; ctx.textAlign = 'center';
                ctx.fillText(fmtDateLabel(day.date), x + barW / 2, H - pad.bottom + 14);
            }
        });

        // Line overlay
        if (!showCost) {
            const reqValues = days.map(d => d.request_count);
            const maxReq = niceMax(Math.max(...reqValues, 1), 4);
            ctx.fillStyle = '#5f6368'; ctx.font = '11px -apple-system, sans-serif';
            for (let i = 0; i <= 4; i++) { const y = pad.top + chartH - (chartH * i / 4); ctx.textAlign = 'left'; ctx.fillText(Math.round(maxReq * i / 4).toString(), W - pad.right + 6, y + 4); }
            ctx.beginPath(); ctx.strokeStyle = '#ff9500'; ctx.lineWidth = 2; ctx.lineJoin = 'round';
            days.forEach((d, i) => { const x = pad.left + i * (barW + gap) + gap / 2 + barW / 2; const y = pad.top + chartH - (d.request_count / maxReq) * chartH; i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y); });
            ctx.stroke();
            ctx.fillStyle = '#ff9500';
            days.forEach((d, i) => { if (d.request_count > 0) { const x = pad.left + i * (barW + gap) + gap / 2 + barW / 2; const y = pad.top + chartH - (d.request_count / maxReq) * chartH; ctx.beginPath(); ctx.arc(x, y, 3, 0, Math.PI * 2); ctx.fill(); } });
            return;
        }
        ctx.beginPath(); ctx.strokeStyle = '#8e8e93'; ctx.lineWidth = 2; ctx.lineJoin = 'round';
        days.forEach((d, i) => { const x = pad.left + i * (barW + gap) + gap / 2 + barW / 2; const y = pad.top + chartH - (d.total_tokens / maxTokens) * chartH; i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y); });
        ctx.stroke();
        ctx.fillStyle = '#8e8e93';
        days.forEach((d, i) => { if (d.total_tokens > 0) { const x = pad.left + i * (barW + gap) + gap / 2 + barW / 2; const y = pad.top + chartH - (d.total_tokens / maxTokens) * chartH; ctx.beginPath(); ctx.arc(x, y, 3, 0, Math.PI * 2); ctx.fill(); } });
    }

    drawCombinedChart('combinedChart', days);

    // Feedback chart
    function drawFeedbackChart(canvasId, days) {
        const canvas = document.getElementById(canvasId); if (!canvas) return;
        const ctx = canvas.getContext('2d'); const dpr = window.devicePixelRatio || 1;
        const rect = canvas.parentElement.getBoundingClientRect();
        canvas.width = rect.width * dpr; canvas.height = rect.height * dpr; ctx.scale(dpr, dpr);
        const W = rect.width, H = rect.height;
        const pad = { top: 20, right: 50, bottom: 30, left: 50 };
        const chartW = W - pad.left - pad.right, chartH = H - pad.top - pad.bottom;
        const maxA = Math.max(1, ...days.map(d => d.chat_answers));
        const maxV = Math.max(1, ...days.map(d => d.upvotes + d.downvotes));
        const niceMax = (v) => { const p = Math.pow(10, Math.floor(Math.log10(v))); return Math.ceil(v / p) * p; };
        const mA = niceMax(maxA), mV = niceMax(maxV);
        const gap = 2, barW = Math.max(4, (chartW - gap * days.length) / days.length);
        const interval = labelInterval(days.length);

        ctx.fillStyle = '#5f6368'; ctx.font = '10px -apple-system, sans-serif';
        ctx.textAlign = 'right'; ctx.fillText(mA, pad.left - 6, pad.top + 8); ctx.fillText('0', pad.left - 6, pad.top + chartH);
        ctx.textAlign = 'left'; ctx.fillText(mV, W - pad.right + 6, pad.top + 8);

        days.forEach((d, i) => {
            const x = pad.left + i * (barW + gap) + gap / 2;
            if (d.downvotes > 0) { const h = (d.downvotes / mV) * chartH; ctx.fillStyle = '#ea4335'; ctx.fillRect(x, pad.top + chartH - h, barW, h); }
            if (d.upvotes > 0) { const hD = (d.downvotes / mV) * chartH; const hU = (d.upvotes / mV) * chartH; ctx.fillStyle = '#34a853'; ctx.fillRect(x, pad.top + chartH - hD - hU, barW, hU); }
        });
        ctx.beginPath(); ctx.strokeStyle = '#5f6368'; ctx.lineWidth = 2;
        days.forEach((d, i) => { const x = pad.left + i * (barW + gap) + gap / 2 + barW / 2; const y = pad.top + chartH - (d.chat_answers / mA) * chartH; i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y); });
        ctx.stroke();
        ctx.fillStyle = '#aaa'; ctx.textAlign = 'center'; ctx.font = '9px -apple-system, sans-serif';
        days.forEach((d, i) => { if (i % interval === 0 || i === days.length - 1) { const x = pad.left + i * (barW + gap) + gap / 2 + barW / 2; ctx.fillText(fmtDateLabel(d.date), x, H - 6); } });
    }
    drawFeedbackChart('feedbackChart', days);

    // Action breakdown chart
    @if(isset($chatAnalytics))
    (function() {
        const rawActions = @json($chatAnalytics['daily_actions']);
        const rawSessions = @json($chatAnalytics['daily_sessions']);
        const actionMap = {}; rawActions.forEach(r => { if (!actionMap[r.date]) actionMap[r.date] = {}; actionMap[r.date][r.action] = Number(r.count) || 0; });
        const sessionMap = {}; rawSessions.forEach(r => { sessionMap[r.date] = Number(r.count) || 0; });

        // Use the same date buckets as the trend data (already aggregated by controller)
        const actionDays = days.map(d => ({
            date: d.date,
            answer: actionMap[d.date]?.answer || 0, answer_broad: actionMap[d.date]?.answer_broad || 0,
            no_match: actionMap[d.date]?.no_match || 0, rejected: actionMap[d.date]?.rejected || 0,
            sessions: sessionMap[d.date] || 0,
        }));

        const colors = { answer: '#34c759', answer_broad: '#ff9f0a', no_match: '#8e8e93', rejected: '#ff3b30' };

        function drawActionChart(canvasId, days, colors) {
            const canvas = document.getElementById(canvasId); if (!canvas) return;
            const ctx = canvas.getContext('2d'); const dpr = window.devicePixelRatio || 1;
            const rect = canvas.parentElement.getBoundingClientRect();
            canvas.width = rect.width * dpr; canvas.height = rect.height * dpr; ctx.scale(dpr, dpr);
            const W = rect.width, H = rect.height;
            const pad = { top: 20, right: 50, bottom: 30, left: 50 };
            const chartW = W - pad.left - pad.right, chartH = H - pad.top - pad.bottom;
            const maxT = Math.max(1, ...days.map(d => d.answer + d.answer_broad + d.no_match + d.rejected));
            const maxS = Math.max(1, ...days.map(d => d.sessions));
            function niceMax(v) { const p = Math.pow(10, Math.floor(Math.log10(v || 1))); return Math.ceil(v / p) * p || 1; }
            const nT = niceMax(maxT), nS = niceMax(maxS);

            ctx.fillStyle = '#5f6368'; ctx.font = '10px -apple-system, sans-serif';
            ctx.textAlign = 'right'; ctx.fillText(nT, pad.left - 6, pad.top + 8); ctx.fillText('0', pad.left - 6, pad.top + chartH);
            ctx.textAlign = 'left'; ctx.fillText(nS, W - pad.right + 6, pad.top + 8);
            ctx.strokeStyle = '#f0f0f2';
            for (let i = 0; i <= 4; i++) { const y = pad.top + chartH - (chartH * i / 4); ctx.beginPath(); ctx.moveTo(pad.left, y); ctx.lineTo(W - pad.right, y); ctx.stroke(); }

            const gap = 2, barW = Math.max(4, (chartW - gap * days.length) / days.length);
            const segs = ['rejected', 'no_match', 'answer_broad', 'answer'];
            const interval = labelInterval(days.length);

            days.forEach((d, i) => {
                const x = pad.left + i * (barW + gap) + gap / 2; let yOff = 0;
                segs.forEach(s => { const v = d[s] || 0; if (v > 0) { const h = (v / nT) * chartH; ctx.fillStyle = colors[s]; ctx.fillRect(x, pad.top + chartH - yOff - h, barW, h); yOff += h; } });
                if (i % interval === 0 || i === days.length - 1) { ctx.fillStyle = '#aaa'; ctx.textAlign = 'center'; ctx.font = '9px -apple-system, sans-serif'; ctx.fillText(fmtDateLabel(d.date), x + barW / 2, H - 6); }
            });
            ctx.beginPath(); ctx.strokeStyle = '#0071e3'; ctx.lineWidth = 2; ctx.lineJoin = 'round';
            days.forEach((d, i) => { const x = pad.left + i * (barW + gap) + gap / 2 + barW / 2; const y = pad.top + chartH - (d.sessions / nS) * chartH; i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y); });
            ctx.stroke();
            ctx.fillStyle = '#0071e3';
            days.forEach((d, i) => { if (d.sessions > 0) { const x = pad.left + i * (barW + gap) + gap / 2 + barW / 2; const y = pad.top + chartH - (d.sessions / nS) * chartH; ctx.beginPath(); ctx.arc(x, y, 3, 0, Math.PI * 2); ctx.fill(); } });
        }
        drawActionChart('actionChart', actionDays, colors);
    })();
    @endif
@endsection
