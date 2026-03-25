@extends('layouts.app')
@section('title', 'Pipeline — KPS')

@section('extra-styles')
        /* ── Layout: sidebar + main ─────────────────────── */
        .layout { display: flex; flex: 1; overflow: hidden; }
        .sidebar { width: 260px; background: #F6F6F6; border-right: none; display: flex; flex-direction: column; flex-shrink: 0; overflow: hidden; }
        .sidebar-tree { flex: 1; overflow-y: auto; padding: 8px; }
        .main { flex: 1; overflow-y: auto; padding: 24px; background: #fff; }

        /* Sidebar menu items */
        .sidebar-section { padding: 12px 12px 6px; font-size: 11px; font-weight: 600; color: #5f6368; text-transform: uppercase; letter-spacing: 0.5px; }
        .sidebar-item { display: flex; align-items: center; gap: 8px; padding: 8px 12px; border-radius: 0 6px 6px 0; margin-left: -8px; padding-left: 20px; cursor: pointer; text-decoration: none; color: #1d1d1f; font-size: 14px; transition: background 0.15s; margin-bottom: 1px; }
        .sidebar-item:hover { background: #E9E9E9; }
        .sidebar-item:hover .sidebar-icon { color: #1d1d1f; }
        .sidebar-item:hover .sidebar-count { color: #5f6368; }
        .sidebar-item.active { background: #DBDBDB; }
        .sidebar-item.active .sidebar-icon { color: #1d1d1f; }
        .sidebar-item.active .sidebar-count { color: #5f6368; }
        .sidebar-icon { flex-shrink: 0; color: #5f6368; width: 18px; text-align: center; }
        .sidebar-count { font-size: 11px; color: #5f6368; margin-left: auto; }

        /* Dispatch card */
        .dispatch-card { background: #f9f9fb; border-radius: 10px; padding: 16px; margin-bottom: 20px; }
        .dispatch-card h3 { font-size: 14px; font-weight: 600; margin-bottom: 12px; }

        /* Job table (same design as KU table) */
        .job-table { width: 100%; border-collapse: collapse; background: #FEFEFE; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
        .job-table th { text-align: left; padding: 10px 14px; font-size: 13px; font-weight: 500; color: #5f6368; background: #FEFEFE; border-bottom: 1px solid #e5e5e7; }
        .job-table td { padding: 12px 14px; border-bottom: 1px solid #f0f0f2; font-size: 14px; vertical-align: top; }
        .job-table tr:last-child td { border-bottom: none; }
        .job-table tr:hover td { background: #F6F6F6; cursor: pointer; }
@endsection

@section('body')
    <div class="layout">
        <!-- Sidebar: status filters -->
        <div class="sidebar">
            <div class="sidebar-tree">
                <div class="sidebar-section">Pipeline Jobs</div>

                <a href="{{ route('dashboard', ['filter' => 'all']) }}"
                   class="sidebar-item {{ $filter === 'all' ? 'active' : '' }}">
                    <span class="sidebar-icon">📋</span>
                    All Jobs
                    <span class="sidebar-count">{{ $stats['total'] }}</span>
                </a>

                <a href="{{ route('dashboard', ['filter' => 'completed']) }}"
                   class="sidebar-item {{ $filter === 'completed' ? 'active' : '' }}">
                    <span class="sidebar-icon">✅</span>
                    Completed
                    <span class="sidebar-count">{{ $stats['completed'] }}</span>
                </a>

                <a href="{{ route('dashboard', ['filter' => 'processing']) }}"
                   class="sidebar-item {{ $filter === 'processing' ? 'active' : '' }}">
                    <span class="sidebar-icon">⏳</span>
                    Processing
                    <span class="sidebar-count">{{ $stats['processing'] }}</span>
                </a>

                <a href="{{ route('dashboard', ['filter' => 'failed']) }}"
                   class="sidebar-item {{ $filter === 'failed' ? 'active' : '' }}">
                    <span class="sidebar-icon">❌</span>
                    Failed
                    <span class="sidebar-count">{{ $stats['failed'] }}</span>
                </a>

                <div class="sidebar-section" style="margin-top: 16px;">Actions</div>

                <a href="{{ route('dashboard', ['filter' => $filter, 'show' => 'dispatch']) }}"
                   class="sidebar-item {{ request('show') === 'dispatch' ? 'active' : '' }}">
                    <span class="sidebar-icon">🚀</span>
                    Run Pipeline
                </a>
            </div>
        </div>

        <!-- Main area -->
        <div class="main" id="main-content">
            @if(request('show') === 'dispatch')
                <!-- Dispatch form -->
                <h2 style="font-size: 18px; font-weight: 600; margin-bottom: 16px;">Run Pipeline</h2>

                @if(session('success'))
                    <div style="background: #d4edda; color: #155724; padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 13px;">✓ {{ session('success') }}</div>
                @endif
                @if(session('error'))
                    <div style="background: #f8d7da; color: #721c24; padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 13px;">✗ {{ session('error') }}</div>
                @endif

                <div class="dispatch-card">
                    <h3>Upload Dataset</h3>
                    <form method="POST" action="{{ route('dataset.upload') }}" enctype="multipart/form-data" style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                        @csrf
                        <input type="file" name="csv_file" accept=".csv,.txt" required style="font-size: 13px;">
                        <button type="submit" class="btn btn-primary">Upload & Configure</button>
                    </form>
                </div>

                <div class="dispatch-card">
                    <h3>Dispatch Pipeline</h3>
                    <form method="POST" action="{{ route('dashboard.dispatch-pipeline') }}">
                        @csrf
                        <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                            <select name="dataset_id" style="padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 14px;">
                                @foreach($datasets as $dataset)
                                    <option value="{{ $dataset->id }}">{{ $dataset->name }} ({{ $dataset->row_count }} rows)</option>
                                @endforeach
                            </select>
                            <select name="llm_model_id" required style="padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 14px;">
                                <option value="">-- Select LLM --</option>
                                @foreach($llmModels as $model)
                                    <option value="{{ $model->model_id }}">{{ $model->display_name }}</option>
                                @endforeach
                            </select>
                            <select name="clustering_method" id="clustering-method" style="padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 14px;">
                                <option value="hdbscan" selected>HDBSCAN (auto clusters)</option>
                                <option value="kmeans">K-Means (fixed clusters)</option>
                                <option value="agglomerative">Agglomerative (hierarchical)</option>
                                <option value="leiden">HNSW + Leiden (graph)</option>
                            </select>
                            <button type="submit" class="btn btn-primary" style="background: #30d158;">Run Full Pipeline</button>
                        </div>
                        <div id="clustering-params" style="margin-top: 10px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                            <div id="params-hdbscan">
                                <label style="font-size: 12px; color: #5f6368;">min_cluster_size</label>
                                <input type="number" name="hdbscan_min_cluster_size" value="15" min="2" max="500" style="width: 70px; padding: 6px 8px; border: 1px solid #d2d2d7; border-radius: 6px; font-size: 13px;">
                                <label style="font-size: 12px; color: #5f6368; margin-left: 8px;">min_samples</label>
                                <input type="number" name="hdbscan_min_samples" value="5" min="1" max="100" style="width: 60px; padding: 6px 8px; border: 1px solid #d2d2d7; border-radius: 6px; font-size: 13px;">
                            </div>
                            <div id="params-kmeans" style="display: none;">
                                <label style="font-size: 12px; color: #5f6368;">n_clusters</label>
                                <input type="number" name="kmeans_n_clusters" value="10" min="2" max="200" style="width: 70px; padding: 6px 8px; border: 1px solid #d2d2d7; border-radius: 6px; font-size: 13px;">
                            </div>
                            <div id="params-agglomerative" style="display: none;">
                                <label style="font-size: 12px; color: #5f6368;">n_clusters</label>
                                <input type="number" name="agglomerative_n_clusters" value="10" min="2" max="200" style="width: 70px; padding: 6px 8px; border: 1px solid #d2d2d7; border-radius: 6px; font-size: 13px;">
                                <label style="font-size: 12px; color: #5f6368; margin-left: 8px;">linkage</label>
                                <select name="agglomerative_linkage" style="padding: 6px 8px; border: 1px solid #d2d2d7; border-radius: 6px; font-size: 13px;">
                                    <option value="ward" selected>ward</option>
                                    <option value="complete">complete</option>
                                    <option value="average">average</option>
                                    <option value="single">single</option>
                                </select>
                            </div>
                            <div id="params-leiden" style="display: none;">
                                <label style="font-size: 12px; color: #5f6368;">n_neighbors</label>
                                <input type="number" name="leiden_n_neighbors" value="15" min="5" max="100" style="width: 70px; padding: 6px 8px; border: 1px solid #d2d2d7; border-radius: 6px; font-size: 13px;">
                                <label style="font-size: 12px; color: #5f6368; margin-left: 8px;">resolution</label>
                                <input type="number" name="leiden_resolution" value="1.0" min="0.1" max="10.0" step="0.1" style="width: 70px; padding: 6px 8px; border: 1px solid #d2d2d7; border-radius: 6px; font-size: 13px;">
                            </div>
                        </div>
                    </form>
                </div>
            @else
                <!-- Job list -->
                @if(session('success'))
                    <div style="background: #d4edda; color: #155724; padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 13px;">✓ {{ session('success') }}</div>
                @endif

                @if($jobs->isEmpty())
                    <div style="text-align: center; padding: 60px 20px; color: #5f6368;">
                        <div style="font-size: 48px; margin-bottom: 12px;">📭</div>
                        <div style="font-size: 16px; font-weight: 600; color: #1d1d1f; margin-bottom: 8px;">No {{ $filter !== 'all' ? $filter : '' }} jobs</div>
                        <div style="font-size: 13px;">
                            @if($filter === 'all')
                                Run a pipeline to get started.
                            @else
                                No jobs with status "{{ $filter }}".
                            @endif
                        </div>
                    </div>
                @else
                    <table class="job-table" id="job-list">
                        <tbody>
                            @foreach($jobs as $job)
                            @php
                                $clustering = $job->step_outputs_json['clustering'] ?? null;
                                $nClusters = $clustering['n_clusters'] ?? null;
                                $nNoise = $clustering['n_noise'] ?? null;
                                $silhouette = $clustering['silhouette_score'] ?? null;
                            @endphp
                            <tr onclick="window.location='{{ route('dashboard.show', $job) }}'">
                                <!-- Info column -->
                                <td style="max-width: 0; width: 100%;">
                                    <div style="font-size: 14px; font-weight: 500;">{{ $job->dataset->name ?? 'Unknown Dataset' }}</div>
                                    <div style="font-size: 13px; color: #5f6368; margin-top: 2px;">
                                        @if($nClusters !== null)
                                            {{ $nClusters }} clusters
                                            @if($silhouette !== null)
                                                · silhouette {{ number_format($silhouette, 3) }}
                                            @endif
                                        @else
                                            {{ $job->status }}
                                            @if($job->progress > 0 && $job->progress < 100)
                                                · {{ $job->progress }}%
                                            @endif
                                        @endif
                                    </div>
                                    <div style="font-size: 12px; color: #a0a0a5; margin-top: 3px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                        @if($nNoise !== null)
                                            {{ $nNoise }} noise points
                                        @elseif($job->error_detail)
                                            {{ Str::limit($job->error_detail, 80) }}
                                        @endif
                                    </div>
                                </td>
                                <!-- Right: date + status icon -->
                                <td style="white-space: nowrap; text-align: right; vertical-align: top;">
                                    <div style="font-size: 12px; color: #5f6368;"><time datetime="{{ $job->created_at->toIso8601String() }}">{{ $job->created_at->format('m/d H:i') }}</time></div>
                                    <div style="font-size: 13px; color: #5f6368; margin-top: 2px;">
                                        @if($job->progress > 0 && $job->progress < 100)
                                            <div class="progress-bar" style="width: 60px; height: 4px; background: #e5e5e7; border-radius: 2px; overflow: hidden; display: inline-block; vertical-align: middle;">
                                                <div style="height: 100%; background: #ff9500; width: {{ $job->progress }}%; border-radius: 2px;"></div>
                                            </div>
                                        @endif
                                    </div>
                                    <div style="font-size: 14px; margin-top: 4px;">
                                        @if($job->status === 'completed') ✅
                                        @elseif($job->status === 'failed') ❌
                                        @else ⏳
                                        @endif
                                    </div>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            @endif
        </div>
    </div>
@endsection

@section('scripts')
        // Clustering method parameter toggle
        const methodSelect = document.getElementById('clustering-method');
        if (methodSelect) {
            methodSelect.addEventListener('change', function() {
                const allParams = ['params-hdbscan', 'params-kmeans', 'params-agglomerative', 'params-leiden'];
                allParams.forEach(id => document.getElementById(id).style.display = 'none');
                document.getElementById('params-' + this.value).style.display = '';
            });
        }

        // Auto-refresh job list every 5 seconds (only on list view)
        @if(request('show') !== 'dispatch')
        async function refreshJobList() {
            try {
                const response = await fetch(window.location.href);
                if (!response.ok) return;
                const html = await response.text();
                const doc = new DOMParser().parseFromString(html, 'text/html');
                // Refresh sidebar counts
                const freshSidebar = doc.querySelector('.sidebar-tree');
                const currentSidebar = document.querySelector('.sidebar-tree');
                if (freshSidebar && currentSidebar) {
                    currentSidebar.innerHTML = freshSidebar.innerHTML;
                }
                // Refresh job list
                const freshMain = doc.getElementById('job-list');
                const currentMain = document.getElementById('job-list');
                if (freshMain && currentMain) {
                    currentMain.innerHTML = freshMain.innerHTML;
                }
            } catch (e) { }
        }
        setInterval(refreshJobList, 5000);
        @endif
@endsection
