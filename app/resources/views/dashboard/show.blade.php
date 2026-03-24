<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Job #{{ $job->id }} — Cluster Results</title>
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
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th { text-align: left; padding: 8px 12px; color: #86868b; font-weight: 500; border-bottom: 1px solid #e5e5e7; }
        td { padding: 10px 12px; border-bottom: 1px solid #f0f0f2; }
        .bar { height: 8px; background: #0071e3; border-radius: 4px; min-width: 2px; }
        .cluster-detail { margin-top: 12px; padding: 12px 16px; background: #f5f5f7; border-radius: 8px; font-size: 13px; }
        .cluster-detail p { margin-bottom: 6px; line-height: 1.5; color: #424245; }
        .cluster-detail .rank { color: #86868b; font-size: 11px; font-weight: 500; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 500; }
        .badge-blue { background: #e3f2fd; color: #1565c0; }
        .badge-green { background: #d4edda; color: #155724; }
        .step-outputs { font-size: 12px; color: #86868b; }
        .step-outputs dt { font-weight: 500; color: #1d1d1f; display: inline; }
        .step-outputs dd { display: inline; margin-left: 4px; margin-right: 16px; }
    </style>
</head>
<body>
    <div class="container">
        <a href="{{ route('dashboard') }}" class="back">&larr; Back to Dashboard</a>
        <h1>Job #{{ $job->id }} — Cluster Results</h1>
        <p class="subtitle">{{ $job->dataset->name ?? 'Unknown dataset' }} &middot; {{ $job->status }} &middot; {{ $job->completed_at?->format('Y-m-d H:i') ?? '' }}</p>

        <!-- Summary Stats -->
        @php
            $clusteringOutput = $job->step_outputs_json['clustering'] ?? [];
            $embeddingOutput = $job->step_outputs_json['embedding'] ?? [];
            $preprocessOutput = $job->step_outputs_json['preprocess'] ?? [];
            $totalRows = $preprocessOutput['total_rows'] ?? 0;
            $nClusters = $clusteringOutput['n_clusters'] ?? 0;
            $nNoise = $clusteringOutput['n_noise'] ?? 0;
            $silhouette = $clusteringOutput['silhouette_score'] ?? 0;
            $cacheHitRate = $embeddingOutput['cache_hit_rate'] ?? 0;
        @endphp

        <div class="stats">
            <div class="stat-card">
                <div class="stat-value">{{ $totalRows }}</div>
                <div class="stat-label">Total Rows</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: #0071e3;">{{ $nClusters }}</div>
                <div class="stat-label">Clusters</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: #86868b;">{{ $nNoise }}</div>
                <div class="stat-label">Noise Points</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">{{ number_format($silhouette, 3) }}</div>
                <div class="stat-label">Silhouette Score</div>
            </div>
        </div>

        <!-- Pipeline Step Outputs -->
        <div class="card">
            <h2>Pipeline Steps</h2>
            <dl class="step-outputs">
                @if($preprocessOutput)
                    <dt>Preprocess:</dt>
                    <dd>{{ $preprocessOutput['total_rows'] ?? '?' }} rows ({{ $preprocessOutput['dropped_rows'] ?? 0 }} dropped)</dd>
                @endif
                @if($embeddingOutput)
                    <dt>Embedding:</dt>
                    <dd>{{ $embeddingOutput['model'] ?? '?' }} &middot; {{ $embeddingOutput['embedding_dimension'] ?? '?' }}d &middot; cache {{ $cacheHitRate }}%</dd>
                @endif
                @if($clusteringOutput)
                    <dt>Clustering:</dt>
                    @php
                        $cMethod = $clusteringOutput['clustering_method'] ?? 'hdbscan';
                        $cParams = $clusteringOutput['clustering_params'] ?? $clusteringOutput['hdbscan_params'] ?? [];
                        $cParamStr = collect($cParams)->map(fn($v, $k) => "{$k}={$v}")->implode(', ');
                    @endphp
                    <dd>{{ strtoupper($cMethod) }} ({{ $cParamStr ?: 'defaults' }})</dd>
                @endif
            </dl>
        </div>

        <!-- Knowledge Units Link -->
        @if($job->step_outputs_json && isset($job->step_outputs_json['knowledge_unit_generation']))
            <div class="card" style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h2 style="margin-bottom: 4px;">Knowledge Units</h2>
                    <span style="font-size: 13px; color: #86868b;">
                        {{ $job->step_outputs_json['knowledge_unit_generation']['knowledge_units_created'] ?? 0 }} KUs generated
                    </span>
                </div>
                <div style="display: flex; gap: 8px;">
                    <a href="{{ route('dashboard.knowledge-units', $job) }}" class="btn btn-sm" style="background: #0071e3; color: #fff; text-decoration: none;">View Knowledge Units</a>
                    <a href="{{ route('dashboard.knowledge-units.export', ['pipelineJob' => $job->id, 'format' => 'json']) }}" class="btn btn-sm btn-outline" style="text-decoration: none;">JSON</a>
                    <a href="{{ route('dashboard.knowledge-units.export', ['pipelineJob' => $job->id, 'format' => 'csv']) }}" class="btn btn-sm btn-outline" style="text-decoration: none;">CSV</a>
                </div>
            </div>
        @endif

        <!-- Cluster Table -->
        <div class="card">
            <h2>Clusters ({{ $clusters->count() }})</h2>

            @if($clusters->isEmpty())
                <p style="color: #86868b; text-align: center; padding: 24px;">No clusters found for this job.</p>
            @else
                @foreach($clusters as $cluster)
                    @php
                        $pct = $totalRows > 0 ? round($cluster->row_count / $totalRows * 100, 1) : 0;
                        $maxRowCount = $clusters->max('row_count');
                        $barWidth = $maxRowCount > 0 ? round($cluster->row_count / $maxRowCount * 100) : 0;
                        $clusterReps = $representatives[$cluster->id] ?? collect();
                    @endphp

                    <div style="margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid #f0f0f2;">
                        <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 8px;">
                            <div>
                                <span style="font-weight: 600; font-size: 15px;">Cluster {{ $cluster->cluster_label }}</span>
                                @if($cluster->topic_name)
                                    <span class="badge badge-blue">{{ $cluster->topic_name }}</span>
                                @endif
                            </div>
                            <div style="font-size: 13px; color: #86868b;">
                                <strong>{{ $cluster->row_count }}</strong> rows ({{ $pct }}%)
                            </div>
                        </div>

                        <div style="margin-bottom: 10px;">
                            <div class="bar" style="width: {{ $barWidth }}%"></div>
                        </div>

                        @if($clusterReps->isNotEmpty())
                            <div class="cluster-detail">
                                <div style="font-size: 12px; font-weight: 600; color: #86868b; margin-bottom: 8px;">
                                    Representative Rows (closest to centroid)
                                </div>
                                @foreach($clusterReps as $rep)
                                    <p>
                                        <span class="rank">#{{ $rep->rank }} (d={{ number_format($rep->distance_to_centroid, 3) }})</span><br>
                                        {{ Str::limit($rep->raw_text, 200) }}
                                    </p>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach

                <!-- Noise summary -->
                @if($nNoise > 0)
                    <div style="padding: 12px 16px; background: #f5f5f7; border-radius: 8px; font-size: 13px; color: #86868b;">
                        <strong>{{ $nNoise }}</strong> rows classified as noise ({{ $totalRows > 0 ? round($nNoise / $totalRows * 100, 1) : 0 }}%)
                        — not assigned to any cluster.
                    </div>
                @endif
            @endif
        </div>
    </div>
</body>
</html>
