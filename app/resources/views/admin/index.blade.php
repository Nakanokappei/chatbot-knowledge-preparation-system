{{-- System admin dashboard: workspace list sidebar with pipeline overview,
     main area shows usage statistics for selected workspace or aggregate. --}}
@extends('layouts.admin')
@section('title', __('ui.system_admin') . ' — KPS')

@section('extra-styles')
        /* Layout: sidebar + main */
        .layout { display: flex; flex: 1; overflow: hidden; }
        .sidebar { width: 280px; background: #F0E6FA; border-right: none; display: flex; flex-direction: column; flex-shrink: 0; overflow: hidden; transition: width 0.2s ease; }
        .sidebar.collapsed { width: 52px; }
        .sidebar.collapsed .ws-name, .sidebar.collapsed .ws-count,
        .sidebar.collapsed .pipeline-header,
        .sidebar.collapsed .create-label { display: none; }
        .sidebar-tree { flex: 1; overflow-y: auto; padding: 4px 0; }

        /* Workspace list items */
        .ws-item { display: flex; align-items: center; gap: 8px; padding: 8px 12px; border-radius: 0 100px 100px 0; cursor: pointer; text-decoration: none; color: #1d1d1f; margin-right: 8px; margin-bottom: 1px; transition: background 0.15s; font-size: 14px; }
        .ws-item:hover { background: #DDD0F5; }
        .ws-item.active { background: #CCBAF0; }
        .ws-item-icon { flex-shrink: 0; color: #5f6368; }
        .ws-item.active .ws-item-icon { color: #1d1d1f; }
        .ws-name { flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-weight: 500; }
        .ws-count { font-size: 12px; color: #5f6368; flex-shrink: 0; }

        /* Pipeline section */
        .pipeline-header { font-size: 11px; font-weight: 600; color: #5f6368; text-transform: uppercase; letter-spacing: 0.5px; padding: 16px 12px 4px; }
        .pipeline-item { display: flex; align-items: center; gap: 8px; padding: 6px 12px 6px 20px; font-size: 13px; color: #5f6368; }
        .pipeline-item .pipeline-ws { font-size: 11px; color: #8e8e93; margin-left: auto; }
        .pipeline-status { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
        .pipeline-status.completed { background: #34c759; }
        .pipeline-status.failed { background: #ff3b30; }
        .pipeline-status.processing { background: #ff9500; }
        .pipeline-status.submitted { background: #d2d2d7; }

        /* Main content */
        .main { flex: 1; overflow-y: auto; padding: 24px; background: #fff; border-radius: 12px 0 0 0; }

        /* Stats grid */
        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 24px; }
        .stat-card { background: #f5f5f7; border-radius: 12px; padding: 20px; text-align: center; }
        .stat-value { font-size: 24px; font-weight: 700; color: #1d1d1f; }
        .stat-label { font-size: 12px; color: #5f6368; margin-top: 4px; }

        /* Chart */
        .chart-container { position: relative; margin-bottom: 24px; }
        .chart-container canvas { width: 100% !important; }
@endsection

@section('body')
    <div class="layout">
        {{-- Sidebar: workspace list + pipeline overview --}}
        <div class="sidebar">
            {{-- Create workspace button (same position/style as CSV upload) --}}
            <div style="padding: 10px 12px;">
                <a href="{{ route('admin.workspaces.create') }}"
                   style="display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; padding: 10px 0; background: #fff; color: #1d1d1f; border: 1px solid #d2d2d7; border-radius: 8px; text-decoration: none; font-size: 14px; font-weight: 500; transition: background 0.15s;"
                   onmouseover="this.style.background='#e8e8ea'" onmouseout="this.style.background='#fff'">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" style="flex-shrink:0;">
                        <line x1="8" y1="3" x2="8" y2="13" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                        <line x1="3" y1="8" x2="13" y2="8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                    <span class="create-label">{{ __('ui.create_workspace') }}</span>
                </a>
            </div>

            <div class="sidebar-tree">
                {{-- All Workspaces (aggregate) --}}
                <a href="{{ route('admin.index', ['workspace' => 'all']) }}"
                   class="ws-item {{ !$selectedWorkspaceId || $selectedWorkspaceId === 'all' ? 'active' : '' }}">
                    <svg class="ws-item-icon" width="16" height="16" viewBox="0 0 16 16" fill="none">
                        <rect x="1.5" y="2.5" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.2"/>
                        <rect x="9.5" y="2.5" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.2"/>
                        <rect x="1.5" y="8.5" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.2"/>
                        <rect x="9.5" y="8.5" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.2"/>
                    </svg>
                    <span class="ws-name">{{ __('ui.all_workspaces') }}</span>
                </a>

                {{-- Individual workspaces --}}
                @foreach($workspaces as $ws)
                    <a href="{{ route('admin.index', ['workspace' => $ws->id]) }}"
                       class="ws-item {{ $selectedWorkspaceId == $ws->id ? 'active' : '' }}">
                        <svg class="ws-item-icon" width="16" height="16" viewBox="0 0 16 16" fill="none">
                            <path d="M2 3.5A1.5 1.5 0 013.5 2h3.172a1.5 1.5 0 011.06.44l.828.827a1.5 1.5 0 001.06.44H12.5A1.5 1.5 0 0114 5.207V12.5a1.5 1.5 0 01-1.5 1.5h-9A1.5 1.5 0 012 12.5V3.5z" stroke="currentColor" stroke-width="1.2"/>
                        </svg>
                        <span class="ws-name">{{ $ws->name }}</span>
                        <span class="ws-count">{{ $ws->users()->count() }}</span>
                    </a>
                @endforeach

                {{-- Pipeline section --}}
                <div class="pipeline-header">{{ __('ui.pipeline') }}</div>
                @forelse($pipelineJobs->take(20) as $job)
                    @php
                        $statusClass = match(true) {
                            $job->status === 'completed' => 'completed',
                            $job->status === 'failed' => 'failed',
                            in_array($job->status, ['submitted']) => 'submitted',
                            default => 'processing',
                        };
                    @endphp
                    <div class="pipeline-item">
                        <span class="pipeline-status {{ $statusClass }}"></span>
                        <span style="flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">{{ $job->dataset->name ?? 'Job #' . $job->id }}</span>
                        <span class="pipeline-ws">{{ $job->workspace_id }}</span>
                    </div>
                @empty
                    <div class="pipeline-item" style="color: #8e8e93;">{{ __('ui.no_jobs') }}</div>
                @endforelse
            </div>
        </div>

        {{-- Main content: usage stats --}}
        <div class="main">
            <h2 style="font-size: 20px; font-weight: 600; margin-bottom: 4px;">
                @if($selectedWorkspace)
                    {{ $selectedWorkspace->name }} — {{ __('ui.usage') }}
                @else
                    {{ __('ui.all_workspaces') }} — {{ __('ui.usage') }}
                @endif
            </h2>
            <p style="color: #5f6368; font-size: 13px; margin-bottom: 24px;">{{ __('ui.usage_30days') }}</p>

            @if(session('success'))
                <div style="background: #d4edda; color: #155724; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px;">✓ {{ session('success') }}</div>
            @endif

            {{-- Summary stats --}}
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value">${{ number_format($usageData['monthly']['cost'], 4) }}</div>
                    <div class="stat-label">{{ __('ui.cost_30days') }}</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value">{{ number_format($usageData['monthly']['tokens']) }}</div>
                    <div class="stat-label">{{ __('ui.tokens_30days') }}</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value">{{ number_format($usageData['monthly']['requests']) }}</div>
                    <div class="stat-label">{{ __('ui.requests_30days') }}</div>
                </div>
            </div>

            {{-- Daily trend chart --}}
            <div class="card">
                <h2>{{ __('ui.daily_trend') }}</h2>
                <div class="chart-container">
                    <canvas id="dailyChart" height="200"></canvas>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('scripts')
        // Daily trend chart (tokens only — simple bar chart)
        (function() {
            const canvas = document.getElementById('dailyChart');
            if (!canvas) return;
            const ctx = canvas.getContext('2d');
            const rawData = @json($usageData['dailyTrend']);

            // Build 30-day date range with zero-fills
            const data = [];
            const today = new Date();
            for (let i = 29; i >= 0; i--) {
                const d = new Date(today);
                d.setDate(d.getDate() - i);
                const dateStr = d.toISOString().slice(0, 10);
                const found = rawData.find(r => r.date === dateStr);
                data.push({
                    date: dateStr,
                    tokens: found ? Number(found.total_tokens) : 0,
                    cost: found ? Number(found.total_cost) : 0,
                });
            }

            const maxTokens = Math.max(...data.map(d => d.tokens), 1);

            function draw() {
                const w = canvas.parentElement.clientWidth;
                const h = 200;
                canvas.width = w * 2;
                canvas.height = h * 2;
                canvas.style.width = w + 'px';
                canvas.style.height = h + 'px';
                ctx.scale(2, 2);

                const pad = { top: 20, right: 20, bottom: 30, left: 60 };
                const cw = w - pad.left - pad.right;
                const ch = h - pad.top - pad.bottom;
                const barW = Math.max(cw / data.length - 2, 2);

                ctx.clearRect(0, 0, w, h);

                // Grid
                ctx.strokeStyle = '#f0f0f2';
                ctx.lineWidth = 0.5;
                for (let i = 0; i <= 4; i++) {
                    const y = pad.top + ch * (1 - i / 4);
                    ctx.beginPath();
                    ctx.moveTo(pad.left, y);
                    ctx.lineTo(w - pad.right, y);
                    ctx.stroke();
                }

                // Y axis labels
                ctx.fillStyle = '#8e8e93';
                ctx.font = '10px Roboto';
                ctx.textAlign = 'right';
                for (let i = 0; i <= 4; i++) {
                    const y = pad.top + ch * (1 - i / 4);
                    const val = (maxTokens * i / 4);
                    ctx.fillText(val >= 1000 ? (val / 1000).toFixed(0) + 'K' : val.toFixed(0), pad.left - 8, y + 3);
                }

                // Bars (tokens)
                data.forEach((d, i) => {
                    const x = pad.left + (cw / data.length) * i + 1;
                    const barH = (d.tokens / maxTokens) * ch;
                    ctx.fillStyle = '#7C3AED';
                    ctx.fillRect(x, pad.top + ch - barH, barW, barH);
                });

                // X axis labels (every 5 days)
                ctx.fillStyle = '#8e8e93';
                ctx.textAlign = 'center';
                data.forEach((d, i) => {
                    if (i % 5 === 0) {
                        const x = pad.left + (cw / data.length) * i + barW / 2;
                        ctx.fillText(d.date.slice(5), x, h - 8);
                    }
                });
            }

            draw();
            window.addEventListener('resize', draw);
        })();
@endsection
