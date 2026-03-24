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
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h1>Knowledge Preparation System</h1>
                <p class="subtitle">Pipeline Dashboard — Job Monitor & Cluster Results</p>
            </div>
            <div style="display: flex; gap: 12px; align-items: center;">
                <a href="{{ route('datasets.index') }}" style="font-size: 13px; color: #0071e3; text-decoration: none;">Datasets</a>
                <a href="{{ route('cost') }}" style="font-size: 13px; color: #0071e3; text-decoration: none;">Cost</a>
                <a href="{{ route('settings.models') }}" style="font-size: 13px; color: #0071e3; text-decoration: none;">Settings</a>
                <form method="POST" action="{{ route('logout') }}" style="display: inline;">
                    @csrf
                    <button type="submit" style="background: none; border: none; color: #86868b; font-size: 13px; cursor: pointer;">Logout ({{ auth()->user()->email }})</button>
                </form>
            </div>
        </div>

        <!-- Stats (auto-refreshed together with job list) -->
        <div class="stats" id="stats">
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
            <form method="POST" action="{{ route('dashboard.dispatch-pipeline') }}" style="display: flex; align-items: center; gap: 12px; margin-top: 12px; flex-wrap: wrap;">
                @csrf
                <select name="dataset_id" style="padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 14px;">
                    @foreach($datasets as $dataset)
                        <option value="{{ $dataset->id }}">{{ $dataset->name }} ({{ $dataset->row_count }} rows)</option>
                    @endforeach
                </select>
                <select name="llm_model_id" style="padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 14px;">
                    @foreach($llmModels as $model)
                        <option value="{{ $model->model_id }}" @if($model->is_default) selected @endif>{{ $model->display_name }}</option>
                    @endforeach
                </select>
                <a href="{{ route('settings.models') }}" style="font-size: 12px; color: #0071e3; text-decoration: none; white-space: nowrap;">Manage Models</a>
                <button type="submit" class="btn btn-primary" style="background: #30d158;">Run Full Pipeline</button>
            </form>
            @if(session('success'))
                <p style="color: #34c759; margin-top: 12px; font-size: 14px;">✓ {{ session('success') }}</p>
            @endif
            @if(session('error'))
                <p style="color: #ff3b30; margin-top: 12px; font-size: 14px;">✗ {{ session('error') }}</p>
            @endif
        </div>

        <!-- Job List (auto-refreshed via AJAX, not full page reload) -->
        <div class="card" id="job-list">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <h2 style="margin-bottom: 0;">Pipeline Jobs</h2>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <label style="font-size: 12px; color: #86868b;">
                        <input type="checkbox" id="auto-refresh" checked> Auto-refresh (5s)
                    </label>
                    <span id="refresh-indicator" style="font-size: 11px; color: #86868b; opacity: 0; transition: opacity 0.3s;"></span>
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
                            <td style="white-space: nowrap;">
                                @if($job->step_outputs_json && isset($job->step_outputs_json['clustering']))
                                    <a href="{{ route('dashboard.show', $job) }}" class="btn btn-sm btn-outline">Details</a>
                                @endif
                                @if($job->step_outputs_json && isset($job->step_outputs_json['knowledge_unit_generation']))
                                    <a href="{{ route('dashboard.knowledge-units', $job) }}" class="btn btn-sm btn-outline" style="margin-left: 4px;">KUs</a>
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
        // Partial auto-refresh: only update the job list card, not the entire page.
        // Fetches the same page and extracts #job-list via DOMParser.
        const checkbox = document.getElementById('auto-refresh');
        const indicator = document.getElementById('refresh-indicator');
        let timer = null;

        async function refreshJobList() {
            try {
                const response = await fetch(window.location.href);
                if (!response.ok) return;

                const html = await response.text();
                const doc = new DOMParser().parseFromString(html, 'text/html');

                // Update stats cards
                const freshStats = doc.getElementById('stats');
                if (freshStats) {
                    document.getElementById('stats').innerHTML = freshStats.innerHTML;
                }

                // Update job list table
                const freshJobList = doc.getElementById('job-list');
                if (freshJobList) {
                    document.getElementById('job-list').innerHTML = freshJobList.innerHTML;

                    // Restore the checkbox state after replacing content
                    const newCheckbox = document.getElementById('auto-refresh');
                    if (newCheckbox) newCheckbox.checked = true;
                    newCheckbox.addEventListener('change', onCheckboxChange);
                }

                // Brief flash to indicate a successful refresh
                indicator.textContent = 'Updated';
                indicator.style.opacity = '1';
                setTimeout(() => { indicator.style.opacity = '0'; }, 1500);
            } catch (e) {
                // Silently ignore network errors during background refresh
            }
        }

        function startAutoRefresh() {
            timer = setInterval(refreshJobList, 5000);
        }

        function stopAutoRefresh() {
            if (timer) { clearInterval(timer); timer = null; }
        }

        function onCheckboxChange() {
            checkbox.checked ? startAutoRefresh() : stopAutoRefresh();
        }

        checkbox.addEventListener('change', onCheckboxChange);
        if (checkbox.checked) startAutoRefresh();
    </script>
</body>
</html>
