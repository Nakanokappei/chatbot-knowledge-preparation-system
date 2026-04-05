{{-- System admin dashboard: workspace list sidebar with pipeline overview,
     main area shows usage statistics for selected workspace or aggregate. --}}
@extends('layouts.admin')
@section('title', __('ui.system_admin') . ' — KPS')

@section('extra-styles')
        /* Layout: sidebar + main */
        .layout { display: flex; flex: 1; overflow: hidden; }
        .sidebar { width: 280px; background: #F0E6FA; border-right: none; display: flex; flex-direction: column; flex-shrink: 0; overflow: hidden; transition: width 0.2s ease; }
        .sidebar.collapsed { width: 52px; }
        /* When collapsed: hide labels, keep icons and counts visible */
        .sidebar.collapsed .ws-name,
        .sidebar.collapsed .pipeline-header,
        .sidebar.collapsed .pipeline-filter-label,
        .sidebar.collapsed .create-label { display: none; }
        /* Keep counts visible and right-aligned when collapsed */
        .sidebar.collapsed .ws-item,
        .sidebar.collapsed .pipeline-filter { justify-content: center; padding-left: 0; padding-right: 0; margin-right: 4px; }
        .sidebar.collapsed .ws-count,
        .sidebar.collapsed .pipeline-filter-count { margin-left: 0; font-size: 11px; }
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
        .pipeline-filter { display: flex; align-items: center; gap: 8px; padding: 6px 12px 6px 16px; border-radius: 0 100px 100px 0; font-size: 13px; color: #5f6368; text-decoration: none; margin-right: 8px; transition: background 0.15s; }
        .pipeline-filter:hover { background: #DDD0F5; }
        .pipeline-filter.active { background: #CCBAF0; color: #1d1d1f; }
        .pipeline-filter-count { font-size: 12px; color: #5f6368; margin-left: auto; }
        .pipeline-filter svg { flex-shrink: 0; }

        /* Job table */
        .ku-table { width: 100%; border-collapse: collapse; }
        .ku-table td { padding: 10px 12px; border-bottom: 1px solid #f0f0f2; vertical-align: top; }

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
                        @if($ws->status === 'frozen')
                            <span class="badge" style="background:#fff3cd;color:#856404;font-size:10px;padding:1px 6px;">{{ __('ui.status_frozen') }}</span>
                        @elseif($ws->status === 'suspended')
                            <span class="badge" style="background:#f8d7da;color:#721c24;font-size:10px;padding:1px 6px;">{{ __('ui.status_suspended') }}</span>
                        @endif
                        <span class="ws-count">{{ $ws->users()->count() }}{{ __('ui.unit_users') }}</span>
                    </a>
                @endforeach

                {{-- Pipeline section: filter links with counts --}}
                <div class="pipeline-header" style="margin-top: 8px; border-top: 1px solid #D8C8F0; padding-top: 16px;">{{ __('ui.pipeline') }}</div>

                <a href="{{ route('admin.index', ['pipeline' => 'jobs', 'pf' => 'all']) }}"
                   class="pipeline-filter {{ ($pipelineView === 'jobs' && $pipelineFilter === 'all') ? 'active' : '' }}">
                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><rect x="1.5" y="1.5" width="11" height="11" rx="2" stroke="currentColor" stroke-width="1.2"/><line x1="4" y1="5" x2="10" y2="5" stroke="currentColor" stroke-width="1"/><line x1="4" y1="7.5" x2="10" y2="7.5" stroke="currentColor" stroke-width="1"/><line x1="4" y1="10" x2="7.5" y2="10" stroke="currentColor" stroke-width="1"/></svg>
                    <span class="pipeline-filter-label">{{ __('ui.all_jobs') }}</span>
                    <span class="pipeline-filter-count">{{ $jobStats['total'] }}</span>
                </a>
                <a href="{{ route('admin.index', ['pipeline' => 'jobs', 'pf' => 'completed']) }}"
                   class="pipeline-filter {{ ($pipelineView === 'jobs' && $pipelineFilter === 'completed') ? 'active' : '' }}">
                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><circle cx="7" cy="7" r="5.5" stroke="currentColor" stroke-width="1.2"/><path d="M4.5 7l2 2 3.5-3.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span class="pipeline-filter-label">{{ __('ui.completed') }}</span>
                    <span class="pipeline-filter-count">{{ $jobStats['completed'] }}</span>
                </a>
                <a href="{{ route('admin.index', ['pipeline' => 'jobs', 'pf' => 'processing']) }}"
                   class="pipeline-filter {{ ($pipelineView === 'jobs' && $pipelineFilter === 'processing') ? 'active' : '' }}">
                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><circle cx="7" cy="7" r="5.5" stroke="currentColor" stroke-width="1.2"/><path d="M7 4v3.5l2.5 1.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span class="pipeline-filter-label">{{ __('ui.processing') }}</span>
                    <span class="pipeline-filter-count">{{ $jobStats['processing'] }}</span>
                </a>
                <a href="{{ route('admin.index', ['pipeline' => 'jobs', 'pf' => 'failed']) }}"
                   class="pipeline-filter {{ ($pipelineView === 'jobs' && $pipelineFilter === 'failed') ? 'active' : '' }}">
                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><circle cx="7" cy="7" r="5.5" stroke="currentColor" stroke-width="1.2"/><line x1="5" y1="5" x2="9" y2="9" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/><line x1="9" y1="5" x2="5" y2="9" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
                    <span class="pipeline-filter-label">{{ __('ui.failed') }}</span>
                    <span class="pipeline-filter-count">{{ $jobStats['failed'] }}</span>
                </a>
            </div>
        </div>

        {{-- Main content: pipeline job list or usage stats --}}
        <div class="main">
            @if(session('success') && !$selectedWorkspace)
                <div style="background: #d4edda; color: #155724; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px;">✓ {{ session('success') }}</div>
            @endif

            @if($pipelineView === 'jobs')
                {{-- Pipeline job table: same columns as workspace view, plus workspace name --}}
                @if($filteredJobs->isEmpty())
                    <div style="text-align: center; padding: 60px 20px; color: #5f6368;">
                        <svg width="48" height="48" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 12px;">
                            <path d="M6 18l8-12h20l8 12"/><path d="M6 18v18a2 2 0 002 2h32a2 2 0 002-2V18"/><path d="M6 18h12l2 4h8l2-4h12"/>
                        </svg>
                        <div style="font-size: 15px; font-weight: 500; margin-bottom: 4px;">{{ __('ui.no_jobs') }}</div>
                    </div>
                @else
                    <table class="ku-table" id="job-list">
                        <tbody>
                            @foreach($filteredJobs as $job)
                            @php
                                $clustering = $job->step_outputs_json['clustering'] ?? null;
                                $nClusters  = $clustering['n_clusters'] ?? null;
                                $nNoise     = $clustering['n_noise'] ?? null;
                                $silhouette = $clustering['silhouette_score'] ?? null;
                            @endphp
                            <tr>
                                <td style="max-width: 0; width: 100%;">
                                    <div style="font-size: 14px; font-weight: 500;">{{ $job->dataset->name ?? __('ui.job_number') . $job->id }}</div>
                                    <div style="font-size: 13px; color: #5f6368; margin-top: 2px;">
                                        @if($nClusters !== null)
                                            {{ $nClusters }} {{ __('ui.clusters') }}
                                            @if($silhouette !== null) · {{ __('ui.silhouette') }} {{ number_format($silhouette, 3) }} @endif
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
                                {{-- Workspace name column --}}
                                <td style="white-space: nowrap; vertical-align: top; padding-top: 12px;">
                                    <span style="font-size: 12px; color: #8e8e93;">{{ $job->workspace->name ?? '—' }}</span>
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
                                                <form method="POST" action="{{ route('admin.cancel-pipeline', $job) }}"
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
            @else
                @if($selectedWorkspace)
                {{-- Workspace header with tab navigation --}}
                <h2 style="font-size: 20px; font-weight: 600; margin-bottom: 16px;">{{ $selectedWorkspace->name }}
                    @if($selectedWorkspace->status !== 'active')
                        <span class="badge" style="background:{{ $selectedWorkspace->status === 'frozen' ? '#fff3cd' : '#f8d7da' }};color:{{ $selectedWorkspace->status === 'frozen' ? '#856404' : '#721c24' }};font-size:11px;padding:2px 8px;vertical-align:middle;">{{ __('ui.status_' . $selectedWorkspace->status) }}</span>
                    @endif
                </h2>

                @php $activeTab = request('tab', 'usage'); @endphp
                <div style="display: flex; gap: 4px; margin-bottom: 20px; border-bottom: 2px solid #e5e5e7; padding-bottom: 0;">
                    <a href="{{ route('admin.index', ['workspace' => $selectedWorkspace->id, 'tab' => 'usage']) }}"
                       style="padding: 8px 16px; font-size: 14px; font-weight: 500; text-decoration: none; border-bottom: 2px solid {{ $activeTab === 'usage' ? '#7C3AED' : 'transparent' }}; margin-bottom: -2px; color: {{ $activeTab === 'usage' ? '#7C3AED' : '#5f6368' }};">
                        {{ __('ui.usage') }}
                    </a>
                    <a href="{{ route('admin.index', ['workspace' => $selectedWorkspace->id, 'tab' => 'users']) }}"
                       style="padding: 8px 16px; font-size: 14px; font-weight: 500; text-decoration: none; border-bottom: 2px solid {{ $activeTab === 'users' ? '#7C3AED' : 'transparent' }}; margin-bottom: -2px; color: {{ $activeTab === 'users' ? '#7C3AED' : '#5f6368' }};">
                        {{ __('ui.tab_users') }}
                    </a>
                    <a href="{{ route('admin.index', ['workspace' => $selectedWorkspace->id, 'tab' => 'manage']) }}"
                       style="padding: 8px 16px; font-size: 14px; font-weight: 500; text-decoration: none; border-bottom: 2px solid {{ $activeTab === 'manage' ? '#7C3AED' : 'transparent' }}; margin-bottom: -2px; color: {{ $activeTab === 'manage' ? '#7C3AED' : '#5f6368' }};">
                        {{ __('ui.tab_manage') }}
                    </a>
                </div>

                @if(session('success'))
                    <div style="background: #d4edda; color: #155724; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px;">{{ session('success') }}</div>
                @endif
                @if($errors->any())
                    <div style="background: #f8d7da; color: #721c24; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px;">{{ $errors->first() }}</div>
                @endif

                {{-- Tab: Users — member list + invite --}}
                @if($activeTab === 'users')
                    @if(session('invite_url'))
                        <div style="background: #d4edda; color: #155724; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 13px;">
                            {{ __('ui.invite_url_label') }}<br>
                            <div style="display: flex; gap: 8px; align-items: center; margin-top: 6px;">
                                <code style="flex: 1; background: #fff; padding: 6px 10px; border-radius: 6px; font-size: 12px; word-break: break-all;">{{ session('invite_url') }}</code>
                                <button onclick="navigator.clipboard.writeText('{{ session('invite_url') }}'); this.textContent='{{ __('ui.copied') }}';" class="btn btn-sm btn-outline" style="font-size: 11px; white-space: nowrap;">{{ __('ui.copy') }}</button>
                            </div>
                        </div>
                    @endif

                    {{-- Members list --}}
                    <div class="card" style="margin-bottom: 16px;">
                        <h3 style="font-size: 15px; font-weight: 600; margin-bottom: 12px;">{{ __('ui.members') }} ({{ $selectedWorkspace->users->count() }}{{ __('ui.unit_users') }})</h3>
                        <table style="table-layout: fixed;">
                            <colgroup>
                                <col style="width: 25%;">
                                <col style="width: 35%;">
                                <col style="width: 15%;">
                                <col style="width: 25%;">
                            </colgroup>
                            <thead><tr><th>{{ __('ui.name') }}</th><th>{{ __('ui.email') }}</th><th>{{ __('ui.role') }}</th><th>{{ __('ui.joined') }}</th></tr></thead>
                            <tbody>
                            @foreach($selectedWorkspace->users->sortBy('name') as $member)
                                <tr>
                                    <td style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $member->name }}</td>
                                    <td style="font-size: 13px; color: #5f6368; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $member->email }}</td>
                                    <td><span class="badge badge-{{ $member->role === 'owner' ? 'approved' : 'draft' }}">{{ __('ui.role_' . $member->role) }}</span></td>
                                    <td style="font-size: 13px; color: #5f6368;">{{ $member->created_at->format('Y-m-d') }}</td>
                                </tr>
                            @endforeach
                            @foreach($workspaceInvitations as $inv)
                                <tr style="opacity: 0.7;">
                                    <td style="color: #86868b; font-style: italic;">{{ __('ui.invited') }}</td>
                                    <td style="font-size: 13px; color: #5f6368; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $inv->email }}</td>
                                    <td><span class="badge badge-{{ $inv->isExpired() ? 'rejected' : 'pending' }}">{{ $inv->isExpired() ? __('ui.expired') : __('ui.pending') }}</span></td>
                                    <td style="font-size: 13px;">
                                        <form method="POST" action="{{ route('admin.invitations.cancel', $inv) }}" style="display: inline;">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-danger" style="font-size: 11px; padding: 2px 8px;" onclick="return confirm('{{ __('ui.cancel_invitation_confirm') }}')">{{ __('ui.cancel') }}</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{-- Invite form --}}
                    <div class="card" style="margin-bottom: 16px;">
                        <h3 style="font-size: 15px; font-weight: 600; margin-bottom: 12px;">{{ __('ui.invite_to_workspace') }}</h3>
                        <form method="POST" action="{{ route('admin.workspaces.invite', $selectedWorkspace) }}" style="display: flex; gap: 8px; align-items: flex-end; flex-wrap: wrap;">
                            @csrf
                            <div style="flex: 1; min-width: 200px;">
                                <label style="font-size: 12px; color: #5f6368; display: block; margin-bottom: 4px;">{{ __('ui.email') }}</label>
                                <input type="email" name="email" required placeholder="user@example.com"
                                       style="width: 100%; padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 13px;">
                            </div>
                            <div style="min-width: 120px;">
                                <label style="font-size: 12px; color: #5f6368; display: block; margin-bottom: 4px;">{{ __('ui.role') }}</label>
                                <select name="role" style="width: 100%; padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 13px; background: #fff;">
                                    <option value="owner">Owner</option>
                                    <option value="member">Member</option>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary">{{ __('ui.invite') }}</button>
                        </form>
                    </div>

                {{-- Tab: Manage — status + delete --}}
                @elseif($activeTab === 'manage')
                    {{-- Workspace status --}}
                    <div class="card" style="margin-bottom: 16px;">
                        <h3 style="font-size: 15px; font-weight: 600; margin-bottom: 12px;">{{ __('ui.workspace_status') }}</h3>
                        <form method="POST" action="{{ route('admin.workspaces.status', $selectedWorkspace) }}" style="display: flex; gap: 8px; align-items: flex-end;">
                            @csrf
                            @method('PUT')
                            <div style="flex: 1;">
                                <label style="font-size: 12px; color: #5f6368; display: block; margin-bottom: 4px;">{{ __('ui.current_status') }}</label>
                                <select name="status" style="width: 100%; padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 13px; background: #fff;">
                                    <option value="active" {{ $selectedWorkspace->status === 'active' ? 'selected' : '' }}>{{ __('ui.status_active') }} — {{ __('ui.status_active_desc') }}</option>
                                    <option value="frozen" {{ $selectedWorkspace->status === 'frozen' ? 'selected' : '' }}>{{ __('ui.status_frozen') }} — {{ __('ui.status_frozen_desc') }}</option>
                                    <option value="suspended" {{ $selectedWorkspace->status === 'suspended' ? 'selected' : '' }}>{{ __('ui.status_suspended') }} — {{ __('ui.status_suspended_desc') }}</option>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary">{{ __('ui.update') }}</button>
                        </form>
                    </div>

                    {{-- Delete workspace --}}
                    <div class="card" style="margin-bottom: 24px; border: 1px solid #f8d7da;">
                        <h3 style="font-size: 15px; font-weight: 600; margin-bottom: 8px; color: #721c24;">{{ __('ui.delete_workspace') }}</h3>
                        <p style="font-size: 13px; color: #721c24; margin-bottom: 12px;">{{ __('ui.delete_workspace_warning') }}</p>
                        <form method="POST" action="{{ route('admin.workspaces.destroy', $selectedWorkspace) }}">
                            @csrf
                            @method('DELETE')
                            <div style="display: flex; gap: 8px; align-items: flex-end;">
                                <div style="flex: 1;">
                                    <label style="font-size: 12px; color: #721c24; display: block; margin-bottom: 4px;">{{ __('ui.delete_workspace_confirm_label', ['name' => $selectedWorkspace->name]) }}</label>
                                    <input type="text" name="confirm_name" required autocomplete="off"
                                           placeholder="{{ $selectedWorkspace->name }}"
                                           style="width: 100%; padding: 8px 12px; border: 1px solid #f5c6cb; border-radius: 8px; font-size: 13px;">
                                </div>
                                <button type="submit" class="btn btn-danger" onclick="return confirm('{{ __('ui.delete_workspace_final_confirm') }}')">{{ __('ui.delete') }}</button>
                            </div>
                        </form>
                    </div>

                {{-- Tab: Usage (default) --}}
                @else
                @endif
                @endif

                {{-- Usage stats panel (shown on usage tab or aggregate view) --}}
                @if(!$selectedWorkspace || $activeTab === 'usage')
                <h2 style="font-size: 20px; font-weight: 600; margin-bottom: 4px;">
                    @if($selectedWorkspace)
                        {{ __('ui.usage') }}
                    @else
                        {{ __('ui.all_workspaces') }} — {{ __('ui.usage') }}
                    @endif
                </h2>
                <p style="color: #5f6368; font-size: 13px; margin-bottom: 24px;">{{ __('ui.usage_30days') }}</p>

                {{-- Summary badges: Cost → Requests → Tokens --}}
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-value">${{ number_format($usageData['monthly']['cost'], 4) }}</div>
                        <div class="stat-label">{{ __('ui.cost_30days') }}</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value">{{ number_format($usageData['monthly']['requests']) }}</div>
                        <div class="stat-label">{{ __('ui.requests_30days') }}</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value">{{ number_format($usageData['monthly']['tokens']) }}</div>
                        <div class="stat-label">{{ __('ui.tokens_30days') }}</div>
                    </div>
                </div>

                {{-- Chart 1: Daily cost line chart --}}
                <div class="card">
                    <h2>{{ __('ui.daily_cost') }}</h2>
                    <div class="chart-container" style="height: 200px;"><canvas id="costChart"></canvas></div>
                    <div style="display: flex; gap: 16px; justify-content: center; margin-top: 8px; font-size: 12px; color: #5f6368;">
                        <span style="display: flex; align-items: center; gap: 4px;">
                            <span style="display: inline-block; width: 14px; height: 2px; background: #7C3AED; border-radius: 1px;"></span>
                            {{ __('ui.cost') }}
                        </span>
                    </div>
                </div>

                {{-- Chart 2: Daily tokens (bars) + requests (orange line) --}}
                <div class="card">
                    <h2>{{ __('ui.daily_tokens') }} / {{ __('ui.requests') }}</h2>
                    <div class="chart-container" style="height: 200px;"><canvas id="combinedChart"></canvas></div>
                    <div style="display: flex; gap: 16px; justify-content: center; margin-top: 8px; font-size: 12px; color: #5f6368;">
                        <span style="display: flex; align-items: center; gap: 4px;">
                            <span style="display: inline-block; width: 10px; height: 10px; border-radius: 2px; background: #0071e3;"></span>
                            {{ __('ui.tokens') }}
                        </span>
                        <span style="display: flex; align-items: center; gap: 4px;">
                            <span style="display: inline-block; width: 14px; height: 2px; background: #ff9500; border-radius: 1px;"></span>
                            {{ __('ui.requests') }}
                        </span>
                    </div>
                </div>

                {{-- Chart 3: Pipeline jobs stacked bar chart (completed / failed) --}}
                <div class="card">
                    <h2>{{ __('ui.pipeline') }} — {{ __('ui.daily_trend') }}</h2>
                    <div class="chart-container" style="height: 200px;"><canvas id="pipelineChart"></canvas></div>
                    <div style="display: flex; gap: 16px; justify-content: center; margin-top: 8px; font-size: 12px; color: #5f6368;">
                        <span style="display: flex; align-items: center; gap: 4px;">
                            <span style="display: inline-block; width: 10px; height: 10px; border-radius: 2px; background: #34c759;"></span>
                            {{ __('ui.completed') }}
                        </span>
                        <span style="display: flex; align-items: center; gap: 4px;">
                            <span style="display: inline-block; width: 10px; height: 10px; border-radius: 2px; background: #ff3b30;"></span>
                            {{ __('ui.failed') }}
                        </span>
                    </div>
                </div>
                @endif {{-- end usage panel conditional --}}
            @endif
        </div>
    </div>
@endsection

@section('scripts')
        @if(!$pipelineView && (!isset($activeTab) || $activeTab === 'usage' || !$selectedWorkspace))
        // Build 30-day date range with zero-fills
        (function() {
            const rawData = @json($usageData['dailyTrend']);
            const data = [];
            const today = new Date();
            for (let i = 29; i >= 0; i--) {
                const d = new Date(today);
                d.setDate(d.getDate() - i);
                const dateStr = d.toISOString().slice(0, 10);
                const found = rawData.find(r => r.date === dateStr);
                data.push({
                    date: dateStr,
                    tokens:   found ? Number(found.total_tokens)  : 0,
                    cost:     found ? Number(found.total_cost)     : 0,
                    requests: found ? Number(found.request_count)  : 0,
                });
            }

            // Round up to a nice number for axis ticks (e.g. 1234 → 2000 with 4 steps)
            function niceMax(value, steps) {
                if (value <= 0) return steps;
                const rawStep  = value / steps;
                const mag      = Math.pow(10, Math.floor(Math.log10(rawStep)));
                const residual = rawStep / mag;
                const niceStep = residual <= 1 ? mag
                               : residual <= 2 ? 2 * mag
                               : residual <= 5 ? 5 * mag
                               : 10 * mag;
                return niceStep * steps;
            }

            function fmtTokens(v) {
                if (v >= 1000000) return (v / 1000000).toFixed(1) + 'M';
                if (v >= 1000)    return (v / 1000).toFixed(0) + 'K';
                return Math.round(v).toString();
            }
            // Adaptive decimal places: strip trailing zeros so ticks look clean (e.g. $0.002 not $0.0020)
            function fmtCost(v) {
                if (v === 0) return '$0';
                let s;
                if (v >= 1)      s = v.toFixed(2);
                else if (v >= 0.01)   s = v.toFixed(3);
                else if (v >= 0.001)  s = v.toFixed(4);
                else if (v >= 0.0001) s = v.toFixed(5);
                else                  s = v.toFixed(6);
                // Remove trailing zeros after the decimal point
                s = s.replace(/(\.\d*[1-9])0+$/, '$1').replace(/\.0+$/, '');
                return '$' + s;
            }

            // ── Chart 1: Daily cost line chart ────────────────────────────────
            function drawCostChart() {
                const canvas = document.getElementById('costChart');
                if (!canvas) return;
                const ctx = canvas.getContext('2d');
                const dpr  = window.devicePixelRatio || 1;
                const rect = canvas.parentElement.getBoundingClientRect();
                canvas.width  = rect.width  * dpr;
                canvas.height = rect.height * dpr;
                ctx.scale(dpr, dpr);
                const W = rect.width, H = rect.height;
                const pad = { top: 20, right: 20, bottom: 32, left: 60 };
                const cw  = W - pad.left - pad.right;
                const ch  = H - pad.top  - pad.bottom;

                const maxCost  = niceMax(Math.max(...data.map(d => d.cost), 0.0001), 4);
                const costStep = maxCost / 4;

                // Grid lines + left Y-axis labels (step-based to avoid floating-point drift)
                ctx.strokeStyle = '#f0f0f2';
                ctx.lineWidth   = 1;
                ctx.fillStyle   = '#5f6368';
                ctx.font        = '11px -apple-system, sans-serif';
                ctx.textAlign   = 'right';
                for (let i = 0; i <= 4; i++) {
                    const y = pad.top + ch - (ch * i / 4);
                    ctx.beginPath(); ctx.moveTo(pad.left, y); ctx.lineTo(W - pad.right, y); ctx.stroke();
                    ctx.fillText(fmtCost(costStep * i), pad.left - 6, y + 4);
                }

                // Cost line (purple)
                const barW = Math.max(2, (cw / data.length) - 2);
                const gap  = (cw - barW * data.length) / data.length;
                ctx.beginPath();
                ctx.strokeStyle = '#7C3AED';
                ctx.lineWidth   = 2;
                ctx.lineJoin    = 'round';
                data.forEach((d, i) => {
                    const x = pad.left + i * (barW + gap) + gap / 2 + barW / 2;
                    const y = pad.top + ch - (maxCost > 0 ? (d.cost / maxCost) * ch : 0);
                    i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
                });
                ctx.stroke();

                // Data points
                ctx.fillStyle = '#7C3AED';
                data.forEach((d, i) => {
                    if (d.cost > 0) {
                        const x = pad.left + i * (barW + gap) + gap / 2 + barW / 2;
                        const y = pad.top + ch - (maxCost > 0 ? (d.cost / maxCost) * ch : 0);
                        ctx.beginPath(); ctx.arc(x, y, 3, 0, Math.PI * 2); ctx.fill();
                    }
                });

                // X-axis date labels
                ctx.fillStyle = '#5f6368';
                ctx.font      = '10px -apple-system, sans-serif';
                ctx.textAlign = 'center';
                data.forEach((d, i) => {
                    if (i % 5 === 0 || i === data.length - 1) {
                        const x = pad.left + i * (barW + gap) + gap / 2 + barW / 2;
                        ctx.fillText(d.date.slice(5), x, H - pad.bottom + 14);
                    }
                });
            }

            // ── Chart 2: Tokens bars + Requests line (same as user view) ─────
            function drawCombinedChart() {
                const canvas = document.getElementById('combinedChart');
                if (!canvas) return;
                const ctx = canvas.getContext('2d');
                const dpr  = window.devicePixelRatio || 1;
                const rect = canvas.parentElement.getBoundingClientRect();
                canvas.width  = rect.width  * dpr;
                canvas.height = rect.height * dpr;
                ctx.scale(dpr, dpr);
                const W = rect.width, H = rect.height;
                const pad = { top: 20, right: 56, bottom: 32, left: 52 };
                const cw  = W - pad.left - pad.right;
                const ch  = H - pad.top  - pad.bottom;

                const maxTokens   = niceMax(Math.max(...data.map(d => d.tokens),   1), 4);
                const maxRequests = niceMax(Math.max(...data.map(d => d.requests), 1), 4);

                // Grid lines + left Y-axis (tokens)
                ctx.strokeStyle = '#f0f0f2';
                ctx.lineWidth   = 1;
                ctx.fillStyle   = '#5f6368';
                ctx.font        = '11px -apple-system, sans-serif';
                for (let i = 0; i <= 4; i++) {
                    const y = pad.top + ch - (ch * i / 4);
                    ctx.beginPath(); ctx.moveTo(pad.left, y); ctx.lineTo(W - pad.right, y); ctx.stroke();
                    ctx.textAlign = 'right';
                    ctx.fillText(fmtTokens(maxTokens * i / 4), pad.left - 6, y + 4);
                    // Right Y-axis (requests)
                    ctx.textAlign = 'left';
                    ctx.fillText(Math.round(maxRequests * i / 4).toString(), W - pad.right + 6, y + 4);
                }

                // Token bars (blue)
                const barW = Math.max(2, (cw / data.length) - 2);
                const gap  = (cw - barW * data.length) / data.length;
                data.forEach((d, i) => {
                    const x = pad.left + i * (barW + gap) + gap / 2;
                    const h = maxTokens > 0 ? (d.tokens / maxTokens) * ch : 0;
                    ctx.fillStyle = '#0071e3';
                    ctx.beginPath();
                    ctx.roundRect(x, pad.top + ch - h, barW, Math.max(h, h > 0 ? 1 : 0), [2, 2, 0, 0]);
                    ctx.fill();
                    // X-axis date labels
                    if (i % 5 === 0 || i === data.length - 1) {
                        ctx.fillStyle = '#5f6368';
                        ctx.font      = '10px -apple-system, sans-serif';
                        ctx.textAlign = 'center';
                        ctx.fillText(d.date.slice(5), x + barW / 2, H - pad.bottom + 14);
                    }
                });

                // Request count line (orange)
                ctx.beginPath();
                ctx.strokeStyle = '#ff9500';
                ctx.lineWidth   = 2;
                ctx.lineJoin    = 'round';
                data.forEach((d, i) => {
                    const x = pad.left + i * (barW + gap) + gap / 2 + barW / 2;
                    const y = pad.top + ch - (maxRequests > 0 ? (d.requests / maxRequests) * ch : 0);
                    i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
                });
                ctx.stroke();

                // Request data points
                ctx.fillStyle = '#ff9500';
                data.forEach((d, i) => {
                    if (d.requests > 0) {
                        const x = pad.left + i * (barW + gap) + gap / 2 + barW / 2;
                        const y = pad.top + ch - (maxRequests > 0 ? (d.requests / maxRequests) * ch : 0);
                        ctx.beginPath(); ctx.arc(x, y, 3, 0, Math.PI * 2); ctx.fill();
                    }
                });
            }

            // ── Chart 3: Pipeline stacked bar chart (completed / failed) ────
            const rawPipelineData = @json($usageData['pipelineDailyStats'] ?? []);
            function drawPipelineChart() {
                const canvas = document.getElementById('pipelineChart');
                if (!canvas) return;
                const ctx = canvas.getContext('2d');
                const dpr  = window.devicePixelRatio || 1;
                const rect = canvas.parentElement.getBoundingClientRect();
                canvas.width  = rect.width  * dpr;
                canvas.height = rect.height * dpr;
                ctx.scale(dpr, dpr);
                const W = rect.width, H = rect.height;
                const pad = { top: 20, right: 20, bottom: 32, left: 40 };
                const cw  = W - pad.left - pad.right;
                const ch  = H - pad.top  - pad.bottom;

                // Build 30-day pipeline data aligned with the same date range
                const pipelineData = [];
                const today = new Date();
                for (let i = 29; i >= 0; i--) {
                    const d = new Date(today);
                    d.setDate(d.getDate() - i);
                    const dateStr = d.toISOString().slice(0, 10);
                    const found = rawPipelineData.find(r => r.date === dateStr);
                    pipelineData.push({
                        date:      dateStr,
                        completed: found ? Number(found.completed) : 0,
                        failed:    found ? Number(found.failed)    : 0,
                    });
                }

                // Integer-aware axis: step is at least 1, maxTotal always a clean multiple of 4
                const rawMax   = Math.max(...pipelineData.map(d => d.completed + d.failed), 1);
                const step     = Math.max(1, Math.ceil(rawMax / 4));
                const maxTotal = step * 4;

                // Grid lines + left Y-axis labels (integer counts, no duplicates)
                ctx.strokeStyle = '#f0f0f2';
                ctx.lineWidth   = 1;
                ctx.fillStyle   = '#5f6368';
                ctx.font        = '11px -apple-system, sans-serif';
                ctx.textAlign   = 'right';
                for (let i = 0; i <= 4; i++) {
                    const y = pad.top + ch - (ch * i / 4);
                    ctx.beginPath(); ctx.moveTo(pad.left, y); ctx.lineTo(W - pad.right, y); ctx.stroke();
                    ctx.fillText((step * i).toString(), pad.left - 6, y + 4);
                }

                // Stacked bars: green (completed) on bottom, red (failed) on top
                const barW = Math.max(2, (cw / pipelineData.length) - 2);
                const gap  = (cw - barW * pipelineData.length) / pipelineData.length;
                pipelineData.forEach((d, i) => {
                    const x = pad.left + i * (barW + gap) + gap / 2;
                    // Completed bar (green, bottom segment)
                    const hCompleted = maxTotal > 0 ? (d.completed / maxTotal) * ch : 0;
                    if (hCompleted > 0) {
                        ctx.fillStyle = '#34c759';
                        ctx.beginPath();
                        ctx.roundRect(x, pad.top + ch - hCompleted, barW, hCompleted, [2, 2, 0, 0]);
                        ctx.fill();
                    }
                    // Failed bar (red, stacked on top of completed)
                    const hFailed = maxTotal > 0 ? (d.failed / maxTotal) * ch : 0;
                    if (hFailed > 0) {
                        ctx.fillStyle = '#ff3b30';
                        const yFailed = pad.top + ch - hCompleted - hFailed;
                        ctx.beginPath();
                        ctx.roundRect(x, yFailed, barW, hFailed, [2, 2, 0, 0]);
                        ctx.fill();
                    }
                    // X-axis date labels every 5 days
                    if (i % 5 === 0 || i === pipelineData.length - 1) {
                        ctx.fillStyle = '#5f6368';
                        ctx.font      = '10px -apple-system, sans-serif';
                        ctx.textAlign = 'center';
                        ctx.fillText(d.date.slice(5), x + barW / 2, H - pad.bottom + 14);
                    }
                });
            }

            drawCostChart();
            drawCombinedChart();
            drawPipelineChart();
            window.addEventListener('resize', () => { drawCostChart(); drawCombinedChart(); drawPipelineChart(); });
        })();
        @endif
@endsection
