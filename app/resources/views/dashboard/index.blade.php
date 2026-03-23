<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Knowledge Preparation System — Dashboard</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f7; color: #1d1d1f; }
        .container { max-width: 960px; margin: 0 auto; padding: 24px; }
        h1 { font-size: 24px; font-weight: 600; margin-bottom: 8px; }
        .subtitle { color: #86868b; font-size: 14px; margin-bottom: 32px; }
        .card { background: #fff; border-radius: 12px; padding: 24px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
        .card h2 { font-size: 16px; font-weight: 600; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th { text-align: left; padding: 8px 12px; color: #86868b; font-weight: 500; border-bottom: 1px solid #e5e5e7; }
        td { padding: 10px 12px; border-bottom: 1px solid #f0f0f2; }
        .status { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 12px; font-weight: 500; }
        .status-submitted { background: #f0f0f2; color: #86868b; }
        .status-validating, .status-preprocessing, .status-embedding, .status-clustering { background: #fff3cd; color: #856404; }
        .status-completed { background: #d4edda; color: #155724; }
        .status-failed { background: #f8d7da; color: #721c24; }
        .progress-bar { width: 100%; height: 6px; background: #e5e5e7; border-radius: 3px; overflow: hidden; }
        .progress-bar-fill { height: 100%; background: #34c759; border-radius: 3px; transition: width 0.5s; }
        .btn { display: inline-block; padding: 8px 20px; border-radius: 8px; border: none; font-size: 14px; font-weight: 500; cursor: pointer; text-decoration: none; }
        .btn-primary { background: #0071e3; color: #fff; }
        .btn-primary:hover { background: #0077ed; }
        .btn-sm { padding: 4px 12px; font-size: 12px; }
        .btn-outline { background: transparent; border: 1px solid #d2d2d7; color: #1d1d1f; }
        .btn-outline:hover { background: #f5f5f7; }
        .empty { text-align: center; padding: 40px; color: #86868b; }
        .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 20px; }
        .stat-card { background: #fff; border-radius: 12px; padding: 16px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
        .stat-value { font-size: 28px; font-weight: 700; }
        .stat-label { font-size: 12px; color: #86868b; margin-top: 4px; }
        .refresh-note { font-size: 12px; color: #86868b; margin-top: 8px; }
        #auto-refresh { margin-right: 4px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Knowledge Preparation System</h1>
        <p class="subtitle">Pipeline Dashboard — Job Monitor & Cluster Results</p>

        <!-- Stats -->
        <div class="stats">
            <div class="stat-card">
                <div class="stat-value">{{ $stats['total'] }}</div>
                <div class="stat-label">Total Jobs</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: #34c759;">{{ $stats['completed'] }}</div>
                <div class="stat-label">Completed</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: #ff9500;">{{ $stats['processing'] }}</div>
                <div class="stat-label">Processing</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: #ff3b30;">{{ $stats['failed'] }}</div>
                <div class="stat-label">Failed</div>
            </div>
        </div>

        <!-- New Job -->
        <div class="card">
            <h2>Dispatch Test Job</h2>
            <form method="POST" action="{{ route('dashboard.dispatch') }}" style="display: flex; align-items: center; gap: 12px;">
                @csrf
                <select name="dataset_id" style="padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 14px;">
                    @foreach($datasets as $dataset)
                        <option value="{{ $dataset->id }}">{{ $dataset->name }} ({{ $dataset->row_count }} rows)</option>
                    @endforeach
                </select>
                <button type="submit" class="btn btn-primary">Dispatch Ping Job</button>
            </form>
            <form method="POST" action="{{ route('dashboard.dispatch-pipeline') }}" style="display: flex; align-items: center; gap: 12px; margin-top: 12px;">
                @csrf
                <select name="dataset_id" style="padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 14px;">
                    @foreach($datasets as $dataset)
                        <option value="{{ $dataset->id }}">{{ $dataset->name }} ({{ $dataset->row_count }} rows)</option>
                    @endforeach
                </select>
                <button type="submit" class="btn btn-primary" style="background: #30d158;">Run Full Pipeline</button>
            </form>
            @if(session('success'))
                <p style="color: #34c759; margin-top: 12px; font-size: 14px;">✓ {{ session('success') }}</p>
            @endif
            @if(session('error'))
                <p style="color: #ff3b30; margin-top: 12px; font-size: 14px;">✗ {{ session('error') }}</p>
            @endif
        </div>

        <!-- Job List -->
        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <h2 style="margin-bottom: 0;">Pipeline Jobs</h2>
                <div>
                    <label style="font-size: 12px; color: #86868b;">
                        <input type="checkbox" id="auto-refresh" checked> Auto-refresh (5s)
                    </label>
                    <a href="{{ route('dashboard') }}" class="btn btn-sm btn-outline">Refresh</a>
                </div>
            </div>

            @if($jobs->isEmpty())
                <div class="empty">No jobs yet. Dispatch a test job above.</div>
            @else
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Dataset</th>
                            <th>Status</th>
                            <th>Progress</th>
                            <th>Clusters</th>
                            <th>Created</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($jobs as $job)
                        <tr>
                            <td>#{{ $job->id }}</td>
                            <td>{{ $job->dataset->name ?? '—' }}</td>
                            <td><span class="status status-{{ $job->status }}">{{ $job->status }}</span></td>
                            <td>
                                <div class="progress-bar">
                                    <div class="progress-bar-fill" style="width: {{ $job->progress }}%"></div>
                                </div>
                                <span style="font-size: 12px; color: #86868b;">{{ $job->progress }}%</span>
                            </td>
                            <td>
                                @if($job->step_outputs_json && isset($job->step_outputs_json['clustering']))
                                    <span style="font-size: 13px; font-weight: 600;">{{ $job->step_outputs_json['clustering']['n_clusters'] }}</span>
                                    <span style="font-size: 11px; color: #86868b;">+{{ $job->step_outputs_json['clustering']['n_noise'] }} noise</span>
                                @else
                                    <span style="color: #d2d2d7;">—</span>
                                @endif
                            </td>
                            <td style="font-size: 12px; color: #86868b;">{{ $job->created_at->format('m/d H:i') }}</td>
                            <td>
                                @if($job->status === 'completed' && $job->step_outputs_json && isset($job->step_outputs_json['clustering']))
                                    <a href="{{ route('dashboard.show', $job) }}" class="btn btn-sm btn-outline">Details</a>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    <script>
        // Auto-refresh every 5 seconds when checkbox is checked
        const checkbox = document.getElementById('auto-refresh');
        let timer = null;

        function startAutoRefresh() {
            timer = setInterval(() => { window.location.reload(); }, 5000);
        }

        function stopAutoRefresh() {
            if (timer) clearInterval(timer);
        }

        checkbox.addEventListener('change', () => {
            checkbox.checked ? startAutoRefresh() : stopAutoRefresh();
        });

        if (checkbox.checked) startAutoRefresh();
    </script>
</body>
</html>
