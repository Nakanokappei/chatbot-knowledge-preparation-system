{{-- Workspace main page: primary interface for managing datasets, embeddings, and KUs.
     Features a tree sidebar (datasets > embeddings > pipeline jobs), a main content area
     showing KU tables with bulk status actions, inline embedding rename, summary stats,
     a chat overlay for RAG queries, and a dispatch modal for CSV upload. --}}
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
        .sidebar.collapsed .tree-dataset-subtitle,
        .sidebar.collapsed .tree-emb-label,
        .sidebar.collapsed .tree-children,
        .sidebar.collapsed .tree-create-link,
        .sidebar.collapsed .tree-dataset-menu { display: none; }
        .sidebar.collapsed .tree-dataset-link { pointer-events: none; }
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
        .tree-dataset-header { display: flex; align-items: center; gap: 6px; padding: 8px 8px 8px 12px; border-radius: 0 100px 100px 0; cursor: pointer; transition: background 0.15s; user-select: none; margin-right: 8px; position: relative; }
        .tree-dataset-header:hover { background: #E9E9E9; }
        .tree-toggle { font-size: 9px; color: #5f6368; width: 12px; text-align: center; flex-shrink: 0; transition: transform 0.15s; cursor: pointer; }
        .tree-toggle.open { transform: rotate(90deg); }
        .tree-icon { flex-shrink: 0; color: #5f6368; }
        .tree-dataset-name { font-size: 15px; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex: 1; }
        .tree-dataset-count { font-size: 12px; color: #5f6368; flex-shrink: 0; }
        .tree-dataset-menu { visibility: hidden; flex-shrink: 0; width: 24px; height: 24px; border-radius: 50%; border: none; background: transparent; cursor: pointer; font-size: 14px; color: #5f6368; line-height: 24px; text-align: center; padding: 0; text-decoration: none; display: flex; align-items: center; justify-content: center; }
        .tree-dataset-header:hover .tree-dataset-menu { visibility: visible; }
        .tree-dataset-header:hover .tree-dataset-count { visibility: hidden; width: 0; overflow: hidden; }
        .tree-dataset-menu:hover { background: #dadce0; color: #202124; }

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
        /* Clickable parent header link (embedding name) — no underline, inherit color */
        .tree-dataset-link { text-decoration: none; color: inherit; display: flex; align-items: center; gap: 6px; flex: 1; min-width: 0; overflow: hidden; }
        .tree-dataset-link:hover { color: inherit; }

        /* Dataset subtitle (shown under embedding name in sidebar) */
        .tree-dataset-subtitle { font-size: 11px; color: #888; font-weight: 400; line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        /* Active parent header when comparison view is shown */
        .tree-dataset-header.active { background: #DBDBDB; }
        .tree-dataset-header.active .tree-dataset-name { font-weight: 600; }

        /* Comparison table: highlight the best run (highest silhouette) */
        .compare-table tr.best-run td { background: #e8f5e9; }
        .compare-table tr:hover td { background: #f0f7ff !important; }
        .compare-table tr.best-run:hover td { background: #d0ebd4 !important; }

        /* Spin animation for deletion spinner */
        @keyframes spin { to { transform: rotate(360deg); } }

        .empty-icon { margin-bottom: 12px; color: #5f6368; }
        .empty-title { font-size: 18px; font-weight: 600; color: #1d1d1f; margin-bottom: 8px; }
@endsection

@section('body')
    <div class="layout">
        {{-- Tree sidebar: hierarchical navigation of datasets and their embedding runs --}}
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
            <div class="sidebar-tree" id="dataset-tree">
                {{-- Embedding-first tree: each embedding is a parent node,
                     completed pipeline jobs (clustering runs) are children.
                     This lets users compare different clustering approaches
                     on the same embedded dataset. --}}
                @forelse($sidebarEmbeddings as $emb)
                    @php
                        // Determine whether this embedding is currently selected
                        $isCurrentEmb = $current && $current->id === $emb->id;
                        $completedJobs = $emb->pipelineJobs;
                        // Expand if this embedding is active or it's the first one
                        $isExpanded = $isCurrentEmb || $loop->first;
                    @endphp
                    <div class="tree-dataset">
                        {{-- Parent header: embedding name with dataset subtitle.
                             Click navigates to comparison view; chevron toggles expansion. --}}
                        <div class="tree-dataset-header {{ $isCurrentEmb && ($compareMode ?? false) ? 'active' : '' }}">
                            <span class="tree-toggle {{ $isExpanded ? 'open' : '' }}"
                                  onclick="event.stopPropagation(); event.preventDefault(); toggleTree(this.closest('.tree-dataset-header'));">&#9654;</span>
                            <a href="{{ route('workspace.embedding', ['embeddingId' => $emb->id]) }}?compare=1"
                               class="tree-dataset-link" onclick="event.stopPropagation();">
                                <svg class="tree-icon" width="16" height="16" viewBox="0 0 16 16" fill="none">
                                    <circle cx="4" cy="4" r="1.5" stroke="currentColor" stroke-width="1.2"/>
                                    <circle cx="12" cy="8" r="1.5" stroke="currentColor" stroke-width="1.2"/>
                                    <circle cx="4" cy="12" r="1.5" stroke="currentColor" stroke-width="1.2"/>
                                    <line x1="5.5" y1="4.5" x2="10.5" y2="7.5" stroke="currentColor" stroke-width="0.9"/>
                                    <line x1="5.5" y1="11.5" x2="10.5" y2="8.5" stroke="currentColor" stroke-width="0.9"/>
                                </svg>
                                <span class="tree-dataset-name">{{ $emb->dataset?->name ?? $emb->name }}</span>
                            </a>
                            <span class="tree-dataset-count">{{ $completedJobs->count() }}</span>
                        </div>

                        {{-- Children: completed pipeline jobs as clustering run entries.
                             Each shows method, key param, cluster count, and silhouette. --}}
                        <div class="tree-children {{ !$isExpanded ? 'collapsed' : '' }}"
                             style="max-height: {{ $isExpanded ? ($completedJobs->count() * 40 + 40) . 'px' : '0' }};">
                            @forelse($completedJobs as $job)
                                @php
                                    $cl = $job->step_outputs_json['clustering'] ?? [];
                                    $method = strtoupper($cl['clustering_method'] ?? '?');
                                    $nCl = $cl['n_clusters'] ?? '?';
                                    $sil = isset($cl['silhouette_score']) ? number_format($cl['silhouette_score'], 3) : '—';
                                    // Extract the most distinctive parameter for tooltip
                                    $params = $cl['clustering_params'] ?? [];
                                    $keyParam = match($cl['clustering_method'] ?? '') {
                                        'leiden' => isset($params['resolution']) ? "res={$params['resolution']}" : '',
                                        'hdbscan' => isset($params['min_cluster_size']) ? "min={$params['min_cluster_size']}" : '',
                                        'kmeans' => isset($params['n_clusters']) ? "k={$params['n_clusters']}" : '',
                                        'agglomerative' => isset($params['n_clusters']) ? "n={$params['n_clusters']}" : '',
                                        default => '',
                                    };
                                    // Display name: creation timestamp (YYYYMMDD-HHMM)
                                    $jobDisplayName = $job->created_at->format('Ymd-Hi');
                                    $isActiveJob = $isCurrentEmb && isset($currentJobId) && $currentJobId == $job->id;
                                @endphp
                                <a href="{{ route('workspace.embedding', ['embeddingId' => $emb->id]) }}?job={{ $job->id }}"
                                   class="tree-emb {{ $isActiveJob ? 'active' : '' }}"
                                   title="{{ $method }} {{ $keyParam }} — {{ $nCl }} clusters, silhouette {{ $sil }}">
                                    <svg class="tree-emb-icon" width="14" height="14" viewBox="0 0 14 14" fill="none">
                                        <circle cx="5" cy="4" r="2" stroke="currentColor" stroke-width="1"/>
                                        <circle cx="10" cy="6" r="1.5" stroke="currentColor" stroke-width="1"/>
                                        <circle cx="5" cy="10" r="2" stroke="currentColor" stroke-width="1"/>
                                    </svg>
                                    <span class="tree-emb-label">{{ $jobDisplayName }}</span>
                                    <span class="tree-emb-count">{{ $nCl }}</span>
                                </a>
                            @empty
                                <div style="padding: 4px 32px; font-size: 11px; color: #aaa;">{{ __('ui.no_clustering_runs') }}</div>
                            @endforelse
                        </div>
                    </div>
                @empty
                    @if(($pendingDatasets ?? collect())->isEmpty())
                    <div class="no-datasets-msg" style="padding: 24px; text-align: center; color: #5f6368; font-size: 13px;">
                        {{ __('ui.no_datasets') }}
                    </div>
                    @endif
                @endforelse

                {{-- Datasets with data but no embeddings yet — show as actionable
                     sidebar entries so users can navigate to configure and run the pipeline. --}}
                @foreach(($pendingDatasets ?? collect()) as $ds)
                    @php $hasStoredCsv = !empty($ds->schema_json['stored_path']); @endphp
                    <div class="tree-dataset" id="pending-ds-{{ $ds->id }}">
                        <div class="tree-dataset-header">
                            {{-- Folder icon: replaced by spinner during deletion --}}
                            <svg class="tree-icon pending-ds-icon" width="16" height="16" viewBox="0 0 16 16" fill="none">
                                <path d="M2 3.5A1.5 1.5 0 013.5 2h3.172a1.5 1.5 0 011.06.44l.828.827a1.5 1.5 0 001.06.44H12.5A1.5 1.5 0 0114 5.207V12.5a1.5 1.5 0 01-1.5 1.5h-9A1.5 1.5 0 012 12.5V3.5z" stroke="currentColor" stroke-width="1.2"/>
                            </svg>
                            {{-- Deleting spinner: hidden by default, shown during deletion --}}
                            <svg class="tree-icon pending-ds-spinner" width="16" height="16" viewBox="0 0 16 16" fill="none" style="display:none; animation: spin 1s linear infinite;">
                                <circle cx="8" cy="8" r="6" stroke="#ccc" stroke-width="2" fill="none"/>
                                <path d="M8 2a6 6 0 014.24 1.76" stroke="#ff3b30" stroke-width="2" stroke-linecap="round" fill="none"/>
                            </svg>
                            @if($hasStoredCsv)
                            <a href="{{ route('dataset.configure', $ds) }}" class="tree-dataset-link pending-ds-link" style="flex: 1; min-width: 0;">
                                <span class="tree-dataset-name" style="display: flex; flex-direction: column; gap: 0;">
                                    {{ $ds->name }}
                                    <span class="tree-dataset-subtitle pending-ds-subtitle">{{ $ds->row_count }} {{ __('ui.rows') }} — {{ __('ui.ready_to_configure') }}</span>
                                </span>
                            </a>
                            @else
                            <span class="tree-dataset-name" style="display: flex; flex-direction: column; gap: 0; flex: 1; min-width: 0;">
                                {{ $ds->name }}
                                <span class="tree-dataset-subtitle pending-ds-subtitle">{{ $ds->row_count }} {{ __('ui.rows') }}</span>
                            </span>
                            @endif
                            <form method="POST" action="{{ route('dataset.destroy', $ds) }}" style="flex-shrink: 0;"
                                onsubmit="event.stopPropagation(); if(!confirm('{{ __('ui.confirm_delete_dataset') }}')) return false; startDatasetDeletion({{ $ds->id }}); return true;">
                                @csrf @method('DELETE')
                                <button type="submit" onclick="event.preventDefault(); event.stopPropagation(); this.closest('form').requestSubmit();"
                                    class="tree-dataset-menu pending-ds-delete-btn" style="visibility: visible; color: #ff3b30; font-size: 12px; width: auto; padding: 2px 6px;"
                                    title="{{ __('ui.delete') }}">✕</button>
                            </form>
                        </div>
                    </div>
                @endforeach

                {{-- Pipeline section in sidebar: job status filter links with counts --}}
                <div id="pipeline-sidebar" data-processing-count="{{ $jobStats['processing'] }}" style="margin-top: 8px; border-top: 1px solid #e0e0e2; padding-top: 8px;">
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

        {{-- Main content area: pipeline job list, embedding detail with KU table, or empty state --}}
        <div class="main">
            @if($pipelineView === 'jobs')
                {{-- Pipeline job list: filterable table of pipeline execution jobs --}}
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
                        @php
                            $noJobsKey = match($pipelineFilter) {
                                'processing' => 'no_jobs_processing',
                                'failed'     => 'no_jobs_failed',
                                'completed'  => 'no_jobs_completed',
                                default      => 'no_jobs',
                            };
                        @endphp
                        <div class="empty-title">{{ __('ui.' . $noJobsKey) }}</div>
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
                                            @php
                                                $jStepLabels = [
                                                    'submitted' => __('ui.step_submitted'),
                                                    'queued' => __('ui.step_queued'),
                                                    'preprocess' => __('ui.step_preprocess'),
                                                    'embedding' => __('ui.step_embedding'),
                                                    'clustering' => __('ui.step_clustering'),
                                                    'cluster_analysis' => __('ui.step_cluster_analysis'),
                                                    'knowledge_unit_generation' => __('ui.step_ku_generation'),
                                                    'parameter_search' => __('ui.step_parameter_search'),
                                                ];
                                            @endphp
                                            {{ $jStepLabels[$job->status] ?? $job->status }}
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
                                        @elseif($job->status === 'cancelled')
                                            <span style="color: #8e8e93;">⊘</span>
                                        @else
                                            @php
                                                // Detect stuck jobs: submitted with no progress for over 3 minutes
                                                $isStuck = $job->status === 'submitted'
                                                    && $job->progress == 0
                                                    && $job->created_at->diffInMinutes(now()) >= 3;
                                            @endphp
                                            <span style="display: inline-flex; align-items: center; gap: 6px;">
                                                @if($isStuck)
                                                    {{-- Stuck indicator: warning icon + retry button --}}
                                                    <span style="color: #ff9500;" title="{{ __('ui.job_stuck_hint') }}">⚠️</span>
                                                    <form method="POST" action="{{ route('dashboard.retry-job', $job) }}" style="display: inline;">
                                                        @csrf
                                                        <button type="submit" style="background: #0071e3; border: none; border-radius: 4px; padding: 2px 8px; font-size: 11px; color: #fff; cursor: pointer;">{{ __('ui.retry') }}</button>
                                                    </form>
                                                @else
                                                    <span style="display: inline-block; animation: spin 2s linear infinite;">⏳</span>
                                                @endif
                                                <form method="POST" action="{{ route('dashboard.cancel-pipeline', $job) }}"
                                                      onsubmit="return confirm('{{ __('ui.confirm_cancel_job') }}')" style="display: inline;">
                                                    @csrf
                                                    <button type="submit" style="background: #fff; border: 1px solid #ff3b30; border-radius: 4px; padding: 2px 8px; font-size: 11px; color: #ff3b30; cursor: pointer;">{{ __('ui.cancel') }}</button>
                                                </form>
                                            </span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            @elseif(($compareMode ?? false) && $current)
                {{-- Clustering comparison view: table comparing all clustering runs
                     for the selected embedding, sorted by silhouette score descending.
                     Helps users identify optimal clustering parameters. --}}
                @if(session('success'))
                    <div style="background: #d4edda; color: #155724; padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 13px;">✓ {{ session('success') }}</div>
                @endif

                <div style="margin-bottom: 24px;">
                    {{-- Editable dataset name (click to rename, same pattern as embedding rename) --}}
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <h2 id="ds-title" style="font-size: 20px; font-weight: 600; margin-bottom: 0; cursor: pointer;"
                            onclick="startDsRename()" title="{{ __('ui.click_to_rename') ?? 'Click to rename' }}">
                            @if($current->dataset) {{ $current->dataset->name }} @else {{ $current->name }} @endif
                        </h2>
                        <button onclick="startDsRename()" style="background: none; border: none; cursor: pointer; color: #5f6368; padding: 2px;" title="{{ __('ui.rename') }}">
                            <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M11.5 1.5l3 3L5 14H2v-3L11.5 1.5z"/>
                            </svg>
                        </button>
                    </div>
                    @if($current->dataset)
                    <form id="ds-rename-form" method="POST" action="{{ route('dataset.rename', $current->dataset) }}"
                          style="display: none; margin-top: 4px;">
                        @csrf @method('PUT')
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <input type="text" name="name" value="{{ $current->dataset->name }}" id="ds-rename-input"
                                   style="padding: 6px 10px; border: 1px solid #d2d2d7; border-radius: 6px; font-size: 14px; width: 250px;">
                            <button type="submit" class="btn btn-sm btn-primary" style="white-space: nowrap;">{{ __('ui.save') }}</button>
                            <button type="button" class="btn btn-sm btn-outline" onclick="cancelDsRename()" style="white-space: nowrap;">{{ __('ui.cancel') }}</button>
                        </div>
                    </form>
                    @endif
                    <div style="font-size: 13px; color: #5f6368; margin-top: 4px;">{{ $clusteringRuns->count() }} {{ __('ui.clustering_runs') }}</div>
                    @if($current->embedding_model)
                        <div style="font-size: 12px; color: #888; margin-top: 2px;">{{ __('ui.embedding_model_used') }}: {{ $current->embedding_model }}</div>
                    @endif
                </div>

                {{-- Action buttons: new clustering run + parameter search + delete dataset.
                     While a parameter search is running for this embedding, both the
                     recluster and parameter-search buttons are disabled by JS
                     (setParamSearchRunningState) to prevent concurrent conflicting
                     dispatches. The disabled state is driven by pollParameterSearch's
                     status updates, not by server-rendered markup, so the UI toggles
                     back on the moment the sweep finishes. --}}
                <div style="display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap;">
                    <button type="button" onclick="toggleReclusterForm()" id="recluster-toggle"
                        style="background: none; border: 1px solid #d2d2d7; border-radius: 8px; padding: 8px 16px; font-size: 13px; cursor: pointer; color: #0071e3; display: flex; align-items: center; gap: 6px;">
                        <span id="recluster-chevron" style="transition: transform 0.15s;">▸</span>
                        {{ __('ui.new_clustering_run') }}
                    </button>
                    <form method="POST" action="{{ route('workspace.parameter-search', $current->id) }}" style="display: inline;" id="param-search-form">
                        @csrf
                        <button type="submit" id="param-search-btn"
                            style="background: #0071e3; color: #fff; border: none; border-radius: 8px; padding: 8px 16px; font-size: 13px; cursor: pointer; display: flex; align-items: center; gap: 6px;"
                            onclick="this.disabled=true; this.style.opacity='0.6'; this.innerHTML='<span style=\'display:inline-block;animation:spin 1s linear infinite\'>🔍</span> {{ __("ui.parameter_search_running") }}'; this.form.submit();">
                            🔍 {{ __('ui.parameter_search') }}
                        </button>
                    </form>
                    {{-- Delete the parent dataset. Only enabled when the dataset
                         has zero Knowledge Units — deleting a dataset that has
                         produced reviewed KUs would silently destroy the final
                         product. Server-side guard in DatasetWizardController
                         enforces the same constraint. --}}
                    @if($current->dataset_id)
                        <form method="POST" action="{{ route('dataset.destroy', $current->dataset_id) }}" style="display: inline;"
                              onsubmit="return confirm('{{ __('ui.confirm_delete_dataset') }}')">
                            @csrf @method('DELETE')
                            <button type="submit"
                                @if($currentDatasetHasKus ?? false) disabled @endif
                                title="@if($currentDatasetHasKus ?? false){{ __('ui.cannot_delete_dataset_with_kus') ?? 'Cannot delete: this dataset still has knowledge units.' }}@else{{ __('ui.delete_dataset') ?? 'Delete this dataset' }}@endif"
                                style="background: none; border: 1px solid #ff3b30; border-radius: 8px; padding: 8px 16px; font-size: 13px; display: flex; align-items: center; gap: 6px;
                                       {{ ($currentDatasetHasKus ?? false) ? 'color: #bbb; border-color: #e5e5e7; cursor: not-allowed;' : 'color: #ff3b30; cursor: pointer;' }}">
                                🗑 {{ __('ui.delete_dataset') ?? 'Delete dataset' }}
                            </button>
                        </form>
                    @endif
                </div>

                {{-- Parameter search results: collapsible panel with chart + top results.
                     Default collapsed when results exist; expanded while polling. --}}
                <div id="param-search-results" style="display: none; margin-bottom: 20px;">
                    {{-- Collapsible header: toggle + title + status + pdf + dismiss buttons --}}
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                        <button type="button" onclick="toggleParamResults()" id="param-results-toggle"
                            style="background: none; border: 1px solid #d2d2d7; border-radius: 8px; padding: 6px 14px; font-size: 13px; cursor: pointer; color: #5f6368; display: flex; align-items: center; gap: 6px;">
                            <span id="param-results-chevron" style="transition: transform 0.15s;">▸</span>
                            {{ __('ui.parameter_search_results') }}
                        </button>
                        <span id="param-search-status" style="font-size: 12px; color: #5f6368;"></span>
                        {{-- Export the current chart + top-5 table as a PDF
                             report (client-side via html2canvas + jsPDF). Only
                             shown once results are populated — dispatched by
                             renderParamSearchChart() at render time. --}}
                        <button type="button" id="param-search-pdf" onclick="exportParamSearchPdf()"
                            style="display:none; margin-left: auto; background: none; border: 1px solid #0071e3; border-radius: 6px; color: #0071e3; font-size: 12px; cursor: pointer; padding: 4px 10px;">
                            {{ __('ui.parameter_search_pdf_export') ?? '📄 PDF' }}
                        </button>
                        <button type="button" id="param-search-dismiss" onclick="dismissParamResults()"
                            style="display:none; background: none; border: 1px solid #ff3b30; border-radius: 6px; color: #ff3b30; font-size: 12px; cursor: pointer; padding: 4px 8px;">
                            ✕ {{ __('ui.dismiss') ?? '消去' }}
                        </button>
                    </div>
                    {{-- Collapsible body --}}
                    <div id="param-search-body" style="display: none; padding: 16px; background: #fafafa; border: 1px solid #e5e5e7; border-radius: 10px;">
                        {{-- Dual-axis chart: bars (silhouette) + line (cluster count).
                             This is a true dual-axis plot: both metrics are
                             Y-axes. Silhouette title sits on the left edge
                             (vertically centred, rotated bottom-up); cluster-
                             count title sits on the right edge (vertically
                             centred, rotated top-down).
                             Wrapper id is used by the PDF exporter to grab
                             both titles, chart, and x-axis labels together. --}}
                        <div id="param-search-chart-wrap" style="display: flex; align-items: stretch; gap: 4px;">
                            {{-- Left Y-axis title (silhouette). Plain
                                 vertical-rl writing mode keeps each character
                                 upright and the text reading top→bottom (the
                                 previous transform: rotate(180deg) flipped
                                 individual glyphs upside-down). Width is
                                 content-sized so Japanese labels can breathe. --}}
                            <div style="display: flex; align-items: center; justify-content: center; padding: 0 2px; font-size: 10px; color: #888; flex-shrink: 0;">
                                <span style="writing-mode: vertical-rl; white-space: nowrap;">{{ __('ui.silhouette') }}</span>
                            </div>
                            <div style="flex: 1;">
                                <div style="display: flex; gap: 0;">
                                    <div id="param-search-yaxis" style="display: flex; flex-direction: column; justify-content: space-between; align-items: flex-end; padding-right: 6px; width: 40px; height: 160px; font-size: 10px; color: #888;"></div>
                                    <div style="flex: 1; position: relative;">
                                        {{-- Gridlines container (absolute positioned behind bars) --}}
                                        <div id="param-search-gridlines" style="position: absolute; inset: 0; pointer-events: none;"></div>
                                        <div id="param-search-chart" style="display: flex; align-items: flex-end; gap: 2px; height: 160px; position: relative; z-index: 1;"></div>
                                        {{-- SVG overlay for cluster count line (second axis) --}}
                                        <svg id="param-search-line" style="position: absolute; inset: 0; pointer-events: none; z-index: 2; overflow: visible;"></svg>
                                    </div>
                                    <div id="param-search-yaxis2" style="display: flex; flex-direction: column; justify-content: space-between; align-items: flex-start; padding-left: 6px; width: 40px; height: 160px; font-size: 10px; color: #333;"></div>
                                </div>
                                {{-- X-axis: rank numbers (1..N) aligned with the bar flex layout.
                                     Same left/right 40px spacers so the labels sit under the bars.
                                     Populated by renderParamChart() so the label count matches
                                     the number of sweep results. --}}
                                <div style="display: flex; gap: 0; margin-top: 2px;">
                                    <div style="width: 40px;"></div>
                                    <div id="param-search-xaxis" style="flex: 1; display: flex; gap: 2px; font-size: 9px; color: #888;"></div>
                                    <div style="width: 40px;"></div>
                                </div>
                            </div>
                            {{-- Right Y-axis title (clusters). Rotated top-down
                                 so it reads the same way as the left title when
                                 you tilt your head to the right. Width auto so
                                 the text can breathe — there's plenty of chart
                                 width to spare. --}}
                            <div style="display: flex; align-items: center; justify-content: center; padding: 0 2px; font-size: 10px; color: #333; flex-shrink: 0;">
                                <span style="writing-mode: vertical-rl; white-space: nowrap;">{{ __('ui.clusters') }}</span>
                            </div>
                        </div>
                        <div id="param-search-legend" style="display: flex; gap: 16px; margin-top: 6px; font-size: 11px; color: #5f6368;"></div>
                        {{-- Top results table --}}
                        <div id="param-search-top" style="margin-top: 12px;"></div>
                    </div>
                </div>

                <div style="margin-bottom: 16px;">
                    <form method="POST" action="{{ route('workspace.recluster', $current->id) }}" id="recluster-form"
                          style="display: none; margin-top: 8px; padding: 14px 16px; background: #fafafa; border: 1px solid #e5e5e7; border-radius: 10px;">
                        @csrf
                        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                            <select name="clustering_method" id="rc-method" onchange="toggleRcParams()"
                                style="padding: 6px 10px; border: 1px solid #d2d2d7; border-radius: 6px; font-size: 13px;">
                                <option value="leiden" selected>HNSW + Leiden</option>
                                <option value="hdbscan">HDBSCAN</option>
                                <option value="kmeans">K-Means++</option>
                                <option value="agglomerative">Agglomerative</option>
                            </select>
                            <span id="rc-params-leiden">
                                <label style="font-size:12px;" title="{{ __('ui.tip_leiden_n_neighbors') }}">n_neighbors</label>
                                <input type="number" name="leiden_n_neighbors" value="15" min="5" max="100" style="width:60px;padding:4px 6px;border:1px solid #d2d2d7;border-radius:4px;font-size:12px;" title="{{ __('ui.tip_leiden_n_neighbors') }}">
                                <label style="font-size:12px;margin-left:4px;" title="{{ __('ui.tip_leiden_resolution') }}">resolution</label>
                                <input type="number" name="leiden_resolution" value="1.0" min="0.1" max="10.0" step="0.1" style="width:60px;padding:4px 6px;border:1px solid #d2d2d7;border-radius:4px;font-size:12px;" title="{{ __('ui.tip_leiden_resolution') }}">
                            </span>
                            <span id="rc-params-hdbscan" style="display:none;">
                                <label style="font-size:12px;" title="{{ __('ui.tip_hdbscan_min_cluster_size') }}">min_cluster_size</label>
                                <input type="number" name="hdbscan_min_cluster_size" value="15" min="2" max="500" style="width:60px;padding:4px 6px;border:1px solid #d2d2d7;border-radius:4px;font-size:12px;" title="{{ __('ui.tip_hdbscan_min_cluster_size') }}">
                                <label style="font-size:12px;margin-left:4px;" title="{{ __('ui.tip_hdbscan_min_samples') }}">min_samples</label>
                                <input type="number" name="hdbscan_min_samples" value="5" min="1" max="100" style="width:50px;padding:4px 6px;border:1px solid #d2d2d7;border-radius:4px;font-size:12px;" title="{{ __('ui.tip_hdbscan_min_samples') }}">
                            </span>
                            <span id="rc-params-kmeans" style="display:none;">
                                <label style="font-size:12px;" title="{{ __('ui.tip_kmeans_n_clusters') }}">n_clusters</label>
                                <input type="number" name="kmeans_n_clusters" value="10" min="2" max="200" style="width:60px;padding:4px 6px;border:1px solid #d2d2d7;border-radius:4px;font-size:12px;" title="{{ __('ui.tip_kmeans_n_clusters') }}">
                            </span>
                            <span id="rc-params-agglomerative" style="display:none;">
                                <label style="font-size:12px;" title="{{ __('ui.tip_agglomerative_n_clusters') }}">n_clusters</label>
                                <input type="number" name="agglomerative_n_clusters" value="10" min="2" max="200" style="width:60px;padding:4px 6px;border:1px solid #d2d2d7;border-radius:4px;font-size:12px;" title="{{ __('ui.tip_agglomerative_n_clusters') }}">
                                <label style="font-size:12px;margin-left:4px;" title="{{ __('ui.tip_agglomerative_linkage') }}">linkage</label>
                                <select name="agglomerative_linkage" style="padding:4px 6px;border:1px solid #d2d2d7;border-radius:4px;font-size:12px;" title="{{ __('ui.tip_agglomerative_linkage') }}">
                                    <option value="ward">ward</option><option value="complete">complete</option>
                                    <option value="average">average</option><option value="single">single</option>
                                </select>
                            </span>
                            <label style="font-size:12px;display:flex;align-items:center;gap:4px;margin-left:8px;">
                                <input type="checkbox" name="remove_language_bias" value="1" checked style="accent-color:#0071e3;">
                                {{ __('ui.remove_language_bias') }}
                            </label>
                            <button type="submit" class="btn btn-sm btn-primary" style="padding: 6px 16px; font-size: 13px; white-space: nowrap;">
                                {{ __('ui.run_clustering') }}
                            </button>
                        </div>
                    </form>
                </div>

                @if($clusteringRuns->isEmpty())
                    <div style="text-align: center; padding: 60px 20px; color: #5f6368;">
                        <div class="empty-icon">
                            <svg width="48" height="48" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="6" y="30" width="8" height="12" rx="1"/><rect x="20" y="18" width="8" height="24" rx="1"/><rect x="34" y="6" width="8" height="36" rx="1"/>
                            </svg>
                        </div>
                        <div class="empty-title">{{ __('ui.no_clustering_runs') }}</div>
                        <p>{{ __('ui.run_pipeline_to_compare') }}</p>
                    </div>
                @else
                    @php
                        // Human-readable clustering method names
                        $methodNames = [
                            'hdbscan' => 'HDBSCAN',
                            'kmeans' => 'K-Means++',
                            'agglomerative' => 'Agglomerative',
                            'leiden' => 'Leiden (Graph)',
                        ];
                    @endphp
                    <table class="ku-table compare-table">
                        <thead>
                            <tr>
                                <th>{{ __('ui.method') }}</th>
                                <th>{{ __('ui.parameters') }}</th>
                                <th style="text-align: center;">{{ __('ui.clusters') }}</th>
                                <th style="text-align: center;">{{ __('ui.silhouette') }}</th>
                                <th style="text-align: center;">{{ __('ui.noise') }}</th>
                                <th style="text-align: right;">{{ __('ui.created') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($clusteringRuns as $run)
                                @php
                                    // Silhouette color coding (same logic as KU detail view)
                                    $sil = $run->silhouette_score;
                                    if ($sil === null)       { $silColor = '#888'; }
                                    elseif ($sil >= 0.5)     { $silColor = '#155724'; }
                                    elseif ($sil >= 0.3)     { $silColor = '#2e7d32'; }
                                    elseif ($sil >= 0.1)     { $silColor = '#1565c0'; }
                                    elseif ($sil >= 0.0)     { $silColor = '#555'; }
                                    else                     { $silColor = '#721c24'; }
                                    // Format parameter key-value pairs for display
                                    $paramParts = [];
                                    foreach ($run->clustering_params as $pk => $pv) {
                                        $paramParts[] = "{$pk}={$pv}";
                                    }
                                    $paramStr = implode(', ', $paramParts);
                                @endphp
                                <tr class="{{ $loop->first ? 'best-run' : '' }}" style="cursor: pointer;"
                                    onclick="if(!event.target.closest('form'))window.location='{{ route('workspace.embedding', ['embeddingId' => $current->id]) }}?job={{ $run->job_id }}'">
                                                                        <td style="font-weight: 600; white-space: nowrap;">
                                        {{ $methodNames[$run->clustering_method] ?? strtoupper($run->clustering_method) }}
                                    </td>
                                    <td style="font-size: 12px; color: #5f6368; max-width: 200px;">
                                        {{ $paramStr ?: '—' }}
                                    </td>
                                    <td style="text-align: center; font-weight: 600; font-size: 18px;">
                                        {{ $run->n_clusters ?? '—' }}
                                    </td>
                                    <td style="text-align: center; font-weight: 700; font-size: 18px; color: {{ $silColor }};">
                                        {{ $sil !== null ? number_format($sil, 3) : '—' }}
                                    </td>
                                    <td style="text-align: center; color: #5f6368;">
                                        {{ $run->n_noise ?? '—' }}
                                    </td>
                                    <td style="text-align: right; font-size: 12px; color: #888; white-space: nowrap;">
                                        {{ $run->created_at->format('m/d H:i') }}
                                    </td>
                                    <td style="text-align: right;" onclick="event.stopPropagation();">
                                        <form method="POST" action="{{ route('dashboard.delete-job', $run->job_id) }}"
                                              onsubmit="return confirm('{{ __('ui.confirm_delete_clustering') }}')">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline" style="font-size: 12px; white-space: nowrap; color: #ff3b30; border-color: #ff3b30;">
                                                {{ __('ui.delete') }}
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    {{-- Metric transparency note.
                         HDBSCAN / K-Means / Agglomerative rely on Euclidean
                         distance internally (shown as metric=euclidean in the
                         parameters column), but the pipeline L2-normalises
                         every row before clustering, which makes Euclidean
                         distance monotonically equivalent to cosine distance
                         on the unit hypersphere — the mathematically correct
                         choice for text embeddings. Leiden uses cosine
                         directly via its HNSW index. --}}
                    <p style="font-size: 11px; color: #8a8a8e; margin-top: 10px; line-height: 1.5;">
                        ※ 距離指標: HDBSCAN / K-Means / Agglomerative は内部的に euclidean を使用していますが、
                        クラスタリング前にベクトルを L2 正規化しているため、単位超球面上では euclidean が cosine と等価な順序関係になります（テキスト埋め込みの推奨挙動）。
                        Leiden は HNSW インデックスで cosine を直接使用。
                    </p>
                @endif

            @elseif($current)
                {{-- Embedding detail view: header with cluster name, dataset subtitle, stats --}}
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid #f0f0f2;">
                    <div style="flex: 1; min-width: 0;">
                        {{-- Show which clustering run is selected (matches sidebar child name) --}}
                        @if($embeddingJob)
                            <h2 style="font-size: 18px; font-weight: 600;">{{ $embeddingJob->created_at->format('Ymd-Hi') }}</h2>
                        @else
                            <h2 style="font-size: 18px; font-weight: 600;">{{ $current->dataset?->name ?? $current->name }}</h2>
                        @endif
                        {{-- Dataset name as subtitle + editable pen icon --}}
                        <div style="display: flex; align-items: center; gap: 6px; margin-top: 2px;">
                            <span id="emb-title" style="font-size: 13px; color: #5f6368; cursor: pointer;"
                                  onclick="startRename()" title="{{ __('ui.click_to_rename') ?? 'Click to rename' }}">{{ $current->dataset?->name ?? $current->name }}</span>
                            <button id="rename-pen" onclick="startRename()" style="background: none; border: none; cursor: pointer; color: #aaa; padding: 2px;" title="{{ __('ui.rename') }}">
                                <svg width="12" height="12" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M11.5 1.5l3 3L5 14H2v-3L11.5 1.5z"/>
                                </svg>
                            </button>
                        </div>
                        <!-- Rename form (hidden) -->
                        <form id="rename-form" method="POST" action="{{ route('workspace.rename', $current->id) }}"
                              style="display: none; margin-top: 4px;">
                            @csrf @method('PUT')
                            @if(request('job'))<input type="hidden" name="job" value="{{ request('job') }}">@endif
                            <div style="display: flex; gap: 8px; align-items: center;">
                                <input type="text" name="name" value="{{ $current->dataset?->name ?? $current->name }}" id="rename-input"
                                       style="padding: 6px 10px; border: 1px solid #d2d2d7; border-radius: 6px; font-size: 14px; width: 200px;">
                                <button type="submit" class="btn btn-sm btn-primary" style="white-space: nowrap;">{{ __('ui.save') }}</button>
                                <button type="button" class="btn btn-sm btn-outline" onclick="cancelRename()" style="white-space: nowrap;">{{ __('ui.cancel') }}</button>
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
                                <div style="background: #F6F6F6; border-radius: 8px; padding: 8px 14px; text-align: center; min-width: 70px;">
                                    <div style="font-size: 11px; color: #5f6368;">&nbsp;</div>
                                    <div style="font-size: 20px; font-weight: 700;">{{ $cl['n_clusters'] ?? '?' }}</div>
                                    <div style="font-size: 11px; color: #5f6368;">{{ __('ui.clusters') }}</div>
                                </div>
                                @if(isset($cl['silhouette_score']))
                                @php
                                    $sil = $cl['silhouette_score'];
                                    if ($sil >= 0.5)       { $silBg = '#d4edda'; $silColor = '#155724'; $silLabel = __('ui.silhouette_excellent'); }
                                    elseif ($sil >= 0.3)   { $silBg = '#e8f5e9'; $silColor = '#2e7d32'; $silLabel = __('ui.silhouette_good'); }
                                    elseif ($sil >= 0.1)   { $silBg = '#e3f2fd'; $silColor = '#1565c0'; $silLabel = __('ui.silhouette_typical_text'); }
                                    elseif ($sil >= 0.0)   { $silBg = '#F6F6F6'; $silColor = '#555';    $silLabel = __('ui.silhouette_typical'); }
                                    else                   { $silBg = '#f8d7da'; $silColor = '#721c24'; $silLabel = __('ui.silhouette_poor'); }
                                @endphp
                                <div style="background: {{ $silBg }}; border-radius: 8px; padding: 8px 14px; text-align: center; min-width: 70px;"
                                     title="{{ __('ui.silhouette_score_hint') }}">
                                    <div style="font-size: 11px; color: {{ $silColor }};">{{ $silLabel }}</div>
                                    <div style="font-size: 20px; font-weight: 700; color: {{ $silColor }};">{{ number_format($sil, 3) }}</div>
                                    <div style="font-size: 11px; color: {{ $silColor }};">{{ __('ui.silhouette') }}</div>
                                </div>
                                @endif
                                @if(isset($cl['n_noise']))
                                <div style="background: #F6F6F6; border-radius: 8px; padding: 8px 14px; text-align: center; min-width: 70px;">
                                    <div style="font-size: 11px; color: #5f6368;">&nbsp;</div>
                                    <div style="font-size: 20px; font-weight: 700;">{{ $cl['n_noise'] }}</div>
                                    <div style="font-size: 11px; color: #5f6368;">{{ __('ui.noise') }}</div>
                                </div>
                                @endif
                            @endif

                            @if($statusCounts->get('draft', 0) > 0)
                            <div style="background: #f0f0f2; border-radius: 8px; padding: 8px 14px; text-align: center; min-width: 70px;">
                                <div style="font-size: 11px; color: #5f6368;">&nbsp;</div>
                                <div style="font-size: 20px; font-weight: 700; color: #5f6368;">{{ $statusCounts['draft'] }}</div>
                                <div style="font-size: 11px; color: #5f6368;">{{ __('ui.draft') }}</div>
                            </div>
                            @endif
                            @if($statusCounts->get('reviewed', 0) > 0)
                            <div style="background: #fff3cd; border-radius: 8px; padding: 8px 14px; text-align: center; min-width: 70px;">
                                <div style="font-size: 11px; color: #856404;">&nbsp;</div>
                                <div style="font-size: 20px; font-weight: 700; color: #856404;">{{ $statusCounts['reviewed'] }}</div>
                                <div style="font-size: 11px; color: #856404;">{{ __('ui.reviewed') }}</div>
                            </div>
                            @endif
                            @if($statusCounts->get('approved', 0) > 0)
                            <div style="background: #d4edda; border-radius: 8px; padding: 8px 14px; text-align: center; min-width: 70px;">
                                <div style="font-size: 11px; color: #155724;">&nbsp;</div>
                                <div style="font-size: 20px; font-weight: 700; color: #155724;">{{ $statusCounts['approved'] }}</div>
                                <div style="font-size: 11px; color: #155724;">{{ __('ui.approved') }}</div>
                            </div>
                            @endif
                            @if($statusCounts->get('rejected', 0) > 0)
                            <div style="background: #f8d7da; border-radius: 8px; padding: 8px 14px; text-align: center; min-width: 70px;">
                                <div style="font-size: 11px; color: #721c24;">&nbsp;</div>
                                <div style="font-size: 20px; font-weight: 700; color: #721c24;">{{ $statusCounts['rejected'] }}</div>
                                <div style="font-size: 11px; color: #721c24;">{{ __('ui.rejected') }}</div>
                            </div>
                            @endif

                        </div>

                        <!-- Detail list (contact-card style) + Chat button -->
                        <div style="margin-top: 12px; font-size: 13px; border-top: 1px solid #f0f0f2; padding-top: 10px; display: flex; align-items: center; gap: 24px;">
                            <table style="border-collapse: collapse;">
                                @if(isset($cl['clustering_method']))
                                <tr>
                                    <td style="padding: 4px 24px 4px 0; color: #5f6368; white-space: nowrap; border: none; vertical-align: top;">{{ __('ui.method') }}</td>
                                    @php
                                        $methodNames = [
                                            'hdbscan' => 'HDBSCAN (density-based, auto)',
                                            'kmeans' => 'K-Means++ (spherical)',
                                            'agglomerative' => 'Agglomerative (hierarchical)',
                                            'leiden' => 'HNSW + Leiden (graph community)',
                                        ];
                                    @endphp
                                    <td style="padding: 4px 0; font-weight: 500; border: none; white-space: nowrap;">{{ $methodNames[$cl['clustering_method']] ?? strtoupper($cl['clustering_method']) }}
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
                                    <td style="padding: 4px 24px 4px 0; color: #5f6368; white-space: nowrap; border: none;">{{ __('ui.lang_debias') }}</td>
                                    <td style="padding: 4px 0; border: none;">
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
                                @if($current->embedding_model)
                                <tr>
                                    <td style="padding: 4px 24px 4px 0; color: #5f6368; white-space: nowrap; border: none;">{{ __('ui.embedding_model_used') }}</td>
                                    <td style="padding: 4px 0; font-weight: 500; border: none; white-space: nowrap;">{{ $current->embedding_model }}</td>
                                </tr>
                                @endif
                                @if($ca && isset($ca['model']))
                                <tr>
                                    <td style="padding: 4px 24px 4px 0; color: #5f6368; white-space: nowrap; border: none;">{{ __('ui.llm') }}</td>
                                    <td style="padding: 4px 0; font-weight: 500; border: none; white-space: nowrap;">{{ Str::afterLast(Str::beforeLast($ca['model'], ':'), '.') }}</td>
                                </tr>
                                @endif
                                <tr>
                                    <td style="padding: 4px 24px 4px 0; color: #5f6368; white-space: nowrap; border: none;">{{ __('ui.created') }}</td>
                                    <td style="padding: 4px 0; border: none;"><time datetime="{{ $current->created_at->toIso8601String() }}" data-format="full">{{ $current->created_at->format('Y/m/d H:i') }}</time></td>
                                </tr>
                                @if($embeddingJob && $embeddingJob->status === 'completed')
                                <tr>
                                    <td style="padding: 4px 24px 4px 0; color: #5f6368; white-space: nowrap; border: none;">{{ __('ui.knowledge_units') }}</td>
                                    <td style="padding: 4px 0; border: none;">{{ $knowledgeUnits->count() }}</td>
                                </tr>
                                @endif
                            </table>
                            <div style="flex: 1;"></div>
                            @php $hasApproved = ($statusCounts->get('approved', 0) > 0); @endphp
                            <button onclick="openChatOverlay()" class="btn btn-primary"
                                style="padding: 12px 28px; font-size: 15px; border-radius: 10px; display: flex; align-items: center; gap: 8px; flex-shrink: 0; white-space: nowrap; {{ !$hasApproved ? 'opacity: 0.4; pointer-events: none;' : '' }}"
                                {{ !$hasApproved ? 'disabled' : '' }}
                                title="{{ !$hasApproved ? __('ui.approve_ku_first') ?? 'Approve at least one KU to enable chat' : '' }}">
                                💬 {{ __('ui.chat_with_data') }}
                            </button>
                        </div>
                    </div>

                    <!-- Action buttons -->
                    <div style="display: flex; gap: 8px; flex-shrink: 0; margin-left: 16px;">
                        <!-- QA Registration (opens overlay modal) -->
                        <button type="button" onclick="openQaModal()"
                           class="btn btn-sm btn-outline" style="display: flex; align-items: center; gap: 4px;">
                            <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M8 3v10M3 8h10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                            {{ __('ui.manual_qa_registration') }}
                        </button>
                        <!-- Export dropdown: CSV or JSON -->
                        <div style="position: relative; display: inline-block;">
                            <button type="button" class="btn btn-sm btn-outline" style="display: flex; align-items: center; gap: 4px;"
                                    onclick="this.nextElementSibling.style.display = this.nextElementSibling.style.display === 'block' ? 'none' : 'block'">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                {{ __('ui.export') }}
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                            </button>
                            <div data-export-dropdown style="display: none; position: absolute; right: 0; top: 100%; margin-top: 4px; background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.12); z-index: 100; min-width: 180px; overflow: hidden;">
                                {{-- Pass the currently-selected clustering run so the download
                                     contains only that run's KUs (matches the YYYYMMDD-HHMM
                                     header shown above). Falls back to all KUs under the
                                     embedding if no run is selected. --}}
                                @php $exportJobParam = $embeddingJob ? ['job' => $embeddingJob->id] : []; @endphp
                                <a href="{{ route('workspace.export', array_merge(['embeddingId' => $current->id, 'format' => 'csv'], $exportJobParam)) }}"
                                   style="display: block; padding: 8px 16px; color: #333; text-decoration: none; font-size: 13px;"
                                   onmouseover="this.style.background='#f5f5f5'" onmouseout="this.style.background='#fff'">
                                    {{ __('ui.cluster') }} CSV (UTF-8)
                                </a>
                                <a href="{{ route('workspace.export', array_merge(['embeddingId' => $current->id, 'format' => 'json'], $exportJobParam)) }}"
                                   style="display: block; padding: 8px 16px; color: #333; text-decoration: none; font-size: 13px;"
                                   onmouseover="this.style.background='#f5f5f5'" onmouseout="this.style.background='#fff'">
                                    {{ __('ui.cluster') }} JSON
                                </a>
                                <div style="border-top: 1px solid #e0e0e0;"></div>
                                <a href="{{ route('workspace.export-rows', array_merge(['embeddingId' => $current->id], $exportJobParam)) }}"
                                   style="display: block; padding: 8px 16px; color: #333; text-decoration: none; font-size: 13px;"
                                   onmouseover="this.style.background='#f5f5f5'" onmouseout="this.style.background='#fff'">
                                    {{ __('ui.export_rows_with_clusters') }}
                                </a>
                            </div>
                        </div>
                        <form method="POST" action="{{ route('workspace.destroy', $current->id) }}"
                              onsubmit="return confirm('{{ __('ui.delete_embedding_confirm', ['name' => $current->name]) }}')">
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

                {{-- Split the conditional branches:
                     #embedding-progress-panel wraps ONLY the "running" branch
                     so the refresh-polling can swap its innerHTML every few
                     seconds without touching the KU list below (which holds
                     user selections / scroll position we want to preserve).
                     When no job is running the panel is empty and the page
                     falls through to the empty/KU-list branches. --}}
                @php
                    $showProgress = $embeddingJob && !in_array($embeddingJob->status, ['completed', 'failed']);
                @endphp
                <div id="embedding-progress-panel">
                @if($showProgress)
                    <div class="empty">
                        <div class="empty-icon" style="color: #ff9500;">
                            <svg width="48" height="48" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="24" cy="24" r="20"/><path d="M24 14v12l8 4"/>
                            </svg>
                        </div>
                        <div class="empty-title">{{ __('ui.pipeline_processing') }}</div>
                        <p>{{ __('ui.pipeline_processing_hint') }}</p>
                        <div style="margin-top: 12px; width: 200px; height: 4px; background: #e5e5e7; border-radius: 2px; overflow: hidden;">
                            <div style="width: {{ $embeddingJob->progress }}%; height: 100%; background: #ff9500; border-radius: 2px; transition: width 0.3s;"></div>
                        </div>
                        @php
                            // Translate pipeline step names to user-friendly descriptions
                            $stepLabels = [
                                'submitted' => __('ui.step_submitted') ?? 'Waiting to start',
                                'queued' => __('ui.step_queued') ?? 'Queued',
                                'preprocess' => __('ui.step_preprocess') ?? 'Preprocessing data',
                                'embedding' => __('ui.step_embedding') ?? 'Generating embeddings',
                                'clustering' => __('ui.step_clustering') ?? 'Clustering',
                                'cluster_analysis' => __('ui.step_cluster_analysis') ?? 'Analyzing clusters (LLM)',
                                'knowledge_unit_generation' => __('ui.step_ku_generation') ?? 'Generating knowledge units (LLM)',
                                'parameter_search' => __('ui.step_parameter_search') ?? 'Searching parameters',
                            ];
                            $stepLabel = $stepLabels[$embeddingJob->status] ?? $embeddingJob->status;
                        @endphp
                        <div style="font-size: 13px; color: #1d1d1f; margin-top: 6px; font-weight: 500;">
                            {{ $stepLabel }}
                        </div>
                        <div style="font-size: 12px; color: #5f6368; margin-top: 2px;">
                            {{ $embeddingJob->progress }}%
                        </div>
                        {{-- Worker heartbeat: what the step is doing right now.
                             Populated by worker's update_job_action() at each slow op
                             (S3 I/O, Bedrock calls, DB batch writes). Gives users
                             continuous feedback between coarse %-based updates. --}}
                        @if(!empty($embeddingJob->current_action))
                            <div style="font-size: 12px; color: #5f6368; margin-top: 6px; max-width: 360px; text-align: center; font-style: italic;">
                                {{ $embeddingJob->current_action }}
                            </div>
                        @endif
                    </div>
                @endif
                </div>
                @if(!$showProgress && $knowledgeUnits->isEmpty())
                    <div class="empty">
                        <div class="empty-icon">
                            <svg width="48" height="48" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="6" y="30" width="8" height="12" rx="1"/><rect x="20" y="18" width="8" height="24" rx="1"/><rect x="34" y="6" width="8" height="36" rx="1"/>
                            </svg>
                        </div>
                        <div class="empty-title">{{ __('ui.no_clusters_yet') }}</div>
                        <p>{{ __('ui.run_pipeline_to_generate') }}</p>
                    </div>
                @elseif(!$showProgress)
                    @php
                        $approvedCount = $knowledgeUnits->where('review_status', 'approved')->count();
                        $totalCount = $knowledgeUnits->count();
                    @endphp

                    {{-- Toolbar: selection controls + count + status actions (in one row) --}}
                    <div style="display: flex; align-items: center; margin-bottom: 10px; gap: 8px; flex-wrap: wrap;">
                        <button type="button" onclick="kuSelectAll()" class="btn btn-sm btn-outline" style="font-size: 12px;">{{ __('ui.select_all_btn') }}</button>
                        <button type="button" onclick="kuDeselectAll()" class="btn btn-sm btn-outline" style="font-size: 12px;">{{ __('ui.deselect_all') }}</button>
                        <span id="ku-selection-count" style="font-size: 12px; color: #5f6368;">0 {{ __('ui.selected') }}</span>
                        <form method="POST" action="{{ route('workspace.ku.bulk-status', $current->id) }}" id="ku-bulk-form" style="display: contents;">
                            @csrf
                            <input type="hidden" name="ku_ids" id="ku-bulk-ids" value="">
                            <input type="hidden" name="new_status" id="ku-bulk-status" value="">
                            <button type="button" onclick="kuBulkAction('approved')" id="ku-btn-approve"
                                class="btn btn-sm btn-outline" style="font-size: 12px; border-color: #30d158; color: #30d158;" disabled>{{ __('ui.approve') }}</button>
                            <button type="button" onclick="kuBulkAction('draft')" id="ku-btn-exclude"
                                class="btn btn-sm btn-outline" style="font-size: 12px; border-color: #ff9500; color: #ff9500;" disabled>{{ __('ui.set_excluded') }}</button>
                        </form>
                        <span style="margin-left: auto; font-size: 12px; color: #5f6368;">{{ $approvedCount }}/{{ $totalCount }} {{ __('ui.approved_count') }}</span>
                    </div>

                    <table class="ku-table">
                        @php $totalKuRows = $knowledgeUnits->sum('row_count'); @endphp
                        <tbody>
                            @foreach($knowledgeUnits as $ku)
                                <tr style="cursor: pointer; {{ $ku->review_status === 'draft' ? 'opacity: 0.5;' : '' }}"
                                    onclick="if(!event.target.closest('input[type=checkbox]'))window.location='{{ route('workspace.ku', ['embeddingId' => $current->id, 'kuId' => $ku->id]) }}'">
                                    <td onclick="event.stopPropagation();" style="width: 36px; vertical-align: top; padding-top: 12px;">
                                        <input type="checkbox" class="ku-checkbox" value="{{ $ku->id }}" style="cursor: pointer;" onchange="kuUpdateSelection()">
                                    </td>
                                    <td style="max-width: 0; width: 100%;">
                                        <div style="display: flex; align-items: baseline; gap: 8px;">
                                            <span style="font-size: 16px; flex-shrink: 0;" title="{{ $ku->review_status }}">{{ $ku->review_status === 'approved' ? '✅' : '⬜' }}</span>
                                            <span style="font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">{{ $ku->intent }}</span>
                                            @if($ku->primary_filter)
                                                <span style="font-size: 11px; color: #0071e3; background: #e8f0fe; padding: 1px 6px; border-radius: 4px; white-space: nowrap; flex-shrink: 0;">{{ Str::limit($ku->primary_filter, 30) }}</span>
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
                                        @if($totalKuRows > 0)
                                            <div style="font-size: 12px; color: #aaa; margin-top: 4px;">{{ number_format($ku->row_count / $totalKuRows * 100, 1) }}%</div>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            @else
                <div class="empty">
                    <div class="empty-icon">
                        <svg width="48" height="48" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M6 12a2 2 0 012-2h10l4 4h18a2 2 0 012 2v20a2 2 0 01-2 2H8a2 2 0 01-2-2V12z"/>
                            <line x1="16" y1="24" x2="32" y2="24"/><line x1="16" y1="30" x2="28" y2="30"/>
                        </svg>
                    </div>
                    <div class="empty-title">{{ __('ui.select_embedding') }}</div>
                    <p>{{ __('ui.select_embedding_hint') }}</p>
                </div>
            @endif
        </div>
    </div>

    {{-- Dispatch modal: bottom-right compose window for uploading CSV datasets --}}
    <div id="dispatch-modal" style="display: none; position: fixed; bottom: 0; right: 24px; z-index: 1000;
        width: 520px; max-height: 80vh; background: #fff; border-radius: 12px 12px 0 0;
        box-shadow: 0 -4px 24px rgba(0,0,0,0.2); display: none; flex-direction: column;">

        <!-- Modal header (draggable look) -->
        <div style="display: flex; align-items: center; justify-content: space-between; padding: 12px 16px;
            background: #F3F3F3; border-radius: 12px 12px 0 0; cursor: default;">
            <span style="font-size: 14px; font-weight: 400; color: #1d1d1f;">{{ __('ui.new_dataset') ?? 'New Dataset' }}</span>
            <button onclick="closeDispatchModal()" style="background: none; border: none; cursor: pointer;
                color: #5f6368; font-size: 18px; line-height: 1; padding: 0 4px;">✕</button>
        </div>

        <!-- Modal body -->
        <div style="padding: 20px; overflow-y: auto; flex: 1;">
            <!-- Upload Dataset -->
            <div style="margin-bottom: 20px;">
                <div style="font-size: 13px; font-weight: 600; color: #5f6368; margin-bottom: 8px;">{{ strtoupper(__('ui.upload_csv')) }}</div>
                <form method="POST" action="{{ route('dataset.upload') }}" enctype="multipart/form-data"
                    style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;"
                    onsubmit="showUploadOverlay()">
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

    {{-- QA Registration modal: overlay form for adding KUs directly --}}
    @if($current)
    <div id="qa-modal-backdrop" onclick="closeQaModal()" style="display: none; position: fixed; inset: 0; z-index: 1100; background: rgba(0,0,0,0.3);"></div>
    <div id="qa-modal" style="display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%);
        z-index: 1101; width: 560px; max-height: 80vh; background: #fff; border-radius: 12px;
        box-shadow: 0 16px 48px rgba(0,0,0,0.2); flex-direction: column; overflow: hidden;">

        <!-- Modal header -->
        <div style="display: flex; align-items: center; justify-content: space-between; padding: 14px 20px;
            background: #F3F3F3; border-radius: 12px 12px 0 0; flex-shrink: 0;">
            <span style="font-size: 14px; font-weight: 600; color: #1d1d1f;">{{ __('ui.manual_qa_create') }}</span>
            <button onclick="closeQaModal()" style="background: none; border: none; cursor: pointer;
                color: #5f6368; font-size: 18px; line-height: 1; padding: 0 4px;">&times;</button>
        </div>

        <!-- Modal body (scrollable) -->
        <div style="padding: 20px; overflow-y: auto; flex: 1;">
            <div id="qa-modal-error" style="display: none; background: #f8d7da; color: #721c24; padding: 10px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 12px;"></div>
            <div id="qa-modal-success" style="display: none; background: #d4edda; color: #155724; padding: 10px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 12px;"></div>

            <form id="qa-modal-form" onsubmit="submitQaForm(event)">
                <!-- Core QA fields -->
                <div style="margin-bottom: 14px;">
                    <label style="font-size: 12px; font-weight: 500; color: #5f6368; display: block; margin-bottom: 3px;">{{ __('ui.manual_qa_question') }} <span style="color: #ff3b30;">*</span></label>
                    <textarea id="qa-question" name="question" rows="2" required maxlength="2000" placeholder="{{ __('ui.manual_qa_question_placeholder') }}"
                        style="width: 100%; padding: 8px 10px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 13px; font-family: inherit; resize: vertical;"></textarea>
                </div>
                <div style="margin-bottom: 14px;">
                    <label style="font-size: 12px; font-weight: 500; color: #5f6368; display: block; margin-bottom: 3px;">{{ __('ui.manual_qa_resolution') }} <span style="color: #ff3b30;">*</span></label>
                    <textarea id="qa-resolution" name="resolution_summary" rows="3" required maxlength="5000" placeholder="{{ __('ui.manual_qa_resolution_placeholder') }}"
                        style="width: 100%; padding: 8px 10px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 13px; font-family: inherit; resize: vertical;"></textarea>
                </div>

                <!-- Classification (2-column) -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 14px;">
                    <div>
                        <label style="font-size: 12px; font-weight: 500; color: #5f6368; display: block; margin-bottom: 3px;">{{ __('ui.topic') }} <span style="color: #ff3b30;">*</span></label>
                        <input id="qa-topic" name="topic" type="text" required maxlength="200" placeholder="{{ __('ui.manual_qa_topic_placeholder') }}"
                            style="width: 100%; padding: 8px 10px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 13px;">
                    </div>
                    <div>
                        <label style="font-size: 12px; font-weight: 500; color: #5f6368; display: block; margin-bottom: 3px;">{{ __('ui.intent') }} <span style="color: #ff3b30;">*</span></label>
                        <input id="qa-intent" name="intent" type="text" required maxlength="200" placeholder="{{ __('ui.manual_qa_intent_placeholder') }}"
                            style="width: 100%; padding: 8px 10px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 13px;">
                    </div>
                </div>
                <div style="margin-bottom: 14px;">
                    <label style="font-size: 12px; font-weight: 500; color: #5f6368; display: block; margin-bottom: 3px;">{{ __('ui.summary') }} <span style="color: #ff3b30;">*</span></label>
                    <textarea id="qa-summary" name="summary" rows="2" required maxlength="5000"
                        style="width: 100%; padding: 8px 10px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 13px; font-family: inherit; resize: vertical;"></textarea>
                </div>

                <!-- Optional fields (collapsed) -->
                <details style="margin-bottom: 14px;">
                    <summary style="font-size: 12px; font-weight: 600; color: #5f6368; cursor: pointer; user-select: none;">{{ __('ui.manual_qa_details') }}</summary>
                    <div style="margin-top: 10px; display: grid; gap: 10px;">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                            <div>
                                <label style="font-size: 12px; color: #5f6368; display: block; margin-bottom: 3px;">{{ __('ui.primary_filter') }}</label>
                                <input name="primary_filter" type="text" maxlength="255" placeholder="{{ __('ui.manual_qa_filter_placeholder') }}"
                                    style="width: 100%; padding: 8px 10px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 13px;">
                            </div>
                            <div>
                                <label style="font-size: 12px; color: #5f6368; display: block; margin-bottom: 3px;">{{ __('ui.category') }}</label>
                                <input name="category" type="text" maxlength="255"
                                    style="width: 100%; padding: 8px 10px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 13px;">
                            </div>
                        </div>
                        <div>
                            <label style="font-size: 12px; color: #5f6368; display: block; margin-bottom: 3px;">{{ __('ui.reference_url') }}</label>
                            <input name="reference_url" type="url" maxlength="2048" placeholder="https://docs.example.com/..."
                                style="width: 100%; padding: 8px 10px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 13px;">
                        </div>
                        <div>
                            <label style="font-size: 12px; color: #5f6368; display: block; margin-bottom: 3px;">{{ __('ui.keywords') }}</label>
                            <input name="keywords" type="text" maxlength="1000" placeholder="{{ __('ui.manual_qa_keywords_placeholder') }}"
                                style="width: 100%; padding: 8px 10px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 13px;">
                        </div>
                    </div>
                </details>

                <!-- Submit -->
                <div style="display: flex; gap: 8px; justify-content: flex-end;">
                    <button type="button" onclick="closeQaModal()" class="btn btn-outline" style="font-size: 13px;">{{ __('ui.cancel') }}</button>
                    <button type="submit" id="qa-submit-btn" class="btn btn-primary" style="font-size: 13px;">{{ __('ui.manual_qa_save') }}</button>
                </div>
            </form>
        </div>
    </div>
    @endif

    {{-- Upload processing overlay: full-screen indicator shown while CSV is being uploaded --}}
    <div id="upload-overlay" style="display: none; position: fixed; inset: 0; z-index: 9999;
        background: rgba(255,255,255,0.85); align-items: center; justify-content: center; flex-direction: column;">
        <div style="display: flex; flex-direction: column; align-items: center; gap: 16px;">
            <svg width="40" height="40" viewBox="0 0 40 40" style="animation: spin 1.2s linear infinite;">
                <circle cx="20" cy="20" r="16" fill="none" stroke="#dadce0" stroke-width="3"/>
                <circle cx="20" cy="20" r="16" fill="none" stroke="#1a73e8" stroke-width="3"
                    stroke-dasharray="80" stroke-dashoffset="60" stroke-linecap="round"/>
            </svg>
            <span style="font-size: 15px; color: #3c4043;">{{ __('ui.csv_processing') }}</span>
        </div>
    </div>
    <style>@keyframes spin { to { transform: rotate(360deg); } }</style>

    {{-- Chat overlay: bottom-right floating RAG chat window for querying approved KUs --}}
    @if($current)
    <div id="chat-overlay" style="display: none; position: fixed; bottom: 0; right: 24px; z-index: 1001;
        width: 480px; height: 600px; background: #fff; border-radius: 12px 12px 0 0;
        box-shadow: 0 -4px 24px rgba(0,0,0,0.2); flex-direction: column;">

        <!-- Header -->
        <div style="display: flex; align-items: center; justify-content: space-between; padding: 10px 16px;
            background: #1d1d1f; border-radius: 12px 12px 0 0; flex-shrink: 0;">
            <div>
                <div style="font-size: 14px; font-weight: 600; color: #fff;">{{ __('ui.chat') }} — {{ Str::limit($current->name, 30) }}</div>
                <div style="font-size: 11px; color: #aaa;">{{ __('ui.approved_ku_count', ['count' => $knowledgeUnits->where('review_status', 'approved')->count()]) }}</div>
            </div>
            <div style="display: flex; gap: 8px; align-items: center;">
                <button onclick="toggleHistoryPanel()" title="{{ __('ui.chat_history') }}"
                    style="background: none; border: none; cursor: pointer; color: #aaa; font-size: 12px; display: flex; align-items: center; gap: 4px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                    </svg>
                    {{ __('ui.chat_history') }}
                </button>
                <button onclick="clearChat()" style="background: none; border: none; cursor: pointer; color: #aaa; font-size: 12px;" title="{{ __('ui.clear_chat') }}">{{ __('ui.clear_chat') }}</button>
                <button onclick="closeChatOverlay()" style="background: none; border: none; cursor: pointer; color: #fff; font-size: 18px; line-height: 1; padding: 0 4px;">✕</button>
            </div>
        </div>

        <!-- History panel: slides in over the messages area -->
        <div id="chat-history-panel" style="display: none; flex-direction: column; flex: 1; overflow: hidden; background: #fff;">
            <div style="padding: 10px 16px; border-bottom: 1px solid #e5e5e7; display: flex; align-items: center; justify-content: space-between;">
                <span style="font-size: 13px; font-weight: 600; color: #1d1d1f;">{{ __('ui.chat_history') }}</span>
                <button onclick="toggleHistoryPanel()" style="background: none; border: none; cursor: pointer; color: #5f6368; font-size: 12px;">{{ __('ui.close') }}</button>
            </div>
            <div id="chat-history-list" style="flex: 1; overflow-y: auto; padding: 8px;">
                <div style="text-align: center; color: #aaa; font-size: 12px; padding: 20px;">{{ __('ui.chat_history_loading') }}</div>
            </div>
        </div>

        <!-- Messages area -->
        <div id="chat-messages" style="flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 12px;">
            <div style="text-align: center; color: #5f6368; font-size: 12px; padding: 20px 0;">
                {{ __('ui.chat_placeholder') }}
            </div>
        </div>

        <!-- Input area (hidden while history panel is open) -->
        <div id="chat-input-area" style="border-top: 1px solid #e5e5e7; padding: 12px 16px; flex-shrink: 0;">
            <form onsubmit="sendChatMessage(event)" style="display: flex; gap: 8px;">
                <input type="text" id="chat-input" placeholder="{{ __('ui.chat_input_placeholder') }}"
                    style="flex: 1; padding: 10px 14px; border: 1px solid #d2d2d7; border-radius: 20px; font-size: 14px; outline: none;"
                    autocomplete="off">
                <button type="submit" id="chat-send-btn"
                    style="background: #0071e3; color: #fff; border: none; border-radius: 50%; width: 36px; height: 36px; cursor: pointer; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <svg width="18" height="18" viewBox="0 0 18 18" fill="none"><path d="M2 3l14 6-14 6 2-6-2-6z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M4 9h12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                </button>
            </form>
        </div>
    </div>
    @endif
@endsection

@section('scripts')
        // ── KU Selection & Bulk Status ──────────────────────────
        function kuSelectAll() {
            document.querySelectorAll('.ku-checkbox').forEach(function(c) { c.checked = true; });
            kuUpdateSelection();
        }
        function kuDeselectAll() {
            document.querySelectorAll('.ku-checkbox').forEach(function(c) { c.checked = false; });
            kuUpdateSelection();
        }
        function kuUpdateSelection() {
            var checked = document.querySelectorAll('.ku-checkbox:checked');
            var count = checked.length;
            var label = document.getElementById('ku-selection-count');
            if (label) label.textContent = count + ' {{ __("ui.selected") }}';
            var btnApprove = document.getElementById('ku-btn-approve');
            var btnExclude = document.getElementById('ku-btn-exclude');
            if (btnApprove) btnApprove.disabled = count === 0;
            if (btnExclude) btnExclude.disabled = count === 0;
        }
        function kuBulkAction(status) {
            var checked = document.querySelectorAll('.ku-checkbox:checked');
            if (checked.length === 0) return;
            var ids = Array.from(checked).map(function(c) { return c.value; });
            document.getElementById('ku-bulk-ids').value = ids.join(',');
            document.getElementById('ku-bulk-status').value = status;
            document.getElementById('ku-bulk-form').submit();
        }

        // ── QA Registration Modal ──────────────────────────────
        function openQaModal() {
            document.getElementById('qa-modal-backdrop').style.display = 'block';
            document.getElementById('qa-modal').style.display = 'flex';
            document.getElementById('qa-modal-error').style.display = 'none';
            document.getElementById('qa-modal-success').style.display = 'none';
            document.getElementById('qa-modal-form').reset();
            document.getElementById('qa-question').focus();
        }
        function closeQaModal() {
            document.getElementById('qa-modal-backdrop').style.display = 'none';
            document.getElementById('qa-modal').style.display = 'none';
        }
        function submitQaForm(e) {
            e.preventDefault();
            var form = document.getElementById('qa-modal-form');
            var btn = document.getElementById('qa-submit-btn');
            var errBox = document.getElementById('qa-modal-error');
            var okBox = document.getElementById('qa-modal-success');
            errBox.style.display = 'none';
            okBox.style.display = 'none';
            btn.disabled = true;
            btn.textContent = '{{ __("ui.saving") }}...';

            var data = new FormData(form);
            data.append('embedding_id', '{{ $current->id ?? "" }}');

            fetch('{{ route("knowledge-units.store") }}', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: data,
            })
            .then(function(r) { return r.json().then(function(d) { return { ok: r.ok, data: d }; }); })
            .then(function(res) {
                if (res.ok && res.data.success) {
                    okBox.textContent = res.data.message;
                    okBox.style.display = 'block';
                    form.reset();
                    // Reload page after short delay to show the new KU in the list
                    setTimeout(function() { window.location.reload(); }, 1200);
                } else {
                    var msg = res.data.error || res.data.message || Object.values(res.data.errors || {}).flat().join(', ') || 'Unknown error';
                    errBox.textContent = msg;
                    errBox.style.display = 'block';
                }
            })
            .catch(function() {
                errBox.textContent = 'Network error. Please try again.';
                errBox.style.display = 'block';
            })
            .finally(function() {
                btn.disabled = false;
                btn.textContent = '{{ __("ui.manual_qa_save") }}';
            });
        }
        // Close modal on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && document.getElementById('qa-modal').style.display === 'flex') {
                closeQaModal();
            }
        });

        // Close export dropdown when clicking outside
        document.addEventListener('click', function(e) {
            document.querySelectorAll('[data-export-dropdown]').forEach(function(dd) {
                if (!dd.parentElement.contains(e.target)) {
                    dd.style.display = 'none';
                }
            });
        });

        // Tree view toggle (open/close dataset nodes)
        /** Toggle the recluster form visibility in the comparison view */
        /**
         * Transition a pending dataset row into "deleting" state:
         * hide the delete button, swap folder icon for a spinner,
         * grey out the name, disable the configure link, and
         * update the subtitle to show deletion in progress.
         */
        function startDatasetDeletion(datasetId) {
            const row = document.getElementById('pending-ds-' + datasetId);
            if (!row) return;
            // Hide delete button
            const btn = row.querySelector('.pending-ds-delete-btn');
            if (btn) btn.style.display = 'none';
            // Swap icon: folder → spinner
            const folderIcon = row.querySelector('.pending-ds-icon');
            const spinner = row.querySelector('.pending-ds-spinner');
            if (folderIcon) folderIcon.style.display = 'none';
            if (spinner) spinner.style.display = '';
            // Disable link and grey out text
            const link = row.querySelector('.pending-ds-link');
            if (link) { link.style.pointerEvents = 'none'; link.style.opacity = '0.5'; }
            // Update subtitle to "Deleting..."
            const subtitle = row.querySelector('.pending-ds-subtitle');
            if (subtitle) subtitle.textContent = '{{ __("ui.deleting") }}';
        }

        function toggleReclusterForm() {
            const form = document.getElementById('recluster-form');
            const chevron = document.getElementById('recluster-chevron');
            if (!form) return;
            const visible = form.style.display !== 'none';
            form.style.display = visible ? 'none' : 'block';
            chevron.style.transform = visible ? '' : 'rotate(90deg)';
        }

        /** Toggle recluster form parameter visibility based on selected method */
        function toggleRcParams() {
            const method = document.getElementById('rc-method')?.value;
            ['leiden', 'hdbscan', 'kmeans', 'agglomerative'].forEach(m => {
                const el = document.getElementById('rc-params-' + m);
                if (el) el.style.display = (method === m) ? '' : 'none';
            });
        }

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
            document.getElementById('rename-pen').style.display = 'none';
            document.getElementById('rename-form').style.display = 'block';
            const input = document.getElementById('rename-input');
            input.focus();
            input.select();
        }
        function cancelRename() {
            document.getElementById('emb-title').style.display = '';
            document.getElementById('rename-pen').style.display = '';
            document.getElementById('rename-form').style.display = 'none';
        }

        // ── Parameter Search: AJAX polling + chart rendering ──────────
        const paramSearchUrl = '{{ $current ? route("workspace.parameter-search-results", $current->id ?? 0) : "" }}';
        const METHOD_COLORS = { leiden: '#0071e3', hdbscan: '#ff9500', kmeans: '#30d158', agglomerative: '#af52de' };

        let paramResultsExpanded = false; // Track collapse state

        /** Toggle the parameter search results body visibility */
        function toggleParamResults() {
            const body = document.getElementById('param-search-body');
            const chevron = document.getElementById('param-results-chevron');
            if (!body) return;
            paramResultsExpanded = !paramResultsExpanded;
            body.style.display = paramResultsExpanded ? '' : 'none';
            chevron.style.transform = paramResultsExpanded ? 'rotate(90deg)' : '';
            // Draw the line overlay once the panel is visible and laid out
            if (paramResultsExpanded) requestAnimationFrame(drawParamLine);
        }

        /** Dismiss (delete) the parameter search results by deleting the job */
        function dismissParamResults() {
            if (!confirm('{{ __("ui.confirm_dismiss_param_search") }}')) return;
            // Find and delete the parameter_search job via the delete-job endpoint
            fetch(paramSearchUrl)
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'not_found') return;
                    // Use the AJAX endpoint to get job info, then delete via form
                    document.getElementById('param-search-results').style.display = 'none';
                    // POST delete request
                    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content
                        || document.querySelector('input[name="_token"]')?.value;
                    fetch('{{ $current ? route("workspace.dismiss-param-search", $current->id) : "" }}', {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
                    });
                });
        }

        /** Poll the parameter search results endpoint and render when complete */
        function pollParameterSearch() {
            if (!paramSearchUrl) return;
            fetch(paramSearchUrl)
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'not_found') return;
                    const container = document.getElementById('param-search-results');
                    const statusEl = document.getElementById('param-search-status');
                    const dismissBtn = document.getElementById('param-search-dismiss');
                    const body = document.getElementById('param-search-body');
                    const chevron = document.getElementById('param-results-chevron');
                    if (!container) return;

                    if (data.status === 'completed' && data.results) {
                        // Show results — collapsed by default, expanded if just finished polling
                        container.style.display = '';
                        dismissBtn.style.display = '';
                        // Sweep finished → re-enable the action buttons that were
                        // locked out during the run.
                        setParamSearchRunningState(false);
                        // Reveal the PDF export button once real results exist
                        const pdfBtn = document.getElementById('param-search-pdf');
                        if (pdfBtn) pdfBtn.style.display = '';
                        // Stash sample_size so renderParamTopResults can add it to each row.
                        window._paramSearchSampleSize = data.results.sample_size;
                        statusEl.textContent = data.results.sample_size + ' {{ __("ui.rows") }} {{ __("ui.sampled") }}, '
                            + data.results.configs_tested + ' {{ __("ui.configs_tested") }}';
                        renderParamChart(data.results.results);
                        renderParamTopResults(data.results.results);
                        // Auto-expand if we were polling (just finished)
                        if (!paramResultsExpanded && window._paramSearchPolling) {
                            paramResultsExpanded = true;
                            body.style.display = '';
                            chevron.style.transform = 'rotate(90deg)';
                        }
                        // Draw line overlay after layout is complete (panel must be visible)
                        if (paramResultsExpanded) requestAnimationFrame(drawParamLine);
                        window._paramSearchPolling = false;
                    } else if (data.status !== 'completed' && data.status !== 'failed') {
                        // Still running — show expanded with progress
                        container.style.display = '';
                        dismissBtn.style.display = 'none';
                        paramResultsExpanded = true;
                        body.style.display = '';
                        chevron.style.transform = 'rotate(90deg)';
                        // Running: lock out the concurrent-dispatch buttons so the
                        // user cannot fire a second parameter search or a clustering
                        // run that would compete for the same queue slot.
                        setParamSearchRunningState(true);
                        statusEl.innerHTML = '<span style="display:inline-block;animation:spin 1s linear infinite;">🔍</span> '
                            + '{{ __("ui.parameter_search_running") }} (' + data.progress + '%)';
                        document.getElementById('param-search-chart').innerHTML = '';
                        document.getElementById('param-search-top').innerHTML = '';
                        window._paramSearchPolling = true;
                        setTimeout(pollParameterSearch, 3000);
                    } else if (data.status === 'failed') {
                        container.style.display = '';
                        dismissBtn.style.display = '';
                        setParamSearchRunningState(false);
                        statusEl.textContent = '{{ __("ui.parameter_search_failed") }}';
                    }
                })
                .catch(() => {});
        }

        /** Render a dual-axis chart: bars (silhouette, left axis) + line (cluster count, right axis) */
        function renderParamChart(results) {
            const chart = document.getElementById('param-search-chart');
            const legend = document.getElementById('param-search-legend');
            const yaxis = document.getElementById('param-search-yaxis');
            const yaxis2 = document.getElementById('param-search-yaxis2');
            const gridlines = document.getElementById('param-search-gridlines');
            const lineSvg = document.getElementById('param-search-line');
            if (!chart || !results.length) return;

            // Left axis: silhouette scores — round up to nearest 0.05
            const rawMax = Math.max(...results.map(r => r.silhouette_score));
            const maxSil = Math.ceil(rawMax * 20) / 20;
            const chartHeight = 160;

            // Generate gridlines at 0.05 intervals
            const gridStep = 0.05;
            let gridHtml = '';
            let yLabels = [];
            for (let v = 0; v <= maxSil + 0.001; v += gridStep) {
                const pct = maxSil > 0 ? (v / maxSil) * 100 : 0;
                gridHtml += '<div style="position:absolute;left:0;right:0;bottom:' + pct + '%;border-top:1px solid #e0e0e2;"></div>';
                yLabels.push({ value: v, pct: pct });
            }
            gridlines.innerHTML = gridHtml;

            // Left Y-axis labels (silhouette)
            yaxis.innerHTML = yLabels.slice().reverse().map(l =>
                '<span style="line-height:1;">' + l.value.toFixed(2) + '</span>'
            ).join('');

            // Right axis: cluster count — find nice max
            const maxClusters = Math.max(...results.map(r => r.n_clusters));
            const clusterCeil = Math.ceil(maxClusters / 10) * 10 || 10;

            // Right Y-axis labels (cluster count)
            const clusterStep = Math.max(Math.round(clusterCeil / 4), 1);
            let y2Labels = [];
            for (let v = 0; v <= clusterCeil; v += clusterStep) {
                y2Labels.push(v);
            }
            yaxis2.innerHTML = y2Labels.slice().reverse().map(v =>
                '<span style="line-height:1;">' + v + '</span>'
            ).join('');

            // Bars (silhouette)
            chart.innerHTML = results.map((r, i) => {
                const height = maxSil > 0 ? Math.max((r.silhouette_score / maxSil) * chartHeight, 2) : 2;
                const color = METHOD_COLORS[r.method] || '#888';
                const tooltip = r.label + '\n' + r.n_clusters + ' clusters, sil=' + r.silhouette_score
                    + (r.n_noise != null ? ', noise=' + r.n_noise : '');
                return '<div data-idx="' + i + '" title="' + tooltip + '" '
                    + 'style="flex:1;min-width:6px;max-width:24px;height:' + height + 'px;'
                    + 'background:' + color + ';border-radius:3px 3px 0 0;cursor:pointer;transition:opacity 0.15s;" '
                    + 'onmouseover="this.style.opacity=0.7" onmouseout="this.style.opacity=1" '
                    + 'onclick="selectParamResult(' + i + ')"></div>';
            }).join('');

            // X-axis labels (1..N) aligned under the bars via matching flex
            // rules (flex:1;min-width:6px;max-width:24px). The numbers are
            // the rank order shown in the top-results table, so readers can
            // cross-reference a bar with its row in the PDF table.
            const xAxis = document.getElementById('param-search-xaxis');
            if (xAxis) {
                xAxis.innerHTML = results.map((_, i) =>
                    '<div style="flex:1;min-width:6px;max-width:24px;text-align:center;">' + (i + 1) + '</div>'
                ).join('');
            }

            // Line overlay (cluster count) — store data; drawn by drawParamLine()
            window._paramLineData = { results: results, clusterCeil: clusterCeil, chartHeight: chartHeight };

            // Legend — add cluster count line indicator
            const methods = [...new Set(results.map(r => r.method))];
            legend.innerHTML = methods.map(m =>
                '<span style="display:flex;align-items:center;gap:4px;">'
                + '<span style="width:10px;height:10px;border-radius:2px;background:' + (METHOD_COLORS[m]||'#888') + ';"></span>'
                + m.toUpperCase() + '</span>'
            ).join('')
            + '<span style="display:flex;align-items:center;gap:4px;">'
            + '<span style="width:14px;height:0;border-top:2px solid #ff3b30;"></span>'
            + '{{ __("ui.clusters") }}</span>';
        }

        /**
         * Draw the cluster-count polyline on the SVG overlay.
         * Must be called when the chart container is visible (not display:none)
         * so that getBoundingClientRect returns real dimensions.
         */
        function drawParamLine() {
            const data = window._paramLineData;
            if (!data) return;
            const chart = document.getElementById('param-search-chart');
            const lineSvg = document.getElementById('param-search-line');
            if (!chart || !lineSvg) return;

            const bars = chart.querySelectorAll('[data-idx]');
            if (!bars.length) return;
            const chartRect = chart.getBoundingClientRect();
            // Guard: if panel is hidden, rect width will be 0
            if (chartRect.width === 0) return;

            const { results, clusterCeil, chartHeight } = data;
            lineSvg.setAttribute('width', chartRect.width);
            lineSvg.setAttribute('height', chartHeight);
            lineSvg.setAttribute('viewBox', '0 0 ' + chartRect.width + ' ' + chartHeight);

            // Build polyline points from bar center positions
            let points = [];
            let circleEls = '';
            bars.forEach((bar, i) => {
                const barRect = bar.getBoundingClientRect();
                const cx = barRect.left - chartRect.left + barRect.width / 2;
                const cy = chartHeight - (clusterCeil > 0 ? (results[i].n_clusters / clusterCeil) * chartHeight : 0);
                points.push(cx.toFixed(1) + ',' + cy.toFixed(1));
                circleEls += '<circle cx="' + cx.toFixed(1) + '" cy="' + cy.toFixed(1) + '" r="2.5" fill="#ff3b30"/>';
            });

            // Use DOMParser to safely set SVG content
            const svgContent = '<svg xmlns="http://www.w3.org/2000/svg">'
                + '<polyline points="' + points.join(' ') + '" fill="none" stroke="#ff3b30" stroke-width="1.5" stroke-linejoin="round"/>'
                + circleEls + '</svg>';
            const parsed = new DOMParser().parseFromString(svgContent, 'image/svg+xml');
            // Clear existing children
            while (lineSvg.firstChild) lineSvg.removeChild(lineSvg.firstChild);
            // Move parsed nodes into the live SVG
            Array.from(parsed.documentElement.childNodes).forEach(n => {
                lineSvg.appendChild(document.importNode(n, true));
            });
        }

        /** Render the top 5 results as a compact table with "use this" buttons */
        function renderParamTopResults(results) {
            const container = document.getElementById('param-search-top');
            if (!container) return;
            // On-screen view keeps the compact top-5 preview. The PDF export
            // path uses _renderParamAllResultsHtml() below to render all 24.
            const top5 = results.slice(0, 5);
            let html = '<table style="width:100%;font-size:13px;border-collapse:collapse;">'
                + '<tr style="color:#5f6368;font-size:11px;"><th style="text-align:left;padding:4px;">{{ __("ui.method") }}</th>'
                + '<th style="text-align:left;padding:4px;">{{ __("ui.parameters") }}</th>'
                + '<th style="text-align:center;padding:4px;">{{ __("ui.clusters") }}</th>'
                + '<th style="text-align:center;padding:4px;">{{ __("ui.silhouette") }}</th>'
                + '<th style="text-align:center;padding:4px;">{{ __("ui.noise") }}</th>'
                + '<th class="pdf-hide"></th></tr>';
            top5.forEach((r, i) => {
                const paramStr = Object.entries(r.params || {}).map(([k,v]) => k + '=' + v).join(', ');
                const silColor = silhouetteColor(r.silhouette_score);
                html += '<tr style="border-top:1px solid #f0f0f2;' + (i === 0 ? 'background:#e8f5e9;' : '') + '">'
                    + '<td style="padding:6px 4px;font-weight:500;">' + r.label + '</td>'
                    + '<td style="padding:6px 4px;font-size:11px;color:#5f6368;">' + paramStr + '</td>'
                    + '<td style="padding:6px 4px;text-align:center;font-weight:600;">' + r.n_clusters + '</td>'
                    + '<td style="padding:6px 4px;text-align:center;font-weight:700;color:' + silColor + ';">' + r.silhouette_score.toFixed(3) + '</td>'
                    + '<td style="padding:6px 4px;text-align:center;color:#5f6368;">' + (r.n_noise != null ? r.n_noise : '—') + '</td>'
                    // "use these params" button is screen-only. Marked with
                    // pdf-hide so the html2canvas capture below elides it.
                    + '<td class="pdf-hide" style="padding:6px 4px;text-align:right;"><button onclick="applyParamResult(' + results.indexOf(r) + ')" '
                    + 'style="background:#0071e3;color:#fff;border:none;border-radius:4px;padding:3px 10px;font-size:11px;cursor:pointer;">'
                    + '{{ __("ui.use_these_params") }}</button></td></tr>';
            });
            html += '</table>';
            container.innerHTML = html;

            // Store results globally for click handlers
            window._paramSearchResults = results;
        }

        // Shared silhouette-score colour mapping. The same green/blue/grey
        // bands apply on the on-screen top-5 table, the PDF results table,
        // and (potentially) the chart. Centralised so the threshold lines
        // (≥0.3 = excellent, ≥0.1 = good) only have to move in one place.
        // `lowColor` is parameterised because the on-screen table sits on
        // a light grey body where #555 is readable, while the PDF table
        // is on white paper and needs the higher-contrast #1d1d1f.
        function silhouetteColor(score, lowColor = '#555') {
            if (score == null) return lowColor;
            if (score >= 0.3) return '#2e7d32';   // green: excellent separation
            if (score >= 0.1) return '#1565c0';   // blue: good for text
            return lowColor;                       // muted: typical / poor
        }

        // Shared "US accounting" table styling tokens used by both the
        // results table and the glossary in the PDF. The intent is a
        // calm, reading-friendly layout: no vertical lines, only thin
        // horizontal rules to separate header from body, and a thicker
        // line capping the bottom — the convention used on financial
        // statements.
        const _PDF_TABLE_STYLES = {
            container: 'background:#fff;padding:12px;font-family:inherit;',
            heading:   'margin:0 0 6px 0;font-size:12px;color:#1d1d1f;font-weight:500;letter-spacing:0.02em;',
            table:     'width:100%;border-collapse:collapse;font-size:10.5px;',
            // Header: thin double rule above and a single rule below
            theadRow:  'color:#5f6368;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;font-size:9.5px;',
            theadCell: 'padding:6px 8px;border-top:1.5px solid #1d1d1f;border-bottom:0.5px solid #1d1d1f;text-align:left;',
            theadCellNum: 'padding:6px 8px;border-top:1.5px solid #1d1d1f;border-bottom:0.5px solid #1d1d1f;text-align:right;',
            // Body rows: no row separators (US-accounting style is to
            // rely on vertical alignment, not horizontal rules)
            bodyCell:    'padding:5px 8px;text-align:left;vertical-align:top;',
            bodyCellNum: 'padding:5px 8px;text-align:right;vertical-align:top;font-variant-numeric:tabular-nums;',
            // Closing rule under the body — single line, like a totals
            // row separator on a balance sheet
            footerCell:  'border-top:0.5px solid #1d1d1f;',
            // Glossary-specific muted variant (smaller + greyer)
            mutedHeading:    'margin:0 0 6px 0;font-size:10px;color:#8a8a8e;font-weight:500;letter-spacing:0.02em;',
            mutedTable:      'width:100%;border-collapse:collapse;font-size:9.5px;color:#555;',
            mutedTheadRow:   'color:#8a8a8e;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;font-size:9px;',
            mutedTheadCell:  'padding:5px 8px;border-top:1px solid #8a8a8e;border-bottom:0.5px solid #8a8a8e;text-align:left;',
        };

        /**
         * Build a compact, low-emphasis glossary describing each clustering
         * method and its parameters. Rendered as the final section of the
         * PDF report so readers unfamiliar with HDBSCAN / Leiden / K-Means
         * / Agglomerative can interpret the results above.
         *
         * Same horizontal-rules-only style as the results table, but in
         * muted grey so it reads as supporting reference material.
         */
        function _renderParamGlossaryHtml() {
            const entries = [
                {
                    method: 'HDBSCAN',
                    desc: 'Hierarchical Density-Based Spatial Clustering。密度ベースで自動的にクラスタ数を決定し、ノイズ点を検出できる手法。距離指標は euclidean ですが、入力ベクトルを L2 正規化しているため cosine 順位と等価。',
                    params: [
                        ['min_cluster_size', 'クラスタとして扱う最小のデータ点数。大きいほど少数の大きなクラスタに、小さいほど多数の細かいクラスタになる'],
                        ['min_samples', 'コア点と判定する近傍点の最小数。大きいほどノイズに敏感になり、小さいほど緩く判定する'],
                        ['metric', '距離尺度。euclidean on L2-normalized vectors = cosine-equivalent'],
                    ],
                },
                {
                    method: 'K-Means',
                    desc: '指定したクラスタ数 K でデータを分割する古典的手法。全点が必ずいずれかのクラスタに属する（ノイズ無し）。距離は euclidean ですが、L2 正規化された入力に対しては cosine 相当（spherical k-means に相当）。',
                    params: [
                        ['n_clusters', '分割するクラスタ数。事前に決める必要がある'],
                        ['n_init', 'ランダム初期化の試行回数。多いほど安定するが遅い'],
                        ['max_iter', '各試行での最大反復回数'],
                    ],
                },
                {
                    method: 'Agglomerative',
                    desc: '階層的クラスタリング。近いもの同士をボトムアップに結合していき、指定クラスタ数で切る。ward linkage を使用、距離は euclidean（L2 正規化により cosine 相当）。',
                    params: [
                        ['n_clusters', '最終的な切り出しクラスタ数'],
                        ['linkage', '結合基準。ward は分散最小化（デフォルト）、average は平均距離、single は最小距離'],
                    ],
                },
                {
                    method: 'Leiden (HNSW + Leiden)',
                    desc: 'HNSW で近傍グラフを構築し、Leiden アルゴリズムでコミュニティを検出するグラフベース手法。高次元埋め込みで自然なクラスタを見つけやすい。HNSW インデックスで cosine 距離を直接使用。',
                    params: [
                        ['n_neighbors', '各点の近傍として考える数。小さいと細かく、大きいと粗く分かれる'],
                        ['resolution', 'コミュニティの粒度パラメータ。大きいほどクラスタ数が増える'],
                        ['ef_construction', 'HNSW インデックス構築時の探索広さ。大きいほど高精度だが構築が遅い'],
                        ['M', 'HNSW の各点の近傍接続数。グラフ品質とメモリのトレードオフ'],
                    ],
                },
            ];

            const S = _PDF_TABLE_STYLES;
            const wrap = document.createElement('div');
            wrap.style.cssText = S.container + 'color:#6b6b70;';
            let html = '<h3 style="' + S.mutedHeading + '">専門用語の説明 / Glossary</h3>';
            html += '<table style="' + S.mutedTable + '">'
                + '<thead><tr style="' + S.mutedTheadRow + '">'
                + '<th style="' + S.mutedTheadCell + 'width:18%;">手法 / Method</th>'
                + '<th style="' + S.mutedTheadCell + '">説明 / Description &amp; Parameters</th>'
                + '</tr></thead><tbody>';
            entries.forEach(e => {
                let paramsHtml = '';
                if (e.params.length) {
                    paramsHtml = '<div style="margin-top:4px;padding-left:8px;">';
                    e.params.forEach(([name, desc]) => {
                        paramsHtml += '<div style="margin-bottom:2px;"><code style="font-family:monospace;color:#333;">' + name + '</code>: <span style="color:#6b6b70;">' + desc + '</span></div>';
                    });
                    paramsHtml += '</div>';
                }
                html += '<tr>'
                    + '<td style="' + S.bodyCell + 'font-weight:500;color:#555;">' + e.method + '</td>'
                    + '<td style="' + S.bodyCell + '">'
                    + '<span style="color:#555;">' + e.desc + '</span>'
                    + paramsHtml
                    + '</td></tr>';
            });
            html += '</tbody><tfoot><tr><td colspan="2" style="' + S.footerCell + '"></td></tr></tfoot></table>';
            wrap.innerHTML = html;
            return wrap;
        }

        /**
         * Render the top-N parameter-search results for the PDF export.
         * Columns: # (rank), method, params, clusters, silhouette, noise,
         * size (sample_size shared by all rows; useful as denominator for
         * the clusters/noise columns).
         *
         * Limited to top 10 by silhouette so the table fits comfortably
         * on the same page as the chart instead of paginating across
         * pages and obscuring the chart capture.
         *
         * Styled in US-accounting convention (no vertical lines, only
         * thin horizontal rules around the header and below the body).
         * Returned as a detached DOM node that the PDF exporter mounts
         * into the document briefly during capture.
         */
        function _renderParamAllResultsHtml(results, sampleSize) {
            const S = _PDF_TABLE_STYLES;
            const top = results.slice(0, 10);
            const wrap = document.createElement('div');
            wrap.style.cssText = S.container;
            let html = '<h3 style="' + S.heading + '">'
                + '{{ __("ui.parameter_search_results") }} (Top ' + top.length
                + ' / ' + results.length + ' tested)</h3>';
            html += '<table style="' + S.table + '">'
                + '<thead><tr style="' + S.theadRow + '">'
                + '<th style="' + S.theadCellNum + 'width:5%;">#</th>'
                + '<th style="' + S.theadCell + 'width:24%;">{{ __("ui.method") }}</th>'
                + '<th style="' + S.theadCell + '">{{ __("ui.parameters") }}</th>'
                + '<th style="' + S.theadCellNum + 'width:9%;">{{ __("ui.clusters") }}</th>'
                + '<th style="' + S.theadCellNum + 'width:11%;">{{ __("ui.silhouette") }}</th>'
                + '<th style="' + S.theadCellNum + 'width:8%;">{{ __("ui.noise") }}</th>'
                + '<th style="' + S.theadCellNum + 'width:9%;">{{ __("ui.sampled") }}</th>'
                + '</tr></thead><tbody>';
            top.forEach((r, i) => {
                const paramStr = Object.entries(r.params || {}).map(([k,v]) => k + '=' + v).join(', ');
                // PDF runs on white paper, so the grey fallback needs more
                // contrast than the on-screen variant (#555 → #1d1d1f).
                const silColor = silhouetteColor(r.silhouette_score, '#1d1d1f');
                html += '<tr>'
                    + '<td style="' + S.bodyCellNum + 'color:#5f6368;">' + (i + 1) + '</td>'
                    + '<td style="' + S.bodyCell + 'font-weight:500;">' + (r.label || r.method) + '</td>'
                    + '<td style="' + S.bodyCell + 'font-size:9.5px;color:#5f6368;">' + paramStr + '</td>'
                    + '<td style="' + S.bodyCellNum + '">' + r.n_clusters + '</td>'
                    + '<td style="' + S.bodyCellNum + 'font-weight:600;color:' + silColor + ';">' + (r.silhouette_score != null ? r.silhouette_score.toFixed(3) : '—') + '</td>'
                    + '<td style="' + S.bodyCellNum + 'color:#5f6368;">' + (r.n_noise != null ? r.n_noise : '—') + '</td>'
                    + '<td style="' + S.bodyCellNum + 'color:#5f6368;">' + (sampleSize != null ? sampleSize : '—') + '</td>'
                    + '</tr>';
            });
            html += '</tbody><tfoot><tr><td colspan="7" style="' + S.footerCell + '"></td></tr></tfoot></table>';
            wrap.innerHTML = html;
            return wrap;
        }

        /**
         * Disable (or re-enable) the recluster and parameter-search buttons
         * while a parameter_search job is running. Keeps their visible state
         * in sync without a page reload.
         */
        function setParamSearchRunningState(running) {
            const btns = [
                document.getElementById('recluster-toggle'),
                document.getElementById('param-search-btn'),
            ];
            btns.forEach(b => {
                if (!b) return;
                b.disabled = !!running;
                b.style.opacity = running ? '0.55' : '';
                b.style.cursor = running ? 'not-allowed' : '';
                if (running) {
                    b.setAttribute('title', '{{ __("ui.parameter_search_running") }}');
                } else {
                    b.removeAttribute('title');
                }
            });
        }

        /** When user clicks a chart bar or "use these params" button:
         *  open the recluster form and pre-fill the method + params */
        function selectParamResult(idx) {
            applyParamResult(idx);
        }
        function applyParamResult(idx) {
            const r = (window._paramSearchResults || [])[idx];
            if (!r) return;

            // Open the recluster form
            const form = document.getElementById('recluster-form');
            const chevron = document.getElementById('recluster-chevron');
            if (form) form.style.display = 'block';
            if (chevron) chevron.style.transform = 'rotate(90deg)';

            // Set method
            const methodSelect = document.getElementById('rc-method');
            if (methodSelect) { methodSelect.value = r.method; toggleRcParams(); }

            // Set params based on method
            const params = r.params || {};
            if (r.method === 'leiden') {
                const nb = document.querySelector('#rc-params-leiden input[name="leiden_n_neighbors"]');
                const res = document.querySelector('#rc-params-leiden input[name="leiden_resolution"]');
                if (nb) nb.value = params.n_neighbors || params.leiden_n_neighbors || 15;
                if (res) res.value = params.resolution || params.leiden_resolution || 1.0;
            } else if (r.method === 'hdbscan') {
                const mcs = document.querySelector('#rc-params-hdbscan input[name="hdbscan_min_cluster_size"]');
                const ms = document.querySelector('#rc-params-hdbscan input[name="hdbscan_min_samples"]');
                if (mcs) mcs.value = params.min_cluster_size || 15;
                if (ms) ms.value = params.min_samples || 5;
            } else if (r.method === 'kmeans') {
                const nc = document.querySelector('#rc-params-kmeans input[name="kmeans_n_clusters"]');
                if (nc) nc.value = params.n_clusters || 10;
            } else if (r.method === 'agglomerative') {
                const nc = document.querySelector('#rc-params-agglomerative input[name="agglomerative_n_clusters"]');
                if (nc) nc.value = params.n_clusters || 10;
            }

            // Scroll to the form
            form?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        // Start polling if page loaded with a recent parameter search
        @if($current)
        pollParameterSearch();
        @endif

        /** Dataset rename (comparison view header) */
        function startDsRename() {
            const title = document.getElementById('ds-title');
            const form = document.getElementById('ds-rename-form');
            if (title) title.style.display = 'none';
            if (title) title.nextElementSibling.style.display = 'none'; // pen button
            if (form) { form.style.display = 'block'; document.getElementById('ds-rename-input').focus(); document.getElementById('ds-rename-input').select(); }
        }
        function cancelDsRename() {
            const title = document.getElementById('ds-title');
            const form = document.getElementById('ds-rename-form');
            if (title) title.style.display = '';
            if (title) title.nextElementSibling.style.display = '';
            if (form) form.style.display = 'none';
        }

        // Chat overlay — session state tracks extracted primary_filter/question
        let chatContext = { primary_filter: null, question: null };
        let chatSessionId = null;   // current chat_sessions.id (null = new session)
        let chatSending = false;

        function openChatOverlay() {
            document.getElementById('chat-overlay').style.display = 'flex';
            document.getElementById('chat-input').focus();
        }

        function closeChatOverlay() {
            document.getElementById('chat-overlay').style.display = 'none';
        }

        // Markdown to HTML converter for chat responses — line-by-line processing
        function renderMarkdown(text) {
            const esc = s => s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
            const inline = s => s
                .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                .replace(/\*(.+?)\*/g, '<em>$1</em>')
                .replace(/`(.+?)`/g, '<code style="background:#e8e8e8;padding:1px 4px;border-radius:3px;font-size:12px;">$1</code>');

            const lines = text.split('\n');
            let html = '';
            let inList = false;

            for (let i = 0; i < lines.length; i++) {
                let line = esc(lines[i]);

                // Headings (must check ### before ##)
                if (/^#{3}\s+(.+)/.test(line)) {
                    if (inList) { html += '</div>'; inList = false; }
                    html += '<div style="font-weight:600;margin:10px 0 4px;">' + inline(line.replace(/^#{3}\s+/, '')) + '</div>';
                    continue;
                }
                if (/^#{2}\s+(.+)/.test(line)) {
                    if (inList) { html += '</div>'; inList = false; }
                    html += '<div style="font-weight:600;font-size:15px;margin:12px 0 4px;">' + inline(line.replace(/^#{2}\s+/, '')) + '</div>';
                    continue;
                }

                // Bullet list items (with indent support)
                const bulletMatch = line.match(/^(\s*)[-*]\s+(.+)/);
                if (bulletMatch) {
                    if (!inList) { html += '<div style="margin:4px 0;">'; inList = true; }
                    const indent = Math.floor((bulletMatch[1] || '').length / 2) * 16 + 8;
                    html += '<div style="padding-left:' + indent + 'px;">• ' + inline(bulletMatch[2]) + '</div>';
                    continue;
                }

                // Numbered list items
                const numMatch = line.match(/^(\s*)(\d+)\.\s+(.+)/);
                if (numMatch) {
                    if (!inList) { html += '<div style="margin:4px 0;">'; inList = true; }
                    const indent = Math.floor((numMatch[1] || '').length / 2) * 16 + 8;
                    html += '<div style="padding-left:' + indent + 'px;">' + numMatch[2] + '. ' + inline(numMatch[3]) + '</div>';
                    continue;
                }

                // Close list if we hit a non-list line
                if (inList) { html += '</div>'; inList = false; }

                // Empty line = paragraph break
                if (line.trim() === '') {
                    html += '<div style="height:8px;"></div>';
                    continue;
                }

                // Normal paragraph
                html += '<div>' + inline(line) + '</div>';
            }
            if (inList) html += '</div>';
            return html;
        }

        function clearChat() {
            chatContext = { primary_filter: null, question: null };
            chatSessionId = null;
            const container = document.getElementById('chat-messages');
            container.innerHTML = '<div style="text-align: center; color: #5f6368; font-size: 12px; padding: 20px 0;">{{ __("ui.chat_placeholder") }}</div>';
            // Clear the context indicator bar
            const indicator = document.getElementById('context-indicator');
            if (indicator) {
                indicator.textContent = '';
                indicator.style.display = 'none';
            }
        }

        // Fallback UI: shown when the knowledge base has no matching answer.
        // Displays a dedicated card with an icon, message, and a reset button.
        function appendFallbackMessage() {
            const container = document.getElementById('chat-messages');
            const wrapper = document.createElement('div');
            wrapper.style.cssText = 'align-self: flex-start; max-width: 85%;';
            wrapper.innerHTML = `
                <div style="background: #FFF8E1; border: 1px solid #FFE082; border-radius: 12px; padding: 12px 16px; display: flex; gap: 10px; align-items: flex-start;">
                    <span style="font-size: 20px; flex-shrink: 0;">🔍</span>
                    <div>
                        <div style="font-size: 13px; font-weight: 600; color: #5D4037; margin-bottom: 4px;">{{ __("ui.chat_no_match_title") }}</div>
                        <div style="font-size: 12px; color: #795548; line-height: 1.5;">{{ __("ui.chat_no_match_body") }}</div>
                        <button onclick="clearChat()" style="margin-top: 8px; font-size: 12px; color: #0071e3; background: none; border: none; cursor: pointer; padding: 0; text-decoration: underline;">
                            {{ __("ui.chat_no_match_reset") }}
                        </button>
                    </div>
                </div>`;
            container.appendChild(wrapper);
            container.scrollTop = container.scrollHeight;
        }

        function appendChatMessage(role, content, meta) {
            const container = document.getElementById('chat-messages');

            // Remove placeholder if present
            const placeholder = container.querySelector('[style*="text-align: center"]');
            if (placeholder && role === 'user') placeholder.remove();

            const isUser = role === 'user';

            // Wrapper: holds bubble + copy button in a row
            const wrapper = document.createElement('div');
            wrapper.style.cssText = `
                display: flex; align-items: flex-start; gap: 4px;
                align-self: ${isUser ? 'flex-end' : 'flex-start'};
                max-width: 85%; position: relative;
            `;
            wrapper.addEventListener('mouseenter', () => copyBtn.style.opacity = '1');
            wrapper.addEventListener('mouseleave', () => copyBtn.style.opacity = '0');

            // Copy button
            const copyBtn = document.createElement('button');
            copyBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>';
            copyBtn.style.cssText = `
                background: none; border: none; cursor: pointer; padding: 4px;
                color: #aaa; opacity: 0; transition: opacity 0.15s;
                flex-shrink: 0; margin-top: 6px;
            `;
            copyBtn.title = '{{ __("ui.copy") }}';
            copyBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                navigator.clipboard.writeText(content).then(() => {
                    copyBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>';
                    setTimeout(() => {
                        copyBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>';
                    }, 1500);
                });
            });

            // Bubble
            const bubble = document.createElement('div');
            bubble.style.cssText = `
                background: ${isUser ? '#0071e3' : '#F6F6F6'};
                color: ${isUser ? '#fff' : '#1d1d1f'};
                padding: 10px 14px; border-radius: ${isUser ? '16px 16px 4px 16px' : '16px 16px 16px 4px'};
                font-size: 14px; line-height: 1.5; word-wrap: break-word; flex: 1; min-width: 0;
            `;
            if (isUser) {
                bubble.textContent = content;
            } else {
                bubble.innerHTML = renderMarkdown(content);
            }

            // User: copy button on left, bubble on right
            // Assistant: bubble on left, copy button on right
            if (isUser) {
                wrapper.appendChild(copyBtn);
                wrapper.appendChild(bubble);
            } else {
                wrapper.appendChild(bubble);
                wrapper.appendChild(copyBtn);
            }

            container.appendChild(wrapper);

            // Show sources as compact inline chips for assistant messages
            if (!isUser && meta) {
                if (meta.sources && meta.sources.length > 0) {
                    const sourcesDiv = document.createElement('div');
                    sourcesDiv.style.cssText = 'align-self: flex-start; max-width: 85%; margin-top: 2px;';
                    const chips = document.createElement('div');
                    chips.style.cssText = 'display: flex; flex-wrap: wrap; gap: 4px; align-items: center;';
                    // Small label before chips
                    const label = document.createElement('span');
                    label.style.cssText = 'font-size: 10px; color: #aaa; margin-right: 2px;';
                    label.textContent = '{{ __("ui.sources") }}';
                    chips.appendChild(label);
                    meta.sources.forEach(s => {
                        const chip = document.createElement('a');
                        chip.href = '/knowledge-units/' + s.id;
                        chip.target = '_blank';
                        chip.rel = 'noopener';
                        chip.title = s.topic + (s.intent ? ' — ' + s.intent : '');
                        const pct = Math.round(s.similarity * 100);
                        const badgeColor = pct >= 70 ? '#34a853' : pct >= 40 ? '#fbbc05' : '#999';
                        chip.style.cssText = `display: inline-flex; align-items: center; gap: 3px; background: #f0f0f0; border-radius: 4px; padding: 1px 6px; text-decoration: none; color: #444; font-size: 11px; line-height: 18px; transition: background 0.15s; max-width: 180px;`;
                        chip.addEventListener('mouseenter', () => chip.style.background = '#e4e4e4');
                        chip.addEventListener('mouseleave', () => chip.style.background = '#f0f0f0');
                        // Similarity percentage
                        const pctSpan = document.createElement('span');
                        pctSpan.style.cssText = `font-size: 10px; font-weight: 600; color: ${badgeColor};`;
                        pctSpan.textContent = pct + '%';
                        chip.appendChild(pctSpan);
                        // Truncated topic
                        const topicSpan = document.createElement('span');
                        topicSpan.style.cssText = 'overflow: hidden; text-overflow: ellipsis; white-space: nowrap;';
                        topicSpan.textContent = s.topic;
                        chip.appendChild(topicSpan);
                        chips.appendChild(chip);
                    });
                    sourcesDiv.appendChild(chips);
                    container.appendChild(sourcesDiv);
                }
                // Feedback row: meta info + upvote/downvote buttons
                const feedbackRow = document.createElement('div');
                feedbackRow.style.cssText = 'align-self: flex-start; font-size: 10px; color: #aaa; padding: 0 4px 4px; display: flex; align-items: center; gap: 8px;';

                if (meta.latency_ms || meta.usage) {
                    const parts = [];
                    if (meta.latency_ms) parts.push(meta.latency_ms + 'ms');
                    if (meta.usage) parts.push((meta.usage.input_tokens + meta.usage.output_tokens) + ' tokens');
                    if (meta.model) parts.push(meta.model.split('.').pop().split(':')[0]);
                    const metaSpan = document.createElement('span');
                    metaSpan.textContent = parts.join(' · ');
                    feedbackRow.appendChild(metaSpan);
                }

                // Upvote/downvote buttons
                const voteContainer = document.createElement('span');
                voteContainer.style.cssText = 'display: inline-flex; gap: 2px; margin-left: 4px;';

                const makeVoteBtn = (type) => {
                    const btn = document.createElement('button');
                    btn.innerHTML = type === 'up'
                        ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 9V5a3 3 0 00-6 0v4H5l7-7 7 7h-5z"/><path d="M5 9v10a2 2 0 002 2h10a2 2 0 002-2V9"/></svg>'
                        : '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 15v4a3 3 0 006 0v-4h3l-7 7-7-7h5z"/><path d="M19 15V5a2 2 0 00-2-2H7a2 2 0 00-2 2v10"/></svg>';
                    btn.style.cssText = 'background: none; border: none; cursor: pointer; padding: 2px; color: #ccc; transition: color 0.15s;';
                    btn.title = type === 'up' ? '{{ __("ui.helpful") }}' : '{{ __("ui.not_helpful") }}';
                    btn.addEventListener('mouseenter', () => btn.style.color = type === 'up' ? '#34a853' : '#ea4335');
                    btn.addEventListener('mouseleave', () => { if (!btn.dataset.voted) btn.style.color = '#ccc'; });
                    btn.addEventListener('click', () => {
                        if (btn.dataset.voted) return;
                        btn.dataset.voted = '1';
                        btn.style.color = type === 'up' ? '#34a853' : '#ea4335';
                        // Disable the other button
                        const sibling = type === 'up' ? btn.nextElementSibling : btn.previousElementSibling;
                        if (sibling) { sibling.style.opacity = '0.3'; sibling.style.pointerEvents = 'none'; }
                        // Send feedback to server
                        fetch('{{ url("/workspace") }}/' + {{ $current ? $current->id : 0 }} + '/chat-feedback', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                            body: JSON.stringify({
                                vote: type,
                                question: chatContext.question,
                                answer: content,
                                source_ku_ids: meta.source_ku_ids || [],
                            }),
                        });
                    });
                    return btn;
                };
                voteContainer.appendChild(makeVoteBtn('up'));
                voteContainer.appendChild(makeVoteBtn('down'));
                feedbackRow.appendChild(voteContainer);
                container.appendChild(feedbackRow);
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
            sendBtn.style.opacity = '0.5';

            // Show user message
            appendChatMessage('user', message);

            // Show typing indicator with generic thinking message
            const container = document.getElementById('chat-messages');
            const typing = document.createElement('div');
            typing.id = 'typing-indicator';
            typing.style.cssText = 'align-self: flex-start; background: #F6F6F6; padding: 10px 14px; border-radius: 16px 16px 16px 4px; font-size: 14px; color: #5f6368;';
            typing.textContent = '{{ __("ui.thinking") }}';
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
                        message:    message,
                        context:    chatContext,
                        session_id: chatSessionId,
                    }),
                });

                const data = await response.json();

                // Remove typing indicator
                document.getElementById('typing-indicator')?.remove();

                // Update session context and history session ID from server response
                if (data.context) {
                    chatContext = data.context;
                    updateContextIndicator();
                }
                if (data.session_id) {
                    chatSessionId = data.session_id;
                }

                if (data.error) {
                    appendChatMessage('assistant', 'Error: ' + data.error);
                } else if (data.action === 'rejected') {
                    // Input gate triggered: show joke with warning icon, clear context
                    chatContext = { primary_filter: null, question: null };
                    updateContextIndicator();
                    appendChatMessage('assistant', '⚠️ ' + data.message);
                } else if (data.action === 'no_match') {
                    // No knowledge found — show dedicated fallback UI
                    appendFallbackMessage();
                } else {
                    // Add note for broad/reference results
                    let responseMessage = data.message;
                    if (data.action === 'answer_broad') {
                        responseMessage = '⚠️ {{ __("ui.chat_broad_match") }}\n\n' + responseMessage;
                    }
                    appendChatMessage('assistant', responseMessage, {
                        sources: data.sources,
                        source_ku_ids: data.source_ku_ids,
                        latency_ms: data.latency_ms,
                        usage: data.usage,
                        model: data.model,
                    });
                }
                @endif
            } catch (err) {
                document.getElementById('typing-indicator')?.remove();
                appendChatMessage('assistant', 'Network error: ' + err.message);
            }

            chatSending = false;
            sendBtn.disabled = false;
            sendBtn.style.opacity = '1';
            input.focus();
        }

        // Toggle the history panel visibility and load sessions on first open.
        // The input area is hidden while the history panel is open to prevent
        // sending messages that would go unnoticed behind the history view.
        let historyLoaded = false;
        function toggleHistoryPanel() {
            const panel = document.getElementById('chat-history-panel');
            const messages = document.getElementById('chat-messages');
            const inputArea = document.getElementById('chat-input-area');
            const isOpen = panel.style.display !== 'none';
            if (isOpen) {
                panel.style.display = 'none';
                messages.style.display = 'flex';
                inputArea.style.display = 'block';
            } else {
                panel.style.display = 'flex';
                messages.style.display = 'none';
                inputArea.style.display = 'none';
                if (!historyLoaded) {
                    loadChatHistory();
                    historyLoaded = true;
                }
            }
        }

        // Load and render the list of past chat sessions for this embedding.
        async function loadChatHistory() {
            const list = document.getElementById('chat-history-list');
            @if($current)
            try {
                const resp = await fetch('{{ url("/workspace") }}/{{ $current->id }}/chat-sessions', {
                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                });
                const sessions = await resp.json();
                if (!sessions.length) {
                    list.innerHTML = '<div style="text-align:center;color:#aaa;font-size:12px;padding:20px;">{{ __("ui.chat_history_empty") }}</div>';
                    return;
                }
                list.innerHTML = '';
                sessions.forEach(s => {
                    const item = document.createElement('button');
                    item.style.cssText = 'display: block; width: 100%; text-align: left; background: none; border: none; cursor: pointer; padding: 8px 10px; border-radius: 8px; margin-bottom: 2px; transition: background 0.15s;';
                    item.addEventListener('mouseenter', () => item.style.background = '#F0F0F0');
                    item.addEventListener('mouseleave', () => item.style.background = 'none');
                    const title = document.createElement('div');
                    title.style.cssText = 'font-size: 13px; color: #1d1d1f; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;';
                    title.textContent = s.title || '{{ __("ui.chat_history_untitled") }}';
                    const date = document.createElement('div');
                    date.style.cssText = 'font-size: 11px; color: #aaa; margin-top: 2px;';
                    date.textContent = new Date(s.updated_at).toLocaleString();
                    item.appendChild(title);
                    item.appendChild(date);
                    item.addEventListener('click', () => loadSession(s.id));
                    list.appendChild(item);
                });
            } catch (e) {
                list.innerHTML = '<div style="text-align:center;color:#ea4335;font-size:12px;padding:20px;">{{ __("ui.failed_load_history") }}</div>';
            }
            @endif
        }

        // Load and replay a specific session into the chat messages area.
        async function loadSession(sessionId) {
            @if($current)
            try {
                const resp = await fetch('{{ url("/workspace") }}/{{ $current->id }}/chat-sessions/' + sessionId, {
                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                });
                const data = await resp.json();
                // Close history panel, show messages
                document.getElementById('chat-history-panel').style.display = 'none';
                document.getElementById('chat-messages').style.display = 'flex';
                clearChat();
                chatSessionId = sessionId;
                // Replay turns
                data.turns.forEach(turn => {
                    if (turn.role === 'user') {
                        appendChatMessage('user', turn.content);
                    } else {
                        appendChatMessage('assistant', turn.content, {
                            sources: turn.sources || [],
                        });
                    }
                });
                // Restore context from last assistant turn
                const lastAssistant = [...data.turns].reverse().find(t => t.role === 'assistant');
                if (lastAssistant?.context) {
                    chatContext = lastAssistant.context;
                    updateContextIndicator();
                }
            } catch (e) {
                appendChatMessage('assistant', '{{ __("ui.failed_load_session") }}' + e.message);
            }
            @endif
        }

        // Show extracted context (product/question) as indicator in chat header
        function updateContextIndicator() {
            let indicator = document.getElementById('context-indicator');
            if (!indicator) {
                const header = document.querySelector('#chat-overlay > div > div:first-child');
                if (!header) return;
                indicator = document.createElement('div');
                indicator.id = 'context-indicator';
                indicator.style.cssText = 'font-size: 11px; color: #aaa; padding: 0 12px 4px; background: #1d1d1f;';
                header.after(indicator);
            }
            const parts = [];
            if (chatContext.primary_filter) parts.push('📦 ' + chatContext.primary_filter);
            if (chatContext.question) parts.push('❓ ' + chatContext.question.substring(0, 40) + (chatContext.question.length > 40 ? '...' : ''));
            indicator.textContent = parts.join('  ');
            indicator.style.display = parts.length ? 'block' : 'none';
        }

        // Dispatch modal open/close
        function showUploadOverlay() {
            document.getElementById('upload-overlay').style.display = 'flex';
        }

        function openDispatchModal() {
            // Reset file input so previous selection doesn't persist
            const fileInput = document.querySelector('#dispatch-modal input[type="file"]');
            if (fileInput) fileInput.value = '';
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

        // Auto-refresh sidebar tree and job list while pipeline jobs are in progress
        let pollingActive = {{ $jobStats['processing'] > 0 ? 'true' : 'false' }};
        async function refreshWorkspace() {
            try {
                const response = await fetch(window.location.href);
                if (!response.ok) return;
                const html = await response.text();
                const doc = new DOMParser().parseFromString(html, 'text/html');

                // Refresh dataset tree (shows new embeddings when pipeline completes)
                const freshTree = doc.getElementById('dataset-tree');
                const currentTree = document.getElementById('dataset-tree');
                if (freshTree && currentTree) currentTree.innerHTML = freshTree.innerHTML;

                // Refresh pipeline sidebar counts
                const freshSidebar = doc.getElementById('pipeline-sidebar');
                const currentSidebar = document.getElementById('pipeline-sidebar');
                if (freshSidebar && currentSidebar) currentSidebar.innerHTML = freshSidebar.innerHTML;

                // Refresh job list if visible
                const freshJobs = doc.getElementById('job-list');
                const currentJobs = document.getElementById('job-list');
                if (freshJobs && currentJobs) currentJobs.innerHTML = freshJobs.innerHTML;

                // Refresh embedding-detail progress panel (progress bar,
                // current step label, %, and worker heartbeat). Without this
                // swap the panel is frozen at the values present when the
                // page first rendered, so the user sees a static percentage
                // and a spinning hourglass that never advances.
                const freshProgress = doc.getElementById('embedding-progress-panel');
                const currentProgress = document.getElementById('embedding-progress-panel');
                if (freshProgress && currentProgress) currentProgress.innerHTML = freshProgress.innerHTML;

                localizeTimestamps();

                // When all processing jobs have finished, reload the full page
                // so comparison view, sidebar, and KU lists show the latest results.
                const processingBadge = doc.querySelector('[data-processing-count]');
                const processingCount = processingBadge ? parseInt(processingBadge.dataset.processingCount) : 0;
                if (processingCount === 0 && pollingActive) {
                    pollingActive = false;
                    window.location.reload();
                }
            } catch (e) { }
        }
        // Poll every 2.5s so the current_action heartbeat from the worker
        // feels continuous. Previous 5s interval was wide enough that users
        // assumed the pipeline had stalled.
        const pollingInterval = setInterval(() => {
            if (pollingActive) refreshWorkspace();
        }, 2500);
        @if($jobStats['processing'] > 0)
        refreshWorkspace();
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

        // ── Parameter Search → PDF report export ─────────────────────────
        // Captures the existing chart panel + top-results table as images,
        // assembles them into an A4 PDF, and triggers a download. Uses
        // jsPDF + html2canvas loaded lazily from CDN on first click so the
        // main page load doesn't pay for it.
        function _loadScriptOnce(src) {
            // Avoid adding the same <script src> twice if the user clicks
            // the PDF button more than once; resolve immediately when it's
            // already in the DOM.
            return new Promise((resolve, reject) => {
                if (document.querySelector('script[data-lazy-src="' + src + '"]')) {
                    resolve();
                    return;
                }
                const s = document.createElement('script');
                s.src = src;
                s.dataset.lazySrc = src;
                s.onload = () => resolve();
                s.onerror = () => reject(new Error('failed to load ' + src));
                document.head.appendChild(s);
            });
        }

        async function exportParamSearchPdf() {
            const btn = document.getElementById('param-search-pdf');
            const body = document.getElementById('param-search-body');
            const chartWrap = document.getElementById('param-search-results');
            const toggle = document.getElementById('param-results-toggle');
            if (!chartWrap) return;

            // The chart + table live inside the collapsible body. If the user
            // kept it collapsed, we have to open it so html2canvas has a
            // laid-out DOM to capture (display:none produces a blank canvas).
            const wasCollapsed = body && body.style.display === 'none';
            if (wasCollapsed) {
                body.style.display = '';
                document.getElementById('param-results-chevron').style.transform = 'rotate(90deg)';
                // Let the browser lay out + SVG line overlay re-draw before capture.
                await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)));
                if (typeof drawParamLine === 'function') drawParamLine();
                await new Promise(r => setTimeout(r, 150));
            }

            if (btn) { btn.disabled = true; btn.textContent = '⏳ ...'; }
            try {
                await _loadScriptOnce('https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js');
                await _loadScriptOnce('https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js');

                const jsPDF = window.jspdf ? window.jspdf.jsPDF : window.jsPDF;
                if (!jsPDF || typeof window.html2canvas !== 'function') {
                    throw new Error('PDF libraries failed to initialise');
                }

                // A4 portrait in mm; allocate a 14mm margin on every side.
                const doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
                const pageW = doc.internal.pageSize.getWidth();
                const margin = 14;
                const usableW = pageW - margin * 2;

                // Title block — dataset name + timestamp for traceability.
                const datasetName = @json($current?->dataset?->name ?? $current?->name ?? 'dataset');
                const now = new Date();
                const pad = n => String(n).padStart(2, '0');
                const stamp = now.getFullYear() + pad(now.getMonth() + 1) + pad(now.getDate())
                            + '-' + pad(now.getHours()) + pad(now.getMinutes());

                doc.setFontSize(14);
                doc.text('Parameter Search Report', margin, margin + 2);
                doc.setFontSize(10);
                doc.setTextColor(90);
                doc.text(datasetName + '  /  ' + now.toLocaleString(), margin, margin + 8);
                doc.setTextColor(0);

                let cursorY = margin + 14;

                // Capture only the chart region (not the whole body). We avoid
                // capturing the table-top so it doesn't show up as a blurred
                // raster alongside the full-size 24-row table below. Using
                // the dedicated wrapper gives us Y-axis title + gridlines +
                // bars + SVG line overlay + x-axis labels in one shot.
                const chartEl = document.getElementById('param-search-chart-wrap')
                              ?? document.getElementById('param-search-chart');
                const chartCanvas = await window.html2canvas(chartEl, {
                    scale: 2, backgroundColor: '#ffffff', logging: false,
                    // Strip screen-only controls ("use these params", dismiss)
                    // from the clone so the rasterised chart doesn't include
                    // buttons that don't belong in the report.
                    onclone: (doc) => {
                        doc.querySelectorAll('.pdf-hide').forEach(el => { el.style.display = 'none'; });
                    },
                });
                // Fit to usable width, preserve aspect ratio, cap height so
                // the chart doesn't squeeze the first table page.
                const chartImg = chartCanvas.toDataURL('image/png');
                const chartAspect = chartCanvas.height / chartCanvas.width;
                const chartH = Math.min(usableW * chartAspect, 120);
                doc.addImage(chartImg, 'PNG', margin, cursorY, usableW, chartH);
                cursorY += chartH + 6;

                // Build a dedicated "all 24 results" table off-screen, capture
                // it, and paginate across PDF pages as needed. This replaces
                // the on-screen top-5 list for the report.
                const sampleSize = window._paramSearchSampleSize;
                const allResults = window._paramSearchResults || [];
                if (allResults.length) {
                    const allTableEl = _renderParamAllResultsHtml(allResults, sampleSize);
                    // Render off-screen but inside the layout flow so real
                    // CSS applies (fixed width ensures predictable aspect).
                    allTableEl.style.position = 'fixed';
                    allTableEl.style.top = '-10000px';
                    allTableEl.style.left = '0';
                    allTableEl.style.width = '700px';
                    document.body.appendChild(allTableEl);
                    try {
                        await new Promise(r => requestAnimationFrame(r));
                        const tableCanvas = await window.html2canvas(allTableEl, {
                            scale: 2, backgroundColor: '#ffffff', logging: false,
                        });
                        const tableImg = tableCanvas.toDataURL('image/png');
                        const tableAspect = tableCanvas.height / tableCanvas.width;
                        const tableFullH = usableW * tableAspect;
                        const pageH = doc.internal.pageSize.getHeight();
                        const availableFirstPage = pageH - cursorY - margin;
                        // If the table fits on the same page as the chart, just add it.
                        // Otherwise start a new page and paginate by slicing the source
                        // canvas vertically into page-height chunks.
                        if (tableFullH <= availableFirstPage) {
                            // Common case (top-10 fits comfortably alongside chart)
                            doc.addImage(tableImg, 'PNG', margin, cursorY, usableW, tableFullH);
                            cursorY += tableFullH + 6;
                        } else {
                            // Table is too big for the remaining space on the
                            // current page. Always start the table on a fresh
                            // page (otherwise the first slice draws at margin,
                            // margin and overwrites the chart we just placed).
                            doc.addPage();
                            cursorY = margin;
                            const pagePixelsPerMm = tableCanvas.width / usableW;
                            const pageBudgetMm = pageH - margin * 2;
                            const pageBudgetPx = pageBudgetMm * pagePixelsPerMm;
                            let srcY = 0;
                            let first = true;
                            while (srcY < tableCanvas.height) {
                                const sliceH = Math.min(pageBudgetPx, tableCanvas.height - srcY);
                                const slice = document.createElement('canvas');
                                slice.width = tableCanvas.width;
                                slice.height = sliceH;
                                slice.getContext('2d').drawImage(
                                    tableCanvas, 0, srcY, tableCanvas.width, sliceH,
                                    0, 0, tableCanvas.width, sliceH,
                                );
                                const sliceMmH = sliceH / pagePixelsPerMm;
                                // First iteration uses the page we just added;
                                // subsequent iterations need their own pages.
                                if (!first) doc.addPage();
                                doc.addImage(slice.toDataURL('image/png'), 'PNG', margin, margin, usableW, sliceMmH);
                                srcY += sliceH;
                                first = false;
                                cursorY = margin + sliceMmH + 6;
                            }
                        }
                    } finally {
                        document.body.removeChild(allTableEl);
                    }
                }

                // Glossary: method + parameter explanations. Kept visually
                // muted (grey palette, small font) because it's reference
                // material, not a finding. Rendered on a fresh page so the
                // main results read cleanly.
                const glossaryEl = _renderParamGlossaryHtml();
                glossaryEl.style.position = 'fixed';
                glossaryEl.style.top = '-10000px';
                glossaryEl.style.left = '0';
                glossaryEl.style.width = '700px';
                document.body.appendChild(glossaryEl);
                try {
                    await new Promise(r => requestAnimationFrame(r));
                    const glossCanvas = await window.html2canvas(glossaryEl, {
                        scale: 2, backgroundColor: '#ffffff', logging: false,
                    });
                    const glossImg = glossCanvas.toDataURL('image/png');
                    const glossAspect = glossCanvas.height / glossCanvas.width;
                    const glossH = usableW * glossAspect;
                    const pageH = doc.internal.pageSize.getHeight();
                    const remainingOnPage = pageH - cursorY - margin;
                    if (glossH > remainingOnPage) {
                        doc.addPage();
                        doc.addImage(glossImg, 'PNG', margin, margin, usableW, glossH);
                    } else {
                        doc.addImage(glossImg, 'PNG', margin, cursorY, usableW, glossH);
                    }
                } finally {
                    document.body.removeChild(glossaryEl);
                }

                // File name: param-search-<slug>_<YYYYMMDD-HHMM>.pdf
                const slug = datasetName.toString()
                    .toLowerCase()
                    .replace(/[^a-z0-9]+/g, '-')
                    .replace(/^-+|-+$/g, '')
                    .slice(0, 60) || 'dataset';
                doc.save('param-search-' + slug + '_' + stamp + '.pdf');
            } catch (e) {
                console.error('PDF export failed', e);
                alert('PDF export failed: ' + e.message);
            } finally {
                if (btn) { btn.disabled = false; btn.textContent = '{{ __('ui.parameter_search_pdf_export') ?? '📄 PDF' }}'; }
                if (wasCollapsed) {
                    // Restore the collapsed state so the user's UI state is preserved.
                    body.style.display = 'none';
                    document.getElementById('param-results-chevron').style.transform = '';
                }
            }
        }
@endsection
