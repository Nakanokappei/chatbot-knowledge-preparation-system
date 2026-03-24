<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Job #{{ $job->id }} — Knowledge Units</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f7; color: #1d1d1f; }
        .container { max-width: 960px; margin: 0 auto; padding: 24px; }
        h1 { font-size: 24px; font-weight: 600; margin-bottom: 4px; }
        .subtitle { color: #86868b; font-size: 14px; margin-bottom: 24px; }
        a { color: #0071e3; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .back { font-size: 14px; margin-bottom: 16px; display: inline-block; }
        .card { background: #fff; border-radius: 12px; padding: 24px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
        .card h2 { font-size: 16px; font-weight: 600; margin-bottom: 16px; }
        .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 20px; }
        .stat-card { background: #fff; border-radius: 12px; padding: 16px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
        .stat-value { font-size: 28px; font-weight: 700; }
        .stat-label { font-size: 12px; color: #86868b; margin-top: 4px; }
        .badge { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 12px; font-weight: 500; }
        .badge-draft { background: #fff3cd; color: #856404; }
        .badge-reviewed { background: #cce5ff; color: #004085; }
        .badge-approved { background: #d4edda; color: #155724; }
        .badge-rejected { background: #f8d7da; color: #721c24; }
        .btn { display: inline-block; padding: 6px 14px; border-radius: 8px; border: none; font-size: 13px; font-weight: 500; cursor: pointer; text-decoration: none; }
        .btn-outline { background: transparent; border: 1px solid #d2d2d7; color: #1d1d1f; }
        .btn-outline:hover { background: #f5f5f7; text-decoration: none; }
        .ku-card { border: 1px solid #e5e5e7; border-radius: 12px; padding: 20px; margin-bottom: 16px; }
        .ku-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; }
        .ku-topic { font-size: 17px; font-weight: 600; }
        .ku-intent { font-size: 13px; color: #86868b; margin-top: 2px; }
        .ku-summary { font-size: 14px; line-height: 1.6; color: #424245; margin-bottom: 12px; }
        .ku-meta { display: flex; gap: 20px; font-size: 12px; color: #86868b; flex-wrap: wrap; }
        .ku-meta strong { color: #1d1d1f; }
        .keywords { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; }
        .keyword { background: #f0f0f2; padding: 2px 8px; border-radius: 6px; font-size: 11px; color: #424245; }
        .typical-case { background: #f5f5f7; border-radius: 8px; padding: 10px 14px; margin-top: 8px; font-size: 13px; color: #424245; line-height: 1.5; }
        .typical-case-label { font-size: 11px; font-weight: 600; color: #86868b; margin-bottom: 6px; }
        .export-bar { display: flex; gap: 8px; align-items: center; }
    </style>
</head>
<body>
    <div class="container">
        <a href="{{ route('dashboard.show', $job) }}" class="back">&larr; Back to Cluster Results</a>
        <h1>Job #{{ $job->id }} — Knowledge Units</h1>
        <p class="subtitle">{{ $job->dataset->name ?? 'Unknown dataset' }} &middot; {{ $knowledgeUnits->count() }} Knowledge Units &middot; review_status: draft</p>

        <!-- Stats -->
        <div class="stats">
            <div class="stat-card">
                <div class="stat-value" style="color: #0071e3;">{{ $knowledgeUnits->count() }}</div>
                <div class="stat-label">Knowledge Units</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">{{ $knowledgeUnits->sum('row_count') }}</div>
                <div class="stat-label">Total Rows</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: #ff9500;">{{ $knowledgeUnits->where('review_status', 'draft')->count() }}</div>
                <div class="stat-label">Draft</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: #34c759;">{{ $knowledgeUnits->where('review_status', 'approved')->count() }}</div>
                <div class="stat-label">Approved</div>
            </div>
        </div>

        <!-- Bulk Actions -->
        @if($knowledgeUnits->where('review_status', 'draft')->count() > 0 || $knowledgeUnits->where('review_status', 'reviewed')->count() > 0)
        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h2 style="margin-bottom: 0;">Bulk Actions</h2>
                <div style="display: flex; gap: 8px;">
                    @if($knowledgeUnits->where('review_status', 'draft')->count() > 0)
                    <form method="POST" action="{{ route('knowledge-units.bulk-approve', $job) }}" style="display: inline;">
                        @csrf
                        <button type="submit" class="btn" style="background: #34c759; color: white;"
                                onclick="return confirm('Approve all {{ $knowledgeUnits->where('review_status', 'draft')->count() + $knowledgeUnits->where('review_status', 'reviewed')->count() }} Knowledge Units?')">
                            Approve All ({{ $knowledgeUnits->where('review_status', '!=', 'approved')->where('review_status', '!=', 'rejected')->count() }})
                        </button>
                    </form>
                    @endif
                </div>
            </div>
        </div>
        @endif

        <!-- Export -->
        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h2 style="margin-bottom: 0;">Export</h2>
                <div class="export-bar">
                    <a href="{{ route('dashboard.knowledge-units.export', ['pipelineJob' => $job->id, 'format' => 'json', 'status' => 'approved']) }}" class="btn btn-outline">JSON (Approved)</a>
                    <a href="{{ route('dashboard.knowledge-units.export', ['pipelineJob' => $job->id, 'format' => 'csv', 'status' => 'approved']) }}" class="btn btn-outline">CSV (Approved)</a>
                    <a href="{{ route('dashboard.knowledge-units.export', ['pipelineJob' => $job->id, 'format' => 'json', 'status' => 'all']) }}" class="btn btn-outline" style="opacity: 0.6;">JSON (All)</a>
                    <a href="{{ route('dashboard.knowledge-units.export', ['pipelineJob' => $job->id, 'format' => 'csv', 'status' => 'all']) }}" class="btn btn-outline" style="opacity: 0.6;">CSV (All)</a>
                </div>
            </div>
        </div>

        <!-- Knowledge Unit Cards -->
        @foreach($knowledgeUnits as $ku)
            <div class="ku-card">
                <div class="ku-header">
                    <div>
                        <a href="{{ route('knowledge-units.show', $ku) }}" style="text-decoration: none; color: inherit;">
                            <div class="ku-topic">{{ $ku->topic }}</div>
                        </a>
                        <div class="ku-intent">Intent: {{ $ku->intent }}</div>
                    </div>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <span class="badge badge-{{ $ku->review_status }}">{{ $ku->review_status }}</span>
                        <a href="{{ route('knowledge-units.show', $ku) }}" class="btn btn-sm btn-outline" style="text-decoration: none; font-size: 12px; padding: 3px 10px;">Edit / Review</a>
                    </div>
                </div>

                <div class="ku-summary">{{ $ku->summary }}</div>

                <div class="ku-meta">
                    <div><strong>{{ $ku->row_count }}</strong> rows</div>
                    <div>Version <strong>{{ $ku->version }}</strong></div>
                    <div>Cluster <strong>{{ $ku->cluster_id }}</strong></div>
                    <div>Created <strong>{{ $ku->created_at->format('m/d H:i') }}</strong></div>
                </div>

                @if($ku->keywords_json)
                    <div class="keywords">
                        @foreach($ku->keywords_json as $kw)
                            <span class="keyword">{{ $kw }}</span>
                        @endforeach
                    </div>
                @endif

                @if($ku->typical_cases_json && count($ku->typical_cases_json) > 0)
                    <div class="typical-case">
                        <div class="typical-case-label">Typical Case</div>
                        {{ Str::limit($ku->typical_cases_json[0], 250) }}
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</body>
</html>
