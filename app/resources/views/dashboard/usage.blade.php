{{-- Usage dashboard: displays token consumption and cost metrics for a configurable date range.
     Includes period selector, mini calendar, summary stats, daily bar charts (tokens & cost by category),
     breakdowns by endpoint/model, chat analytics, and day-of-week background coloring on charts. --}}
@extends(($isAdminView ?? false) ? 'layouts.admin' : 'layouts.app')
@section('title', 'Usage — KPS')
@php
    $showCost = auth()->user()->isSystemAdmin();
    $currentPeriod = $period ?? 'last_30';
    $periodStart = $startDate ?? now()->subDays(29);
    $periodEnd = $endDate ?? now();
    $periods = [
        'today' => __('ui.period_today', [], 'ja') ?: '今日',
        'yesterday' => __('ui.period_yesterday', [], 'ja') ?: '昨日',
        'this_week' => __('ui.period_this_week', [], 'ja') ?: '今週',
        'last_week' => __('ui.period_last_week', [], 'ja') ?: '先週',
        'last_7' => __('ui.period_last_7', [], 'ja') ?: '過去7日間',
        'last_28' => __('ui.period_last_28', [], 'ja') ?: '過去28日間',
        'last_30' => __('ui.period_last_30', [], 'ja') ?: '過去30日間',
        'last_90' => __('ui.period_last_90', [], 'ja') ?: '過去90日間',
        'last_12m' => __('ui.period_last_12m', [], 'ja') ?: '過去12か月',
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
        .period-bar { display: flex; gap: 6px; margin-bottom: 12px; flex-wrap: wrap; align-items: center; }
        .period-bar a { font-size: 12px; padding: 4px 10px; }
        .period-info { display: flex; align-items: center; gap: 16px; margin-bottom: 20px; }
        .period-dates { font-size: 13px; color: #5f6368; }
        .mini-cal { display: inline-grid; grid-template-columns: repeat(7, 24px); gap: 1px; font-size: 10px; text-align: center; background: #fff; border-radius: 8px; padding: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
        .mini-cal-header { color: #5f6368; font-weight: 500; padding: 2px 0; }
        .mini-cal-day { padding: 2px 0; border-radius: 4px; color: #1d1d1f; }
        .mini-cal-day.in-range { background: #e8f0fe; color: #0071e3; font-weight: 500; }
        .mini-cal-day.outside { color: #ccc; }
        .mini-cal-day.today { border: 1px solid #0071e3; }
        .mini-cal-day.sun { color: #ea4335; }
        .mini-cal-day.sat { color: #4285f4; }
        .mini-cal-day.in-range.sun { background: #fce8e6; color: #ea4335; }
        .mini-cal-day.in-range.sat { background: #e0eaff; color: #4285f4; }
@endsection

@section('body')
    <div class="page-content">
        <div class="page-container">
            <h1 style="font-size: 20px; font-weight: 600; margin-bottom: 4px;">{{ __('ui.usage') }}</h1>
            <p style="color: #5f6368; font-size: 13px; margin-bottom: 16px;">{{ __('ui.usage_description') }}</p>

            {{-- Period selector buttons --}}
            <div class="period-bar">
                @foreach($periods as $key => $label)
                    <a href="{{ request()->fullUrlWithQuery(['period' => $key]) }}"
                       class="btn btn-sm {{ $currentPeriod === $key ? 'btn-primary' : 'btn-outline' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>

            {{-- Period date range display with mini calendar --}}
            <div class="period-info">
                <div class="period-dates">
                    {{ $periodStart->format('Y/m/d') }}（{{ ['日','月','火','水','木','金','土'][$periodStart->dayOfWeek] }}）〜 {{ $periodEnd->format('Y/m/d') }}（{{ ['日','月','火','水','木','金','土'][$periodEnd->dayOfWeek] }}）
                </div>
                {{-- Mini calendar showing selected range --}}
                @php
                    // Show the month(s) of the period end date
                    $calMonth = $periodEnd->copy()->startOfMonth();
                    $calEnd = $periodEnd->copy()->endOfMonth();
                    $calStart = $calMonth->copy()->startOfWeek(Carbon\Carbon::MONDAY);
                    $todayStr = now('Asia/Tokyo')->toDateString();
                    $rangeStartStr = $periodStart->toDateString();
                    $rangeEndStr = $periodEnd->toDateString();
                @endphp
                <div style="display: flex; flex-direction: column; align-items: center;">
                    <div style="font-size: 11px; color: #5f6368; margin-bottom: 4px; font-weight: 500;">{{ $calMonth->format('Y年n月') }}</div>
                    <div class="mini-cal">
                        <div class="mini-cal-header">月</div>
                        <div class="mini-cal-header">火</div>
                        <div class="mini-cal-header">水</div>
                        <div class="mini-cal-header">木</div>
                        <div class="mini-cal-header">金</div>
                        <div class="mini-cal-header" style="color: #4285f4;">土</div>
                        <div class="mini-cal-header" style="color: #ea4335;">日</div>
                        @php $cursor = $calStart->copy(); @endphp
                        @while($cursor->lte($calEnd) || $cursor->dayOfWeek !== 1)
                            @php
                                $dateStr = $cursor->toDateString();
                                $inMonth = $cursor->month === $calMonth->month;
                                $inRange = $dateStr >= $rangeStartStr && $dateStr <= $rangeEndStr;
                                $isToday = $dateStr === $todayStr;
                                $dow = $cursor->dayOfWeek;
                                $classes = 'mini-cal-day';
                                if (!$inMonth) $classes .= ' outside';
                                if ($inRange && $inMonth) $classes .= ' in-range';
                                if ($isToday) $classes .= ' today';
                                if ($dow === 0) $classes .= ' sun';
                                if ($dow === 6) $classes .= ' sat';
                            @endphp
                            <div class="{{ $classes }}">{{ $cursor->day }}</div>
                            @php $cursor->addDay(); @endphp
                        @endwhile
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
                        @endif
                        @if($showCost)
                        <span style="--c: #8e8e93;">─ {{ __('ui.tokens') }}</span>
                        @else
                        <span style="--c: #0071e3;">{{ __('ui.tokens') }}</span>
                        <span style="display: flex; align-items: center; gap: 4px;"><span style="display: inline-block; width: 14px; height: 2px; background: #ff9500; border-radius: 1px;"></span> {{ __('ui.requests') }}</span>
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

            {{-- Chat Analytics section --}}
            @if(isset($chatAnalytics))
            <h2 style="font-size: 17px; font-weight: 600; margin-bottom: 12px; margin-top: 8px;">{{ __('ui.chat_analytics') }}</h2>

            {{-- Chat summary cards --}}
            <div class="stats-grid" style="grid-template-columns: repeat(4, 1fr); margin-bottom: 20px;">
                <div class="stat">
                    <div class="stat-value">{{ number_format($chatAnalytics['total_sessions']) }}</div>
                    <div class="stat-label">{{ __('ui.chat_sessions_30days') }}</div>
                </div>
                <div class="stat">
                    <div class="stat-value">{{ $chatAnalytics['avg_turns'] }}</div>
                    <div class="stat-label">{{ __('ui.avg_turns_per_session') }}</div>
                </div>
                <div class="stat">
                    <div class="stat-value">{{ $chatAnalytics['avg_response_ms'] > 0 ? number_format($chatAnalytics['avg_response_ms'] / 1000, 1) . 's' : '—' }}</div>
                    <div class="stat-label">{{ __('ui.avg_response_time') }}</div>
                </div>
                <div class="stat">
                    <div class="stat-value">{{ $chatAnalytics['resolution_rate'] }}%</div>
                    <div class="stat-label">{{ __('ui.resolution_rate') }}</div>
                </div>
            </div>

            {{-- Action breakdown chart --}}
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

            {{-- Chat by channel table --}}
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
    const periodStart = @json($periodStart->toDateString());
    const periodEnd = @json($periodEnd->toDateString());

    // Build date range from periodStart to periodEnd, filling missing dates with zeros
    function buildDateRange(data) {
        const map = {};
        data.forEach(d => { map[d.date] = d; });
        const result = [];
        const start = new Date(periodStart + 'T00:00:00');
        const end = new Date(periodEnd + 'T00:00:00');
        for (let dt = new Date(start); dt <= end; dt.setDate(dt.getDate() + 1)) {
            const key = dt.toISOString().slice(0, 10);
            result.push(map[key] || { date: key, total_tokens: 0, total_cost: 0, pipeline_cost: 0, chat_cost: 0, embedding_cost: 0, request_count: 0, chat_answers: 0, upvotes: 0, downvotes: 0 });
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

    // Day-of-week background color for chart columns
    function getDayColor(dateStr) {
        const d = new Date(dateStr + 'T00:00:00');
        const dow = d.getDay();
        if (dow === 0) return 'rgba(255, 0, 0, 0.04)';    // Sunday: light red
        if (dow === 6) return 'rgba(0, 100, 255, 0.04)';   // Saturday: light blue
        return 'rgba(0, 0, 0, 0.02)';                       // Weekday: light grey
    }

    // Adaptive label interval based on number of days
    function labelInterval(numDays) {
        if (numDays <= 14) return 1;
        if (numDays <= 31) return 5;
        if (numDays <= 90) return 10;
        return 30;
    }

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

        // Round up to a nice number for axis scales
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

        // Compute max values for both axes
        const costValues = days.map(d =>
            (Number(d.pipeline_cost) || 0) + (Number(d.chat_cost) || 0) + (Number(d.embedding_cost) || 0)
        );
        const tokenValues = days.map(d => Number(d.total_tokens) || 0);
        const maxCost = niceMax(Math.max(...costValues, 0.0001), 4);
        const maxTokens = niceMax(Math.max(...tokenValues, 1), 4);

        // Draw grid lines
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
                ctx.textAlign = 'right';
                ctx.fillText(fmtCost(maxCost * i / 4), pad.left - 6, y + 4);
                ctx.textAlign = 'left';
                ctx.fillText(fmtTokens(maxTokens * i / 4), W - pad.right + 6, y + 4);
            } else {
                ctx.textAlign = 'right';
                ctx.fillText(fmtTokens(maxTokens * i / 4), pad.left - 6, y + 4);
            }
        }

        // Compute bar dimensions
        const barW = Math.max(2, (chartW / days.length) - 2);
        const gap = (chartW - barW * days.length) / days.length;
        const interval = labelInterval(days.length);

        // Draw day-of-week background colors
        days.forEach((day, i) => {
            const x = pad.left + i * (barW + gap);
            ctx.fillStyle = getDayColor(day.date);
            ctx.fillRect(x, pad.top, barW + gap, chartH);
        });

        // Draw bars
        days.forEach((day, i) => {
            const x = pad.left + i * (barW + gap) + gap / 2;

            if (showCost) {
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
                const tokens = Number(day.total_tokens) || 0;
                const h = maxTokens > 0 ? (tokens / maxTokens) * chartH : 0;
                ctx.fillStyle = '#0071e3';
                ctx.beginPath();
                ctx.roundRect(x, pad.top + chartH - h, barW, Math.max(h, h > 0 ? 1 : 0), [2, 2, 0, 0]);
                ctx.fill();
            }

            // X-axis date labels
            if (i % interval === 0 || i === days.length - 1) {
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

            ctx.fillStyle = '#5f6368';
            ctx.font = '11px -apple-system, sans-serif';
            for (let i = 0; i <= 4; i++) {
                const y = pad.top + chartH - (chartH * i / 4);
                ctx.textAlign = 'left';
                ctx.fillText(Math.round(maxRequests * i / 4).toString(), W - pad.right + 6, y + 4);
            }

            ctx.beginPath();
            ctx.strokeStyle = '#ff9500';
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

            ctx.fillStyle = '#ff9500';
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

        // Draw token line on right Y-axis (cost mode)
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

        const maxAnswers = Math.max(1, ...days.map(d => Number(d.chat_answers) || 0));
        const maxVotes = Math.max(1, ...days.map(d => (Number(d.upvotes) || 0) + (Number(d.downvotes) || 0)));
        const niceMax = (v) => { const p = Math.pow(10, Math.floor(Math.log10(v))); return Math.ceil(v / p) * p; };
        const maxA = niceMax(maxAnswers);
        const maxV = niceMax(maxVotes);

        const gap = 2;
        const barW = Math.max(4, (chartW - gap * days.length) / days.length);
        const interval = labelInterval(days.length);

        // Y-axis labels
        ctx.fillStyle = '#5f6368';
        ctx.font = '10px -apple-system, sans-serif';
        ctx.textAlign = 'right';
        ctx.fillText(maxA, pad.left - 6, pad.top + 8);
        ctx.fillText('0', pad.left - 6, pad.top + chartH);
        ctx.textAlign = 'left';
        ctx.fillText(maxV, W - pad.right + 6, pad.top + 8);

        // Draw day-of-week background colors
        days.forEach((day, i) => {
            const x = pad.left + i * (barW + gap);
            ctx.fillStyle = getDayColor(day.date);
            ctx.fillRect(x, pad.top, barW + gap, chartH);
        });

        // Draw bars: upvotes (green) + downvotes (red) stacked
        days.forEach((day, i) => {
            const up = Number(day.upvotes) || 0;
            const down = Number(day.downvotes) || 0;
            const x = pad.left + i * (barW + gap) + gap / 2;

            if (down > 0) {
                const h = (down / maxV) * chartH;
                ctx.fillStyle = '#ea4335';
                ctx.fillRect(x, pad.top + chartH - h, barW, h);
            }
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

        // X-axis date labels
        ctx.fillStyle = '#aaa';
        ctx.textAlign = 'center';
        ctx.font = '9px -apple-system, sans-serif';
        days.forEach((day, i) => {
            if (i % interval === 0 || i === days.length - 1) {
                const x = pad.left + i * (barW + gap) + gap / 2 + barW / 2;
                ctx.fillText(day.date.slice(5), x, H - 6);
            }
        });
    }

    drawFeedbackChart('feedbackChart', days);

    // Action breakdown chart: stacked bars by action type + session count line
    @if(isset($chatAnalytics))
    (function() {
        const rawActions = @json($chatAnalytics['daily_actions']);
        const rawSessions = @json($chatAnalytics['daily_sessions']);

        // Build date-indexed maps for quick lookup
        const actionMap = {};
        rawActions.forEach(function(r) {
            if (!actionMap[r.date]) actionMap[r.date] = {};
            actionMap[r.date][r.action] = Number(r.count) || 0;
        });
        const sessionMap = {};
        rawSessions.forEach(function(r) { sessionMap[r.date] = Number(r.count) || 0; });

        // Fill period date range
        const actionDays = [];
        const start = new Date(periodStart + 'T00:00:00');
        const end = new Date(periodEnd + 'T00:00:00');
        for (let dt = new Date(start); dt <= end; dt.setDate(dt.getDate() + 1)) {
            const key = dt.toISOString().slice(0, 10);
            const dayActions = actionMap[key] || {};
            actionDays.push({
                date: key,
                answer: dayActions['answer'] || 0,
                answer_broad: dayActions['answer_broad'] || 0,
                no_match: dayActions['no_match'] || 0,
                rejected: dayActions['rejected'] || 0,
                sessions: sessionMap[key] || 0,
            });
        }

        const actionColors = {
            answer: '#34c759',
            answer_broad: '#ff9f0a',
            no_match: '#8e8e93',
            rejected: '#ff3b30',
        };

        drawActionChart('actionChart', actionDays, actionColors);

        function drawActionChart(canvasId, days, colors) {
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

            const maxTurns = Math.max(1, ...days.map(function(d) {
                return d.answer + d.answer_broad + d.no_match + d.rejected;
            }));
            const maxSessions = Math.max(1, ...days.map(function(d) { return d.sessions; }));

            function niceMax(v) { const p = Math.pow(10, Math.floor(Math.log10(v || 1))); return Math.ceil(v / p) * p || 1; }
            const nMaxT = niceMax(maxTurns);
            const nMaxS = niceMax(maxSessions);

            // Y-axis labels
            ctx.fillStyle = '#5f6368';
            ctx.font = '10px -apple-system, sans-serif';
            ctx.textAlign = 'right';
            ctx.fillText(nMaxT, pad.left - 6, pad.top + 8);
            ctx.fillText('0', pad.left - 6, pad.top + chartH);
            ctx.textAlign = 'left';
            ctx.fillText(nMaxS, W - pad.right + 6, pad.top + 8);

            // Grid lines
            ctx.strokeStyle = '#f0f0f2';
            for (let i = 0; i <= 4; i++) {
                const y = pad.top + chartH - (chartH * i / 4);
                ctx.beginPath();
                ctx.moveTo(pad.left, y);
                ctx.lineTo(W - pad.right, y);
                ctx.stroke();
            }

            // Bar dimensions
            const gap = 2;
            const barW = Math.max(4, (chartW - gap * days.length) / days.length);
            const segments = ['rejected', 'no_match', 'answer_broad', 'answer'];
            const interval = labelInterval(days.length);

            // Draw day-of-week background colors
            days.forEach(function(day, i) {
                const x = pad.left + i * (barW + gap);
                ctx.fillStyle = getDayColor(day.date);
                ctx.fillRect(x, pad.top, barW + gap, chartH);
            });

            // Draw stacked bars
            days.forEach(function(day, i) {
                const x = pad.left + i * (barW + gap) + gap / 2;
                let yOffset = 0;
                segments.forEach(function(seg) {
                    const val = day[seg] || 0;
                    if (val > 0) {
                        const h = (val / nMaxT) * chartH;
                        ctx.fillStyle = colors[seg];
                        ctx.fillRect(x, pad.top + chartH - yOffset - h, barW, h);
                        yOffset += h;
                    }
                });

                // X-axis labels
                if (i % interval === 0 || i === days.length - 1) {
                    ctx.fillStyle = '#aaa';
                    ctx.textAlign = 'center';
                    ctx.font = '9px -apple-system, sans-serif';
                    ctx.fillText(day.date.slice(5), x + barW / 2, H - 6);
                }
            });

            // Session count line overlay
            ctx.beginPath();
            ctx.strokeStyle = '#0071e3';
            ctx.lineWidth = 2;
            ctx.lineJoin = 'round';
            days.forEach(function(day, i) {
                const x = pad.left + i * (barW + gap) + gap / 2 + barW / 2;
                const y = pad.top + chartH - (day.sessions / nMaxS) * chartH;
                if (i === 0) ctx.moveTo(x, y);
                else ctx.lineTo(x, y);
            });
            ctx.stroke();

            // Session data points
            ctx.fillStyle = '#0071e3';
            days.forEach(function(day, i) {
                if (day.sessions > 0) {
                    const x = pad.left + i * (barW + gap) + gap / 2 + barW / 2;
                    const y = pad.top + chartH - (day.sessions / nMaxS) * chartH;
                    ctx.beginPath();
                    ctx.arc(x, y, 3, 0, Math.PI * 2);
                    ctx.fill();
                }
            });
        }
    })();
    @endif
@endsection
