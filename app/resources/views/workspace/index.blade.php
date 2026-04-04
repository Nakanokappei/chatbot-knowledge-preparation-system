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
        .sidebar.collapsed .tree-emb-label,
        .sidebar.collapsed .tree-children,
        .sidebar.collapsed .tree-create-link,
        .sidebar.collapsed .tree-dataset-menu { display: none; }
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
                @forelse($datasets as $ds)
                    @php
                        $hasActiveChild = $current && $ds->embeddings->contains('id', $current->id);
                        $hasStoredCsv = !empty($ds->schema_json['stored_path']);
                    @endphp
                    <div class="tree-dataset">
                        <div class="tree-dataset-header" onclick="toggleTree(this)">
                            <span class="tree-toggle {{ $hasActiveChild || $loop->first ? 'open' : '' }}">&#9654;</span>
                            <svg class="tree-icon" width="16" height="16" viewBox="0 0 16 16" fill="none">
                                <path d="M2 3.5A1.5 1.5 0 013.5 2h3.172a1.5 1.5 0 011.06.44l.828.827a1.5 1.5 0 001.06.44H12.5A1.5 1.5 0 0114 5.207V12.5a1.5 1.5 0 01-1.5 1.5h-9A1.5 1.5 0 012 12.5V3.5z" stroke="currentColor" stroke-width="1.2"/>
                            </svg>
                            <span class="tree-dataset-name">{{ $ds->name }}</span>
                            <span class="tree-dataset-count">{{ $ds->row_count }}</span>
                            @if($hasStoredCsv)
                                <a href="{{ route('dataset.configure', $ds) }}" class="tree-dataset-menu"
                                   onclick="event.stopPropagation();" title="{{ __('ui.reconfigure') }}">⋯</a>
                            @endif
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
                                    <span class="tree-emb-count">{{ $emb->knowledge_units_count > 0 ? $emb->knowledge_units_count : $emb->row_count }}</span>
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
                                        @elseif($job->status === 'cancelled')
                                            <span style="color: #8e8e93;">⊘</span>
                                        @else
                                            <span style="display: inline-flex; align-items: center; gap: 6px;">
                                                ⏳
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
            @elseif($current)
                {{-- Embedding detail view: header with stats, rename, chat button, and KU table --}}
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid #f0f0f2;">
                    <div style="flex: 1; min-width: 0;">
                        <!-- Editable name -->
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <h2 id="emb-title" style="font-size: 18px; font-weight: 600; cursor: pointer;"
                                onclick="startRename()" title="Click to rename">{{ $current->name }}</h2>
                            <button id="rename-pen" onclick="startRename()" style="background: none; border: none; cursor: pointer; color: #5f6368; padding: 2px;" title="{{ __('ui.rename') }}">
                                <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M11.5 1.5l3 3L5 14H2v-3L11.5 1.5z"/>
                                </svg>
                            </button>
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
                                <tr>
                                    <td style="padding: 4px 24px 4px 0; color: #5f6368; white-space: nowrap; border: none;">{{ __('ui.rows') }}</td>
                                    <td style="padding: 4px 0; border: none;">{{ $current->row_count }}</td>
                                </tr>
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
                                <a href="{{ route('workspace.export', ['embeddingId' => $current->id, 'format' => 'csv']) }}"
                                   style="display: block; padding: 8px 16px; color: #333; text-decoration: none; font-size: 13px;"
                                   onmouseover="this.style.background='#f5f5f5'" onmouseout="this.style.background='#fff'">
                                    {{ __('ui.cluster') }} CSV (UTF-8)
                                </a>
                                <a href="{{ route('workspace.export', ['embeddingId' => $current->id, 'format' => 'json']) }}"
                                   style="display: block; padding: 8px 16px; color: #333; text-decoration: none; font-size: 13px;"
                                   onmouseover="this.style.background='#f5f5f5'" onmouseout="this.style.background='#fff'">
                                    {{ __('ui.cluster') }} JSON
                                </a>
                                <div style="border-top: 1px solid #e0e0e0;"></div>
                                <a href="{{ route('workspace.export-rows', ['embeddingId' => $current->id]) }}"
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

                @if($embeddingJob && !in_array($embeddingJob->status, ['completed', 'failed']))
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
                        <div style="font-size: 12px; color: #5f6368; margin-top: 4px;">
                            {{ $embeddingJob->progress }}%
                            @if($embeddingJob->status && !in_array($embeddingJob->status, ['submitted', 'completed', 'failed', 'queued']))
                                <span style="margin-left: 4px; text-transform: capitalize;">{{ str_replace('_', ' ', $embeddingJob->status) }}</span>
                            @endif
                        </div>
                    </div>
                @elseif($knowledgeUnits->isEmpty())
                    <div class="empty">
                        <div class="empty-icon">
                            <svg width="48" height="48" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="6" y="30" width="8" height="12" rx="1"/><rect x="20" y="18" width="8" height="24" rx="1"/><rect x="34" y="6" width="8" height="36" rx="1"/>
                            </svg>
                        </div>
                        <div class="empty-title">{{ __('ui.no_clusters_yet') }}</div>
                        <p>{{ __('ui.run_pipeline_to_generate') }}</p>
                    </div>
                @else
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
                                class="btn btn-sm btn-green" style="font-size: 12px;" disabled>{{ __('ui.approve') }}</button>
                            <button type="button" onclick="kuBulkAction('draft')" id="ku-btn-exclude"
                                class="btn btn-sm btn-outline" style="font-size: 12px;" disabled>{{ __('ui.set_excluded') }}</button>
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

                localizeTimestamps();

                // Stop polling when no more processing jobs
                const processingBadge = doc.querySelector('[data-processing-count]');
                const processingCount = processingBadge ? parseInt(processingBadge.dataset.processingCount) : 0;
                if (processingCount === 0 && pollingActive) {
                    pollingActive = false;
                }
            } catch (e) { }
        }
        // Poll every 5 seconds; also poll on page load if jobs are processing
        const pollingInterval = setInterval(() => {
            if (pollingActive) refreshWorkspace();
        }, 5000);
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
@endsection
