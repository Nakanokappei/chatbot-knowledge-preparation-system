{{-- System health dashboard: infrastructure and application metrics for past 24 hours.
     Charts: ECS CPU/Memory, RDS connections, chat latency, pipeline duration, error rate. --}}
@extends('layouts.admin')
@section('title', __('ui.system_health') . ' — KPS')

@section('extra-styles')
        .page-content { flex: 1; overflow-y: auto; padding: 24px; background: #fff; border-radius: 12px 12px 0 0; }
        .page-container { max-width: 960px; margin: 0 auto; }
        .chart-card { background: #f5f5f7; border-radius: 12px; padding: 20px 24px 16px; margin-bottom: 20px; }
        .chart-card h2 { font-size: 15px; font-weight: 600; margin-bottom: 4px; }
        .chart-subtitle { font-size: 12px; color: #5f6368; margin-bottom: 16px; }
        .chart-container { position: relative; height: 180px; }
        .chart-container canvas { width: 100% !important; }
        .legend { display: flex; gap: 16px; justify-content: center; margin-top: 10px; font-size: 12px; color: #5f6368; flex-wrap: wrap; }
        .legend-item { display: flex; align-items: center; gap: 5px; }
        .legend-line { display: inline-block; width: 14px; height: 2px; border-radius: 1px; }
        .legend-bar { display: inline-block; width: 10px; height: 10px; border-radius: 2px; }
        .no-data { text-align: center; padding: 32px 16px; color: #a0a0a5; font-size: 13px; }
@endsection

@section('body')
<div class="page-content">
    <div class="page-container">
        <h1 style="font-size: 20px; font-weight: 600; margin-bottom: 4px;">{{ __('ui.system_health') }}</h1>
        <p style="font-size: 13px; color: #5f6368; margin-bottom: 28px;">{{ __('ui.system_health_subtitle') }}</p>

        {{-- Chart 1: ECS CPU + Memory --}}
        <div class="chart-card">
            <h2>{{ __('ui.ecs_cpu_memory') }}</h2>
            <div class="chart-subtitle">AWS/ECS · {{ config('services.system_metrics.ecs_cluster') }} / {{ config('services.system_metrics.ecs_service') }}</div>
            @if(empty($metrics['ecs']['cpu']) && empty($metrics['ecs']['memory']))
                <div class="no-data">{{ __('ui.no_data_available') }}</div>
            @else
                <div class="chart-container"><canvas id="ecsChart"></canvas></div>
                <div class="legend">
                    <span class="legend-item"><span class="legend-line" style="background:#7C3AED;"></span>{{ __('ui.cpu_utilization') }} %</span>
                    <span class="legend-item"><span class="legend-line" style="background:#0071e3;"></span>{{ __('ui.memory_utilization') }} %</span>
                </div>
            @endif
        </div>

        {{-- Chart 2: RDS DB Connections --}}
        <div class="chart-card">
            <h2>{{ __('ui.rds_connections') }}</h2>
            <div class="chart-subtitle">AWS/RDS · {{ config('services.system_metrics.rds_instance') }}</div>
            @if(empty($metrics['rds']['connections']))
                <div class="no-data">{{ __('ui.no_data_available') }}</div>
            @else
                <div class="chart-container"><canvas id="rdsChart"></canvas></div>
                <div class="legend">
                    <span class="legend-item"><span class="legend-line" style="background:#ff9500;"></span>{{ __('ui.rds_connections') }}</span>
                </div>
            @endif
        </div>

        {{-- Chart 3: Chat Response Time --}}
        <div class="chart-card">
            <h2>{{ __('ui.chat_response_time') }}</h2>
            <div class="chart-subtitle">{{ __('ui.avg_ms') }}</div>
            <div class="chart-container"><canvas id="chatChart"></canvas></div>
            <div class="legend">
                <span class="legend-item"><span class="legend-bar" style="background:#34c759;"></span>{{ __('ui.avg_ms') }}</span>
            </div>
        </div>

        {{-- Chart 4: Pipeline Processing Time --}}
        <div class="chart-card">
            <h2>{{ __('ui.pipeline_duration') }}</h2>
            <div class="chart-subtitle">{{ __('ui.avg_seconds') }}</div>
            <div class="chart-container"><canvas id="pipelineDurationChart"></canvas></div>
            <div class="legend">
                <span class="legend-item"><span class="legend-bar" style="background:#0071e3;"></span>{{ __('ui.avg_seconds') }}</span>
            </div>
        </div>

        {{-- Chart 5: Error Rate --}}
        <div class="chart-card">
            <h2>{{ __('ui.error_rate') }}</h2>
            <div class="chart-subtitle">no_match / rejected ÷ total answers (%)</div>
            <div class="chart-container"><canvas id="errorChart"></canvas></div>
            <div class="legend">
                <span class="legend-item"><span class="legend-bar" style="background:#ff3b30;"></span>{{ __('ui.error_rate') }} %</span>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
(function() {
    // Raw metric data from server (all times in JST)
    const rawEcs      = @json($metrics['ecs']);
    const rawRds      = @json($metrics['rds']);
    const rawChat     = @json($metrics['chat']);
    const rawPipeline = @json($metrics['pipeline']);
    const rawError    = @json($metrics['error_rate']);

    // ── 24-hour skeleton builder ──────────────────────────────────────────────
    // Builds an array of 24 slots (oldest → newest) in Asia/Tokyo time.
    // Each slot: { key: 'YYYY-MM-DD HH', label: 'HH:00' }
    // Uses Intl.DateTimeFormat.formatToParts() to avoid NaN from browser-dependent
    // toLocaleString() string parsing.
    function buildSkeleton() {
        const tz  = 'Asia/Tokyo';
        const fmt = new Intl.DateTimeFormat('en-CA', {
            timeZone: tz,
            year: 'numeric', month: '2-digit', day: '2-digit',
            hour: '2-digit', hour12: false,
        });
        const slots = [];
        const now   = new Date();
        for (let i = 23; i >= 0; i--) {
            const d     = new Date(now.getTime() - i * 3600000);
            const parts = Object.fromEntries(
                fmt.formatToParts(d)
                   .filter(p => p.type !== 'literal')
                   .map(p => [p.type, p.value])
            );
            // Intl may return '24' for midnight in some environments; normalise to '00'
            const h = parts.hour === '24' ? '00' : parts.hour;
            slots.push({ key: `${parts.year}-${parts.month}-${parts.day} ${h}`, label: `${h}:00` });
        }
        return slots;
    }

    // Merge server data into the 24-slot skeleton by hour key.
    function fillSeries(skeleton, rawData, valueField) {
        const map = {};
        rawData.forEach(r => { map[r.key] = r[valueField]; });
        return skeleton.map(s => map[s.key] ?? 0);
    }

    // ── Shared chart utilities ────────────────────────────────────────────────
    function niceMax(value, steps) {
        if (value <= 0) return steps;
        const rawStep  = value / steps;
        const mag      = Math.pow(10, Math.floor(Math.log10(rawStep)));
        const residual = rawStep / mag;
        const niceStep = residual <= 1 ? mag : residual <= 2 ? 2 * mag : residual <= 5 ? 5 * mag : 10 * mag;
        return niceStep * steps;
    }

    function fmtNum(v, decimals = 1) {
        return v === 0 ? '0' : v.toFixed(decimals).replace(/\.0+$/, '');
    }

    // Draw a multi-line chart (lines is [{values, color}]).
    function drawLineChart(canvasId, skeleton, lines, yMax, yFmt, yLabel) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) return;
        const ctx  = canvas.getContext('2d');
        const dpr  = window.devicePixelRatio || 1;
        const rect = canvas.parentElement.getBoundingClientRect();
        canvas.width  = rect.width  * dpr;
        canvas.height = rect.height * dpr;
        ctx.scale(dpr, dpr);
        const W   = rect.width, H = rect.height;
        const pad = { top: 20, right: 20, bottom: 28, left: 50 };
        const cw  = W - pad.left - pad.right;
        const ch  = H - pad.top  - pad.bottom;
        const n   = skeleton.length;

        // Grid lines + Y-axis labels
        ctx.strokeStyle = '#e0e0e4';
        ctx.lineWidth   = 1;
        ctx.fillStyle   = '#5f6368';
        ctx.font        = '10px -apple-system, sans-serif';
        ctx.textAlign   = 'right';
        for (let i = 0; i <= 4; i++) {
            const y = pad.top + ch - (ch * i / 4);
            ctx.beginPath(); ctx.moveTo(pad.left, y); ctx.lineTo(W - pad.right, y); ctx.stroke();
            ctx.fillText(yFmt(yMax * i / 4), pad.left - 4, y + 4);
        }

        // X-axis labels (every 6 hours)
        ctx.textAlign = 'center';
        skeleton.forEach((s, i) => {
            if (i % 6 === 0 || i === n - 1) {
                const x = pad.left + (i / (n - 1)) * cw;
                ctx.fillText(s.label, x, H - pad.bottom + 12);
            }
        });

        // Draw each line
        lines.forEach(({ values, color }) => {
            ctx.beginPath();
            ctx.strokeStyle = color;
            ctx.lineWidth   = 2;
            ctx.lineJoin    = 'round';
            values.forEach((v, i) => {
                const x = pad.left + (i / (n - 1)) * cw;
                const y = pad.top + ch - (yMax > 0 ? (v / yMax) * ch : 0);
                i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
            });
            ctx.stroke();
            // Data points
            ctx.fillStyle = color;
            values.forEach((v, i) => {
                if (v > 0) {
                    const x = pad.left + (i / (n - 1)) * cw;
                    const y = pad.top + ch - (yMax > 0 ? (v / yMax) * ch : 0);
                    ctx.beginPath(); ctx.arc(x, y, 3, 0, Math.PI * 2); ctx.fill();
                }
            });
        });
    }

    // Draw a bar chart (single series).
    function drawBarChart(canvasId, skeleton, values, yMax, yFmt, color) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) return;
        const ctx  = canvas.getContext('2d');
        const dpr  = window.devicePixelRatio || 1;
        const rect = canvas.parentElement.getBoundingClientRect();
        canvas.width  = rect.width  * dpr;
        canvas.height = rect.height * dpr;
        ctx.scale(dpr, dpr);
        const W   = rect.width, H = rect.height;
        const pad = { top: 20, right: 20, bottom: 28, left: 50 };
        const cw  = W - pad.left - pad.right;
        const ch  = H - pad.top  - pad.bottom;
        const n   = skeleton.length;

        // Grid + Y-axis
        ctx.strokeStyle = '#e0e0e4';
        ctx.lineWidth   = 1;
        ctx.fillStyle   = '#5f6368';
        ctx.font        = '10px -apple-system, sans-serif';
        ctx.textAlign   = 'right';
        for (let i = 0; i <= 4; i++) {
            const y = pad.top + ch - (ch * i / 4);
            ctx.beginPath(); ctx.moveTo(pad.left, y); ctx.lineTo(W - pad.right, y); ctx.stroke();
            ctx.fillText(yFmt(yMax * i / 4), pad.left - 4, y + 4);
        }

        // Bars + X-axis labels
        const barW = Math.max(1, (cw / n) - 2);
        const gap  = (cw - barW * n) / n;
        values.forEach((v, i) => {
            const x = pad.left + i * (barW + gap) + gap / 2;
            const h = yMax > 0 ? (v / yMax) * ch : 0;
            if (h > 0) {
                ctx.fillStyle = color;
                ctx.beginPath();
                ctx.roundRect(x, pad.top + ch - h, barW, h, [2, 2, 0, 0]);
                ctx.fill();
            }
            if (i % 6 === 0 || i === n - 1) {
                ctx.fillStyle = '#5f6368';
                ctx.textAlign = 'center';
                ctx.fillText(skeleton[i].label, x + barW / 2, H - pad.bottom + 12);
            }
        });
    }

    // ── Render all charts ─────────────────────────────────────────────────────
    const sk = buildSkeleton();

    function renderAll() {
        // Chart 1: ECS CPU + Memory (line chart, 0-100%)
        const cpuVals    = fillSeries(sk, rawEcs.cpu    || [], 'value');
        const memVals    = fillSeries(sk, rawEcs.memory || [], 'value');
        const ecsPeak    = niceMax(Math.max(...cpuVals, ...memVals, 1), 4);
        drawLineChart('ecsChart', sk,
            [{ values: cpuVals, color: '#7C3AED' }, { values: memVals, color: '#0071e3' }],
            ecsPeak, v => fmtNum(v) + '%');

        // Chart 2: RDS Connections (line chart).
        // Enforce a minimum ceiling of 4 so Y-axis steps are always ≥1 (integer, no duplicates).
        const connVals  = fillSeries(sk, rawRds.connections || [], 'value');
        const connPeak  = niceMax(Math.max(...connVals, 4), 4);
        drawLineChart('rdsChart', sk,
            [{ values: connVals, color: '#ff9500' }],
            connPeak, v => Math.round(v).toString());

        // Chart 3: Chat response time (bar chart, ms)
        const chatVals  = fillSeries(sk, rawChat, 'avg_ms');
        const chatPeak  = niceMax(Math.max(...chatVals, 100), 4);
        drawBarChart('chatChart', sk, chatVals, chatPeak,
            v => v >= 1000 ? (v / 1000).toFixed(1) + 's' : Math.round(v) + 'ms', '#34c759');

        // Chart 4: Pipeline duration (bar chart, seconds)
        const plVals    = fillSeries(sk, rawPipeline, 'avg_seconds');
        const plPeak    = niceMax(Math.max(...plVals, 1), 4);
        drawBarChart('pipelineDurationChart', sk, plVals, plPeak,
            v => v >= 60 ? Math.round(v / 60) + 'm' : Math.round(v) + 's', '#0071e3');

        // Chart 5: Error rate (bar chart, %)
        const errVals   = fillSeries(sk, rawError, 'rate');
        const errPeak   = niceMax(Math.max(...errVals, 1), 4);
        drawBarChart('errorChart', sk, errVals, errPeak,
            v => fmtNum(v, 1) + '%', '#ff3b30');
    }

    renderAll();
    window.addEventListener('resize', renderAll);
})();
@endsection
