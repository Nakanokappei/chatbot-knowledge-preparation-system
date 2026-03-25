@extends('layouts.app')
@section('title', 'Workspace — KPS')

@section('extra-styles')
        /* ── Layout: tree sidebar + main ─────────────────────── */
        .layout { display: flex; flex: 1; overflow: hidden; }
        .sidebar { width: 280px; background: #F6F6F6; border-right: none; display: flex; flex-direction: column; flex-shrink: 0; overflow: hidden; transition: width 0.2s ease; }

        /* Collapsed sidebar: show icons only */
        .sidebar.collapsed { width: 52px; }
        .sidebar.collapsed .upload-label { display: none; }
        .sidebar.collapsed .sidebar-upload-btn a { padding: 8px 0; border: none; background: transparent; }
        .sidebar.collapsed .tree-dataset-name,
        .sidebar.collapsed .tree-emb-label,
        .sidebar.collapsed .tree-children,
        .sidebar.collapsed .tree-create-link { display: none; }
        .sidebar.collapsed .tree-dataset-header { justify-content: center; padding: 7px 0; position: relative; flex-direction: column; gap: 0; align-items: center; }
        .sidebar.collapsed .tree-toggle { display: none; }
        .sidebar.collapsed .tree-dataset-count { font-size: 9px; position: absolute; bottom: -2px; right: 2px; }
        .sidebar.collapsed .tree-icon { margin: 0 auto; }
        .sidebar.collapsed .no-datasets-msg { display: none; }
        .sidebar.collapsed .pipeline-header { display: none; }
        .sidebar.collapsed #pipeline-sidebar .tree-emb-label { display: none; }
        .sidebar.collapsed #pipeline-sidebar .tree-emb { padding-left: 0; justify-content: center; }
        .sidebar.collapsed #pipeline-sidebar .tree-emb-count { display: none; }
        .sidebar-tree { flex: 1; overflow-y: auto; padding: 4px 0 4px 0; }

        /* Tree: dataset node (parent) */
        .tree-dataset { margin-bottom: 2px; }
        .tree-dataset-header { display: flex; align-items: center; gap: 6px; padding: 8px 8px 8px 12px; border-radius: 0 100px 100px 0; cursor: pointer; transition: background 0.15s; user-select: none; margin-right: 8px; }
        .tree-dataset-header:hover { background: #E9E9E9; }
        .tree-toggle { font-size: 9px; color: #5f6368; width: 12px; text-align: center; flex-shrink: 0; transition: transform 0.15s; }
        .tree-toggle.open { transform: rotate(90deg); }
        .tree-icon { flex-shrink: 0; color: #5f6368; }
        .tree-dataset-name { font-size: 15px; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex: 1; }
        .tree-dataset-count { font-size: 12px; color: #5f6368; flex-shrink: 0; }

        /* Tree: embedding node (child) — left edge flush with window */
        .tree-children { padding-left: 0; margin-left: 0; overflow: hidden; transition: max-height 0.2s ease; }
        .tree-children.collapsed { max-height: 0 !important; }
        .tree-emb { display: flex; align-items: center; gap: 6px; padding: 7px 8px 7px 32px; border-radius: 0 100px 100px 0; cursor: pointer; text-decoration: none; color: #1d1d1f; margin-bottom: 1px; transition: background 0.15s; margin-right: 8px; }
        .tree-emb:hover { background: #E9E9E9; }
        .tree-emb:hover .tree-emb-icon { color: #1d1d1f; }
        .tree-emb:hover .tree-emb-count { color: #5f6368; }
        .tree-emb.active { background: #DBDBDB; }
        .tree-emb.active .tree-emb-count { color: #5f6368; }
        .tree-emb-icon { flex-shrink: 0; color: #5f6368; }
        .tree-emb.active .tree-emb-icon { color: #1d1d1f; }
        .tree-emb-label { font-size: 14px; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex: 1; }
        .tree-emb-count { font-size: 12px; color: #5f6368; flex-shrink: 0; margin-left: auto; }

        /* Main content area — white with top-left corner radius over grey body */
        .main { flex: 1; overflow-y: auto; padding: 24px; background: #fff; border-radius: 12px 0 0 0; }
        .main-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .main-header h2 { font-size: 20px; font-weight: 600; margin-bottom: 0; }
        .main-actions { display: flex; gap: 8px; }
        .ku-table { width: 100%; border-collapse: collapse; background: #FEFEFE; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
        .ku-table th { text-align: left; padding: 10px 14px; font-size: 14px; font-weight: 500; color: #5f6368; background: #FEFEFE; border-bottom: 1px solid #e5e5e7; }
        .ku-table td { padding: 12px 14px; border-bottom: 1px solid #f0f0f2; font-size: 15px; }
        .ku-table tr:last-child td { border-bottom: none; }
        .ku-table tr:hover td { background: #F6F6F6; }
        .empty-icon { margin-bottom: 12px; color: #5f6368; }
        .empty-title { font-size: 18px; font-weight: 600; color: #1d1d1f; margin-bottom: 8px; }
@endsection

@section('body')
    <div class="layout">
        <!-- Tree sidebar: datasets > embeddings -->
        <div class="sidebar">
            <div class="sidebar-upload-btn" style="padding: 10px 12px;">
                <a href="javascript:void(0)" onclick="openDispatchModal()"
                   style="display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; padding: 10px 0; background: #fff; color: #1d1d1f; border: 1px solid #d2d2d7; border-radius: 8px; text-decoration: none; font-size: 14px; font-weight: 500; transition: background 0.15s;"
                   onmouseover="this.style.background='#e8e8ea'" onmouseout="this.style.background='#fff'">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" style="flex-shrink:0;">
                        <path d="M8 1v9M5 7l3 3 3-3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M2 11v2a1 1 0 001 1h10a1 1 0 001-1v-2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span class="upload-label">{{ __('ui.upload_csv') }}</span>
                </a>
            </div>
            <div class="sidebar-tree">
                @forelse($datasets as $ds)
                    @php
                        $hasActiveChild = $current && $ds->embeddings->contains('id', $current->id);
                    @endphp
                    <div class="tree-dataset">
                        <div class="tree-dataset-header" onclick="toggleTree(this)">
                            <span class="tree-toggle {{ $hasActiveChild || $loop->first ? 'open' : '' }}">&#9654;</span>
                            <svg class="tree-icon" width="16" height="16" viewBox="0 0 16 16" fill="none">
                                <path d="M2 3.5A1.5 1.5 0 013.5 2h3.172a1.5 1.5 0 011.06.44l.828.827a1.5 1.5 0 001.06.44H12.5A1.5 1.5 0 0114 5.207V12.5a1.5 1.5 0 01-1.5 1.5h-9A1.5 1.5 0 012 12.5V3.5z" stroke="currentColor" stroke-width="1.2"/>
                            </svg>
                            <span class="tree-dataset-name">{{ $ds->name }}</span>
                            <span class="tree-dataset-count">{{ $ds->row_count }}</span>
                        </div>
                        <div class="tree-children {{ !$hasActiveChild && !$loop->first ? 'collapsed' : '' }}"
                             style="max-height: {{ ($hasActiveChild || $loop->first) ? ($ds->embeddings->count() * 40 + 80) . 'px' : '0' }};">
                            @forelse($ds->embeddings as $emb)
                                <a href="{{ route('workspace.embedding', ['embeddingId' => $emb->id]) }}"
                                   class="tree-emb {{ $current && $current->id === $emb->id ? 'active' : '' }}">
                                    <svg class="tree-emb-icon" width="14" height="14" viewBox="0 0 14 14" fill="none">
                                        <circle cx="3" cy="3" r="1.2" stroke="currentColor" stroke-width="1"/>
                                        <circle cx="11" cy="7" r="1.2" stroke="currentColor" stroke-width="1"/>
                                        <circle cx="3" cy="11" r="1.2" stroke="currentColor" stroke-width="1"/>
                                        <line x1="4.2" y1="3.4" x2="9.8" y2="6.6" stroke="currentColor" stroke-width="0.8"/>
                                        <line x1="4.2" y1="10.6" x2="9.8" y2="7.4" stroke="currentColor" stroke-width="0.8"/>
                                    </svg>
                                    <span class="tree-emb-label">{{ $emb->name }}</span>
                                    <span class="tree-emb-count">{{ $emb->row_count }}</span>
                                </a>
                            @empty
                                <div style="padding: 4px 32px; font-size: 11px; color: #aaa;">{{ __('ui.no_embeddings') }}</div>
                                <form method="POST" action="{{ route('dataset.destroy', $ds) }}" style="padding: 2px 32px;"
                                    onsubmit="return confirm('{{ __('ui.confirm_delete_dataset') }}')">
                                    @csrf @method('DELETE')
                                    <button type="submit" style="background: none; border: none; color: #ff3b30; font-size: 11px; cursor: pointer; padding: 2px 0;">
                                        {{ __('ui.delete_dataset') }}
                                    </button>
                                </form>
                            @endforelse
                        </div>
                    </div>
                @empty
                    <div class="no-datasets-msg" style="padding: 24px; text-align: center; color: #5f6368; font-size: 13px;">
                        {{ __('ui.no_datasets') }}
                    </div>
                @endforelse

                <!-- Pipeline section -->
                <div id="pipeline-sidebar" style="margin-top: 8px; border-top: 1px solid #e0e0e2; padding-top: 8px;">
                    <div class="pipeline-header" style="padding: 6px 12px; font-size: 12px; font-weight: 600; color: #5f6368; text-transform: uppercase; letter-spacing: 0.5px;">{{ __('ui.pipeline') }}</div>

                    <a href="?pipeline=jobs&pf=all"
                       class="tree-emb {{ ($pipelineView === 'jobs' && $pipelineFilter === 'all') ? 'active' : '' }}"
                       style="padding-left: 16px; margin-right: 8px;">
                        <svg class="tree-emb-icon" width="14" height="14" viewBox="0 0 14 14" fill="none"><rect x="1.5" y="1.5" width="11" height="11" rx="2" stroke="currentColor" stroke-width="1.2"/><line x1="4" y1="5" x2="10" y2="5" stroke="currentColor" stroke-width="1"/><line x1="4" y1="7.5" x2="10" y2="7.5" stroke="currentColor" stroke-width="1"/><line x1="4" y1="10" x2="7.5" y2="10" stroke="currentColor" stroke-width="1"/></svg>
                        <span class="tree-emb-label">{{ __('ui.all_jobs') }}</span>
                        <span class="tree-emb-count">{{ $jobStats['total'] }}</span>
                    </a>

                    <a href="?pipeline=jobs&pf=completed"
                       class="tree-emb {{ ($pipelineView === 'jobs' && $pipelineFilter === 'completed') ? 'active' : '' }}"
                       style="padding-left: 16px; margin-right: 8px;">
                        <svg class="tree-emb-icon" width="14" height="14" viewBox="0 0 14 14" fill="none"><circle cx="7" cy="7" r="5.5" stroke="currentColor" stroke-width="1.2"/><path d="M4.5 7l2 2 3.5-3.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <span class="tree-emb-label">{{ __('ui.completed') }}</span>
                        <span class="tree-emb-count">{{ $jobStats['completed'] }}</span>
                    </a>

                    <a href="?pipeline=jobs&pf=processing"
                       class="tree-emb {{ ($pipelineView === 'jobs' && $pipelineFilter === 'processing') ? 'active' : '' }}"
                       style="padding-left: 16px; margin-right: 8px;">
                        <svg class="tree-emb-icon" width="14" height="14" viewBox="0 0 14 14" fill="none"><circle cx="7" cy="7" r="5.5" stroke="currentColor" stroke-width="1.2"/><path d="M7 4v3.5l2.5 1.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <span class="tree-emb-label">{{ __('ui.processing') }}</span>
                        <span class="tree-emb-count">{{ $jobStats['processing'] }}</span>
                    </a>

                    <a href="?pipeline=jobs&pf=failed"
                       class="tree-emb {{ ($pipelineView === 'jobs' && $pipelineFilter === 'failed') ? 'active' : '' }}"
                       style="padding-left: 16px; margin-right: 8px;">
                        <svg class="tree-emb-icon" width="14" height="14" viewBox="0 0 14 14" fill="none"><circle cx="7" cy="7" r="5.5" stroke="currentColor" stroke-width="1.2"/><line x1="5" y1="5" x2="9" y2="9" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/><line x1="9" y1="5" x2="5" y2="9" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
                        <span class="tree-emb-label">{{ __('ui.failed') }}</span>
                        <span class="tree-emb-count">{{ $jobStats['failed'] }}</span>
                    </a>

                </div>
            </div>
        </div>

        <!-- Main content -->
        <div class="main">
            @if($pipelineView === 'jobs')
                <!-- Pipeline job list -->
                @if(session('success'))
                    <div style="background: #d4edda; color: #155724; padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 13px;">✓ {{ session('success') }}</div>
                @endif

                @if($jobs->isEmpty())
                    <div style="text-align: center; padding: 60px 20px; color: #5f6368;">
                        <div class="empty-icon">
                            <svg width="48" height="48" viewBox="0 0 48 48" fill="none">
                                <path d="M6 18l8-12h20l8 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M6 18v18a2 2 0 002 2h32a2 2 0 002-2V18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M6 18h12l2 4h8l2-4h12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <div class="empty-title">No {{ $pipelineFilter !== 'all' ? $pipelineFilter : '' }} jobs</div>
                        <div style="font-size: 14px;">{{ __('ui.no_jobs_hint') }}</div>
                    </div>
                @else
                    <table class="ku-table" id="job-list">
                        <tbody>
                            @foreach($jobs as $job)
                            @php
                                $clustering = $job->step_outputs_json['clustering'] ?? null;
                                $nClusters = $clustering['n_clusters'] ?? null;
                                $nNoise = $clustering['n_noise'] ?? null;
                                $silhouette = $clustering['silhouette_score'] ?? null;
                            @endphp
                            <tr>
                                <td style="max-width: 0; width: 100%;">
                                    <div style="font-size: 14px; font-weight: 500;">{{ $job->dataset->name ?? 'Unknown Dataset' }}</div>
                                    <div style="font-size: 13px; color: #5f6368; margin-top: 2px;">
                                        @if($nClusters !== null)
                                            {{ $nClusters }} clusters
                                            @if($silhouette !== null) · silhouette {{ number_format($silhouette, 3) }} @endif
                                        @else
                                            {{ $job->status }}
                                            @if($job->progress > 0 && $job->progress < 100) · {{ $job->progress }}% @endif
                                        @endif
                                    </div>
                                    <div style="font-size: 12px; color: #a0a0a5; margin-top: 3px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                        @if($nNoise !== null) {{ $nNoise }} {{ __('ui.noise_points') }}
                                        @elseif($job->error_detail) {{ Str::limit($job->error_detail, 80) }}
                                        @endif
                                    </div>
                                </td>
                                <td style="white-space: nowrap; text-align: right; vertical-align: top;">
                                    <div style="font-size: 12px; color: #5f6368;"><time datetime="{{ $job->created_at->toIso8601String() }}">{{ $job->created_at->format('m/d H:i') }}</time></div>
                                    <div style="font-size: 13px; color: #5f6368; margin-top: 2px;">
                                        @if($job->progress > 0 && $job->progress < 100)
                                            <div style="width: 60px; height: 4px; background: #e5e5e7; border-radius: 2px; overflow: hidden; display: inline-block; vertical-align: middle;">
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
            @elseif($current)
                <!-- Embedding header: info + actions -->
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid #f0f0f2;">
                    <div style="flex: 1; min-width: 0;">
                        <!-- Editable name -->
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <h2 id="emb-title" style="font-size: 18px; font-weight: 600; cursor: pointer;"
                                onclick="startRename()" title="Click to rename">{{ $current->name }}</h2>
                            <button onclick="startRename()" style="background: none; border: none; cursor: pointer; color: #5f6368; font-size: 13px;" title="{{ __('ui.rename') }}">✏️</button>
                        </div>
                        <!-- Rename form (hidden) -->
                        <form id="rename-form" method="POST" action="{{ route('workspace.rename', $current->id) }}"
                              style="display: none; margin-top: 4px;">
                            @csrf @method('PUT')
                            <div style="display: flex; gap: 8px; align-items: center;">
                                <input type="text" name="name" value="{{ $current->name }}" id="rename-input"
                                       style="padding: 6px 10px; border: 1px solid #d2d2d7; border-radius: 6px; font-size: 14px; width: 300px;">
                                <button type="submit" class="btn btn-sm btn-primary">{{ __('ui.save') }}</button>
                                <button type="button" class="btn btn-sm btn-outline" onclick="cancelRename()">{{ __('ui.cancel') }}</button>
                            </div>
                        </form>

                        @php
                            $jobData = $embeddingJob?->step_outputs_json ?? [];
                            $cl = $jobData['clustering'] ?? null;
                            $ca = $jobData['cluster_analysis'] ?? null;
                            $statusCounts = $knowledgeUnits->isNotEmpty()
                                ? $knowledgeUnits->groupBy('review_status')->map->count()
                                : collect();
                        @endphp

                        <!-- Summary cards -->
                        <div style="display: flex; gap: 8px; margin-top: 12px; flex-wrap: wrap;">
                            @if($cl)
                                <div style="background: #F6F6F6; border-radius: 8px; padding: 8px 14px; text-align: center; min-width: 80px;">
                                    <div style="font-size: 20px; font-weight: 700;">{{ $cl['n_clusters'] ?? '?' }}</div>
                                    <div style="font-size: 11px; color: #5f6368;">{{ __('ui.clusters') }}</div>
                                </div>
                                @if(isset($cl['silhouette_score']))
                                @php
                                    // Text embeddings produce structurally lower silhouette scores
                                    // than numeric data due to high dimensionality and soft cluster
                                    // boundaries. Thresholds are calibrated for text clustering.
                                    $sil = $cl['silhouette_score'];
                                    if ($sil >= 0.5)       { $silBg = '#d4edda'; $silColor = '#155724'; $silLabel = __('ui.silhouette_excellent'); }
                                    elseif ($sil >= 0.3)   { $silBg = '#e8f5e9'; $silColor = '#2e7d32'; $silLabel = __('ui.silhouette_good'); }
                                    elseif ($sil >= 0.1)   { $silBg = '#e3f2fd'; $silColor = '#1565c0'; $silLabel = __('ui.silhouette_typical_text'); }
                                    elseif ($sil >= 0.0)   { $silBg = '#F6F6F6'; $silColor = '#555';    $silLabel = __('ui.silhouette_typical'); }
                                    else                   { $silBg = '#f8d7da'; $silColor = '#721c24'; $silLabel = __('ui.silhouette_poor'); }
                                @endphp
                                <div style="background: {{ $silBg }}; border-radius: 8px; padding: 8px 14px; text-align: center; min-width: 80px;"
                                     title="Text embeddings typically score 0.0–0.3 due to high dimensionality. Scores above 0.1 indicate meaningful cluster separation.">
                                    <div style="font-size: 20px; font-weight: 700; color: {{ $silColor }};">{{ number_format($sil, 3) }}</div>
                                    <div style="font-size: 11px; color: {{ $silColor }};">{{ __('ui.silhouette') }} · {{ $silLabel }}</div>
                                </div>
                                @endif
                                @if(isset($cl['n_noise']))
                                <div style="background: #F6F6F6; border-radius: 8px; padding: 8px 14px; text-align: center; min-width: 80px;">
                                    <div style="font-size: 20px; font-weight: 700;">{{ $cl['n_noise'] }}</div>
                                    <div style="font-size: 11px; color: #5f6368;">{{ __('ui.noise') }}</div>
                                </div>
                                @endif
                            @endif

                            @if($statusCounts->get('draft', 0) > 0)
                            <div style="background: #f0f0f2; border-radius: 8px; padding: 8px 14px; text-align: center; min-width: 70px;">
                                <div style="font-size: 20px; font-weight: 700; color: #5f6368;">{{ $statusCounts['draft'] }}</div>
                                <div style="font-size: 11px; color: #5f6368;">Draft</div>
                            </div>
                            @endif
                            @if($statusCounts->get('reviewed', 0) > 0)
                            <div style="background: #fff3cd; border-radius: 8px; padding: 8px 14px; text-align: center; min-width: 70px;">
                                <div style="font-size: 20px; font-weight: 700; color: #856404;">{{ $statusCounts['reviewed'] }}</div>
                                <div style="font-size: 11px; color: #856404;">Reviewed</div>
                            </div>
                            @endif
                            @if($statusCounts->get('approved', 0) > 0)
                            <div style="background: #d4edda; border-radius: 8px; padding: 8px 14px; text-align: center; min-width: 70px;">
                                <div style="font-size: 20px; font-weight: 700; color: #155724;">{{ $statusCounts['approved'] }}</div>
                                <div style="font-size: 11px; color: #155724;">Approved</div>
                            </div>
                            @endif
                            @if($statusCounts->get('rejected', 0) > 0)
                            <div style="background: #f8d7da; border-radius: 8px; padding: 8px 14px; text-align: center; min-width: 70px;">
                                <div style="font-size: 20px; font-weight: 700; color: #721c24;">{{ $statusCounts['rejected'] }}</div>
                                <div style="font-size: 11px; color: #721c24;">Rejected</div>
                            </div>
                            @endif

                        </div>

                        <!-- Detail list (contact-card style) + Chat button -->
                        <div style="margin-top: 12px; font-size: 13px; border-top: 1px solid #f0f0f2; padding-top: 10px; display: flex; justify-content: space-between; align-items: flex-start;">
                            <table style="border-collapse: collapse; width: auto;">
                                @if(isset($cl['clustering_method']))
                                <tr>
                                    <td style="padding: 3px 0; color: #5f6368; width: 100px; border: none; vertical-align: top;">{{ __('ui.method') }}</td>
                                    @php
                                        $methodNames = [
                                            'hdbscan' => 'HDBSCAN (density-based, auto)',
                                            'kmeans' => 'K-Means++ (spherical)',
                                            'agglomerative' => 'Agglomerative (hierarchical)',
                                            'leiden' => 'HNSW + Leiden (graph community)',
                                        ];
                                    @endphp
                                    <td style="padding: 3px 0; font-weight: 500; border: none;">{{ $methodNames[$cl['clustering_method']] ?? strtoupper($cl['clustering_method']) }}
                                        @if(isset($cl['clustering_params']))
                                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1px 16px; margin-top: 4px; padding-left: 8px; border-left: 2px solid #e0e0e2;">
                                            @foreach($cl['clustering_params'] as $pKey => $pVal)
                                            <div style="font-size: 11px; font-weight: 400; color: #5f6368;">
                                                {{ $pKey }}: <span style="color: #555;">{{ $pVal }}</span>
                                            </div>
                                            @endforeach
                                        </div>
                                        @endif
                                    </td>
                                </tr>
                                @endif
                                @if(isset($cl['remove_language_bias']))
                                <tr>
                                    <td style="padding: 3px 0; color: #5f6368; width: 100px; border: none;">{{ __('ui.lang_debias') }}</td>
                                    <td style="padding: 3px 0; border: none;">
                                        @if($cl['remove_language_bias'])
                                            <span style="color: #30d158;">{{ __('ui.enabled') }}</span>
                                            @if(isset($cl['language_stats']) && is_array($cl['language_stats']) && count($cl['language_stats']) > 1)
                                                <span style="font-size: 11px; color: #5f6368; margin-left: 6px;">
                                                    ({{ collect($cl['language_stats'])->map(fn($cnt, $lang) => strtoupper($lang) . ": {$cnt}")->implode(', ') }})
                                                </span>
                                            @endif
                                        @else
                                            <span style="color: #5f6368;">{{ __('ui.disabled') }}</span>
                                        @endif
                                    </td>
                                </tr>
                                @endif
                                @if($ca && isset($ca['model']))
                                <tr>
                                    <td style="padding: 3px 0; color: #5f6368; width: 100px; border: none;">{{ __('ui.llm') }}</td>
                                    <td style="padding: 3px 0; font-weight: 500; border: none;">{{ Str::afterLast(Str::beforeLast($ca['model'], ':'), '.') }}</td>
                                </tr>
                                @endif
                                <tr>
                                    <td style="padding: 3px 0; color: #5f6368; width: 100px; border: none;">{{ __('ui.created') }}</td>
                                    <td style="padding: 3px 0; border: none;"><time datetime="{{ $current->created_at->toIso8601String() }}" data-format="full">{{ $current->created_at->format('Y/m/d H:i') }}</time></td>
                                </tr>
                                <tr>
                                    <td style="padding: 3px 0; color: #5f6368; width: 100px; border: none;">{{ __('ui.rows') }}</td>
                                    <td style="padding: 3px 0; border: none;">{{ $current->row_count }}</td>
                                </tr>
                            </table>
                            <button onclick="openChatOverlay()" class="btn btn-primary"
                                style="padding: 12px 28px; font-size: 15px; border-radius: 10px; display: flex; align-items: center; gap: 8px; flex-shrink: 0; align-self: flex-start;">
                                💬 {{ __('ui.chat_with_data') }}
                            </button>
                        </div>
                    </div>

                    <!-- Action buttons -->
                    <div style="display: flex; gap: 8px; flex-shrink: 0; margin-left: 16px;">
                        <a href="{{ route('workspace.export', $current->id) }}" class="btn btn-sm btn-outline" style="display: flex; align-items: center; gap: 4px;">
                            ⬇️ {{ __('ui.export') }}
                        </a>
                        <form method="POST" action="{{ route('workspace.destroy', $current->id) }}"
                              onsubmit="return confirm('Delete &quot;{{ $current->name }}&quot; and all its KUs? This cannot be undone.')">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline" style="color: #ff3b30; border-color: #ff3b30;">
                                🗑 {{ __('ui.delete') }}
                            </button>
                        </form>
                    </div>
                </div>

                @if(session('success'))
                    <p style="color: #34c759; font-size: 13px; margin-bottom: 16px;">✓ {{ session('success') }}</p>
                @endif

                @if($knowledgeUnits->isEmpty())
                    <div class="empty">
                        <div class="empty-icon">📊</div>
                        <div class="empty-title">{{ __('ui.no_clusters_yet') }}</div>
                        <p>{{ __('ui.run_pipeline_to_generate') }}</p>
                    </div>
                @else
                    <form method="POST" action="{{ route('workspace.ku.bulk-status', $current->id) }}" id="bulk-form">
                        @csrf
                        <table class="ku-table">
                            <thead>
                                <tr>
                                    <th style="width: 36px; position: relative;">
                                        <div style="display: flex; align-items: center; gap: 2px;">
                                            <input type="checkbox" id="select-all" style="cursor: pointer;"
                                                   onchange="toggleAllCheckboxes(this)">
                                            <button type="button" id="bulk-dropdown-btn"
                                                    style="background: none; border: none; cursor: pointer; font-size: 10px; color: #5f6368; padding: 0 2px;"
                                                    onclick="toggleBulkDropdown(event)">▼</button>
                                        </div>
                                        <div id="bulk-dropdown" style="display: none; position: absolute; top: 100%; left: 0; z-index: 100;
                                            background: #fff; border: 1px solid #d2d2d7; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                                            min-width: 180px; padding: 4px 0;">
                                            <button type="submit" name="new_status" value="draft"
                                                    style="display: flex; align-items: center; gap: 8px; width: 100%; padding: 8px 14px; border: none; background: none; cursor: pointer; font-size: 13px; text-align: left;"
                                                    onmouseover="this.style.background='#f0f0f2'" onmouseout="this.style.background='none'">
                                                ✏️ {{ __('ui.set_draft') }}
                                            </button>
                                            <button type="submit" name="new_status" value="reviewed"
                                                    style="display: flex; align-items: center; gap: 8px; width: 100%; padding: 8px 14px; border: none; background: none; cursor: pointer; font-size: 13px; text-align: left;"
                                                    onmouseover="this.style.background='#f0f0f2'" onmouseout="this.style.background='none'">
                                                👁️ {{ __('ui.set_reviewed') }}
                                            </button>
                                            <button type="submit" name="new_status" value="approved"
                                                    style="display: flex; align-items: center; gap: 8px; width: 100%; padding: 8px 14px; border: none; background: none; cursor: pointer; font-size: 13px; text-align: left;"
                                                    onmouseover="this.style.background='#f0f0f2'" onmouseout="this.style.background='none'">
                                                ✅ {{ __('ui.set_approved') }}
                                            </button>
                                            <button type="submit" name="new_status" value="rejected"
                                                    style="display: flex; align-items: center; gap: 8px; width: 100%; padding: 8px 14px; border: none; background: none; cursor: pointer; font-size: 13px; text-align: left;"
                                                    onmouseover="this.style.background='#f0f0f2'" onmouseout="this.style.background='none'">
                                                ❌ {{ __('ui.set_rejected') }}
                                            </button>
                                        </div>
                                    </th>
                                    <td colspan="3"></td>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($knowledgeUnits as $ku)
                                    <tr style="cursor: pointer;"
                                        onclick="if(!event.target.closest('input[type=checkbox]'))window.location='{{ route('workspace.ku', ['embeddingId' => $current->id, 'kuId' => $ku->id]) }}'">
                                        <td onclick="event.stopPropagation();" style="vertical-align: top; padding-top: 12px;">
                                            <input type="checkbox" name="ku_ids[]" value="{{ $ku->id }}" class="ku-checkbox"
                                                   style="cursor: pointer;" onchange="updateSelectAll()">
                                        </td>
                                        <td style="max-width: 0; width: 100%;">
                                            <div style="display: flex; align-items: baseline; gap: 8px;">
                                                <span style="font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">{{ $ku->intent }}</span>
                                                @if($ku->product)
                                                    <span style="font-size: 11px; color: #0071e3; background: #e8f0fe; padding: 1px 6px; border-radius: 4px; white-space: nowrap; flex-shrink: 0;">{{ Str::limit($ku->product, 30) }}</span>
                                                @endif
                                                @if($ku->category)
                                                    <span style="font-size: 11px; color: #5f6368; background: #f0f0f2; padding: 1px 6px; border-radius: 4px; white-space: nowrap; flex-shrink: 0;">{{ Str::limit($ku->category, 20) }}</span>
                                                @endif
                                            </div>
                                            <div style="font-size: 13px; color: #5f6368; margin-top: 2px;">{{ $ku->topic }}</div>
                                            @if($ku->question)
                                                <div style="font-size: 12px; color: #1d1d1f; margin-top: 3px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">Q: {{ $ku->question }}</div>
                                            @elseif($ku->summary)
                                                <div style="font-size: 12px; color: #a0a0a5; margin-top: 3px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $ku->summary }}</div>
                                            @endif
                                        </td>
                                        <td style="text-align: right; vertical-align: top; padding-top: 10px; white-space: nowrap;">
                                            <div style="font-size: 13px; color: #5f6368;">{{ $ku->row_count }} {{ __('ui.rows') }}</div>
                                            <div style="height: 18px;"></div>
                                            <div title="{{ $ku->review_status }}" style="font-size: 14px; text-align: right;">
                                                @switch($ku->review_status)
                                                    @case('draft')    ✏️ @break
                                                    @case('reviewed') 👁️ @break
                                                    @case('approved') ✅ @break
                                                    @case('rejected') ❌ @break
                                                    @default          ❓
                                                @endswitch
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </form>
                @endif
            @else
                <div class="empty">
                    <div class="empty-icon">🗂</div>
                    <div class="empty-title">{{ __('ui.select_embedding') }}</div>
                    <p>{{ __('ui.select_embedding_hint') }}</p>
                </div>
            @endif
        </div>
    </div>

    <!-- Dispatch modal overlay (Gmail-style compose window) -->
    <div id="dispatch-modal" style="display: none; position: fixed; bottom: 0; right: 24px; z-index: 1000;
        width: 520px; max-height: 80vh; background: #fff; border-radius: 12px 12px 0 0;
        box-shadow: 0 -4px 24px rgba(0,0,0,0.2); display: none; flex-direction: column;">

        <!-- Modal header (draggable look) -->
        <div style="display: flex; align-items: center; justify-content: space-between; padding: 12px 16px;
            background: #F3F3F3; border-radius: 12px 12px 0 0; cursor: default;">
            <span style="font-size: 14px; font-weight: 600; color: #1d1d1f;">{{ __('ui.new_dataset') ?? 'New Dataset' }}</span>
            <button onclick="closeDispatchModal()" style="background: none; border: none; cursor: pointer;
                color: #5f6368; font-size: 18px; line-height: 1; padding: 0 4px;">✕</button>
        </div>

        <!-- Modal body -->
        <div style="padding: 20px; overflow-y: auto; flex: 1;">
            <!-- Upload Dataset -->
            <div style="margin-bottom: 20px;">
                <div style="font-size: 13px; font-weight: 600; color: #5f6368; margin-bottom: 8px;">UPLOAD DATASET</div>
                <form method="POST" action="{{ route('dataset.upload') }}" enctype="multipart/form-data"
                    style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                    @csrf
                    <input type="file" name="csv_file" accept=".csv,.txt" required style="font-size: 13px; flex: 1;">
                    <button type="submit" class="btn btn-primary" style="font-size: 13px;">{{ __('ui.upload_and_configure') }}</button>
                </form>
            </div>

            <p style="font-size: 12px; color: #5f6368; margin-top: 8px;">
                {{ __('ui.after_upload_hint') }}
            </p>
        </div>
    </div>

    <!-- Chat overlay (Gmail compose-style, bottom-right) -->
    @if($current)
    <div id="chat-overlay" style="display: none; position: fixed; bottom: 0; right: 24px; z-index: 1001;
        width: 480px; height: 600px; background: #fff; border-radius: 12px 12px 0 0;
        box-shadow: 0 -4px 24px rgba(0,0,0,0.2); flex-direction: column;">

        <!-- Header -->
        <div style="display: flex; align-items: center; justify-content: space-between; padding: 10px 16px;
            background: #1d1d1f; border-radius: 12px 12px 0 0; flex-shrink: 0;">
            <div>
                <div style="font-size: 14px; font-weight: 600; color: #fff;">{{ __('ui.chat') }} — {{ Str::limit($current->name, 30) }}</div>
                <div style="font-size: 11px; color: #aaa;">{{ $knowledgeUnits->where('review_status', 'approved')->count() }} approved clusters as knowledge source</div>
            </div>
            <div style="display: flex; gap: 8px;">
                <button onclick="clearChat()" style="background: none; border: none; cursor: pointer; color: #aaa; font-size: 12px;" title="Clear">Clear</button>
                <button onclick="closeChatOverlay()" style="background: none; border: none; cursor: pointer; color: #fff; font-size: 18px; line-height: 1; padding: 0 4px;">✕</button>
            </div>
        </div>

        <!-- Messages area -->
        <div id="chat-messages" style="flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 12px;">
            <div style="text-align: center; color: #5f6368; font-size: 12px; padding: 20px 0;">
                Ask a question about the data in this embedding's clusters.
            </div>
        </div>

        <!-- Input area -->
        <div style="border-top: 1px solid #e5e5e7; padding: 12px 16px; flex-shrink: 0;">
            <form onsubmit="sendChatMessage(event)" style="display: flex; gap: 8px;">
                <input type="text" id="chat-input" placeholder="Type your question..."
                    style="flex: 1; padding: 10px 14px; border: 1px solid #d2d2d7; border-radius: 20px; font-size: 14px; outline: none;"
                    autocomplete="off">
                <button type="submit" id="chat-send-btn"
                    style="background: #0071e3; color: #fff; border: none; border-radius: 20px; padding: 10px 18px; font-size: 14px; cursor: pointer; font-weight: 500;">
                    {{ __('ui.send') }}
                </button>
            </form>
        </div>
    </div>
    @endif
@endsection

@section('scripts')
        // Tree view toggle (open/close dataset nodes)
        function toggleTree(header) {
            const toggle = header.querySelector('.tree-toggle');
            const children = header.nextElementSibling;
            const isOpen = toggle.classList.contains('open');

            if (isOpen) {
                toggle.classList.remove('open');
                children.classList.add('collapsed');
            } else {
                toggle.classList.add('open');
                children.classList.remove('collapsed');
                children.style.maxHeight = children.scrollHeight + 'px';
            }
        }

        // Bulk checkbox controls
        function toggleAllCheckboxes(selectAll) {
            document.querySelectorAll('.ku-checkbox').forEach(cb => {
                cb.checked = selectAll.checked;
            });
        }

        function updateSelectAll() {
            const all = document.querySelectorAll('.ku-checkbox');
            const checked = document.querySelectorAll('.ku-checkbox:checked');
            const selectAll = document.getElementById('select-all');
            if (selectAll) {
                selectAll.checked = all.length > 0 && all.length === checked.length;
                selectAll.indeterminate = checked.length > 0 && checked.length < all.length;
            }
        }

        // Bulk action dropdown
        function toggleBulkDropdown(e) {
            e.stopPropagation();
            const dd = document.getElementById('bulk-dropdown');
            dd.style.display = dd.style.display === 'none' ? 'block' : 'none';
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function() {
            const dd = document.getElementById('bulk-dropdown');
            if (dd) dd.style.display = 'none';
        });

        // Prevent form submit if no checkboxes selected
        document.getElementById('bulk-form')?.addEventListener('submit', function(e) {
            const checked = document.querySelectorAll('.ku-checkbox:checked');
            if (checked.length === 0) {
                e.preventDefault();
                alert('Select at least one cluster.');
            }
        });

        // Rename embedding inline
        function startRename() {
            document.getElementById('emb-title').style.display = 'none';
            document.getElementById('rename-form').style.display = 'block';
            const input = document.getElementById('rename-input');
            input.focus();
            input.select();
        }
        function cancelRename() {
            document.getElementById('emb-title').style.display = '';
            document.getElementById('rename-form').style.display = 'none';
        }

        // Chat overlay
        let chatHistory = [];
        let chatSending = false;

        function openChatOverlay() {
            document.getElementById('chat-overlay').style.display = 'flex';
            document.getElementById('chat-input').focus();
        }

        function closeChatOverlay() {
            document.getElementById('chat-overlay').style.display = 'none';
        }

        function clearChat() {
            chatHistory = [];
            const container = document.getElementById('chat-messages');
            container.innerHTML = '<div style="text-align: center; color: #5f6368; font-size: 12px; padding: 20px 0;">Ask a question about the data in this embedding\'s clusters.</div>';
        }

        function appendChatMessage(role, content, meta) {
            const container = document.getElementById('chat-messages');

            // Remove placeholder if present
            const placeholder = container.querySelector('[style*="text-align: center"]');
            if (placeholder && role === 'user') placeholder.remove();

            const bubble = document.createElement('div');
            const isUser = role === 'user';

            bubble.style.cssText = `
                max-width: 85%; align-self: ${isUser ? 'flex-end' : 'flex-start'};
                background: ${isUser ? '#0071e3' : '#F6F6F6'};
                color: ${isUser ? '#fff' : '#1d1d1f'};
                padding: 10px 14px; border-radius: ${isUser ? '16px 16px 4px 16px' : '16px 16px 16px 4px'};
                font-size: 14px; line-height: 1.5; word-wrap: break-word;
            `;
            bubble.textContent = content;

            container.appendChild(bubble);

            // Show sources and meta for assistant messages
            if (!isUser && meta) {
                if (meta.sources && meta.sources.length > 0) {
                    const sourcesDiv = document.createElement('div');
                    sourcesDiv.style.cssText = 'align-self: flex-start; font-size: 11px; color: #5f6368; padding: 2px 4px;';
                    const topicList = meta.sources.map(s => s.topic + ' (' + (s.similarity * 100).toFixed(1) + '%)').join(', ');
                    sourcesDiv.textContent = 'Sources: ' + topicList;
                    container.appendChild(sourcesDiv);
                }
                if (meta.latency_ms || meta.usage) {
                    const metaDiv = document.createElement('div');
                    metaDiv.style.cssText = 'align-self: flex-start; font-size: 10px; color: #aaa; padding: 0 4px 4px;';
                    const parts = [];
                    if (meta.latency_ms) parts.push(meta.latency_ms + 'ms');
                    if (meta.usage) parts.push((meta.usage.input_tokens + meta.usage.output_tokens) + ' tokens');
                    if (meta.model) parts.push(meta.model.split('.').pop().split(':')[0]);
                    metaDiv.textContent = parts.join(' · ');
                    container.appendChild(metaDiv);
                }
            }

            container.scrollTop = container.scrollHeight;
        }

        async function sendChatMessage(e) {
            e.preventDefault();
            const input = document.getElementById('chat-input');
            const message = input.value.trim();
            if (!message || chatSending) return;

            chatSending = true;
            input.value = '';
            const sendBtn = document.getElementById('chat-send-btn');
            sendBtn.disabled = true;
            sendBtn.textContent = '...';

            // Show user message
            appendChatMessage('user', message);
            chatHistory.push({ role: 'user', content: message });

            // Show typing indicator
            const container = document.getElementById('chat-messages');
            const typing = document.createElement('div');
            typing.id = 'typing-indicator';
            typing.style.cssText = 'align-self: flex-start; background: #F6F6F6; padding: 10px 14px; border-radius: 16px 16px 16px 4px; font-size: 14px; color: #5f6368;';
            typing.textContent = 'Thinking...';
            container.appendChild(typing);
            container.scrollTop = container.scrollHeight;

            try {
                @if($current)
                const response = await fetch('{{ route("workspace.chat", $current->id) }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    },
                    body: JSON.stringify({
                        message: message,
                        history: chatHistory.slice(0, -1), // Exclude current message (server adds it)
                    }),
                });

                const data = await response.json();

                // Remove typing indicator
                document.getElementById('typing-indicator')?.remove();

                if (data.error) {
                    appendChatMessage('assistant', 'Error: ' + data.error);
                } else {
                    appendChatMessage('assistant', data.message, {
                        sources: data.sources,
                        latency_ms: data.latency_ms,
                        usage: data.usage,
                        model: data.model,
                    });
                    chatHistory.push({ role: 'assistant', content: data.message });
                }
                @endif
            } catch (err) {
                document.getElementById('typing-indicator')?.remove();
                appendChatMessage('assistant', 'Network error: ' + err.message);
            }

            chatSending = false;
            sendBtn.disabled = false;
            sendBtn.textContent = 'Send';
            input.focus();
        }

        // Dispatch modal open/close
        function openDispatchModal() {
            document.getElementById('dispatch-modal').style.display = 'flex';
        }
        function closeDispatchModal() {
            document.getElementById('dispatch-modal').style.display = 'none';
        }

        // Close modal on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeDispatchModal();
        });

        // (Pipeline settings moved to dataset/configure page)

        // Auto-open modal if redirected with success/error from dispatch
        @if(session('success') && !$current && !$pipelineView)
            openDispatchModal();
        @endif

        // Auto-refresh pipeline job list every 5 seconds
        @if($pipelineView === 'jobs')
        async function refreshJobList() {
            try {
                const response = await fetch(window.location.href);
                if (!response.ok) return;
                const html = await response.text();
                const doc = new DOMParser().parseFromString(html, 'text/html');
                // Refresh job list
                const freshJobs = doc.getElementById('job-list');
                const currentJobs = document.getElementById('job-list');
                if (freshJobs && currentJobs) currentJobs.innerHTML = freshJobs.innerHTML;
                // Refresh sidebar counts
                const freshSidebar = doc.getElementById('pipeline-sidebar');
                const currentSidebar = document.getElementById('pipeline-sidebar');
                if (freshSidebar && currentSidebar) currentSidebar.innerHTML = freshSidebar.innerHTML;
                localizeTimestamps();
            } catch (e) { }
        }
        setInterval(refreshJobList, 5000);
        @endif

        // Show cleanup confirmation after last dataset is deleted
        @if(session('confirm_cleanup'))
        if (confirm('All datasets have been deleted. There are {{ session('confirm_cleanup') }} failed/pending job(s) remaining.\n\nWould you like to clean these up as well?')) {
            fetch('{{ route('workspace.cleanup-jobs') }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json',
                },
            }).then(() => window.location.reload());
        }
        @endif
@endsection
