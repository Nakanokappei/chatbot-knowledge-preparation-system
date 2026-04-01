{{-- Knowledge units listing for a pipeline job: stats, bulk approve, export links, and individual KU cards. --}}
@extends('layouts.app')
@section('title', 'Job #' . $job->id . ' — ' . __('ui.knowledge'))

@section('extra-styles')
    .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 20px; }
    .stat-card { background: #fff; border-radius: 12px; padding: 16px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
    .stat-value { font-size: 26px; font-weight: 700; }
    .stat-label { font-size: 11px; color: #5f6368; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 4px; }
    .ku-card { border: 1px solid #e5e5e7; border-radius: 12px; padding: 20px; margin-bottom: 14px; }
    .ku-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }
    .ku-topic { font-size: 16px; font-weight: 600; color: #1d1d1f; text-decoration: none; }
    .ku-topic:hover { text-decoration: underline; }
    .ku-intent { font-size: 13px; color: #5f6368; margin-top: 2px; }
    .ku-summary { font-size: 14px; line-height: 1.6; color: #424245; margin-bottom: 12px; }
    .ku-meta { display: flex; gap: 16px; font-size: 12px; color: #5f6368; flex-wrap: wrap; }
    .ku-meta strong { color: #1d1d1f; }
    .keywords { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; }
    .keyword { background: #f0f0f2; padding: 2px 8px; border-radius: 6px; font-size: 11px; color: #424245; }
    .typical-case { background: #f5f5f7; border-radius: 8px; padding: 10px 14px; margin-top: 8px; font-size: 13px; color: #424245; line-height: 1.5; }
    .typical-case-label { font-size: 11px; font-weight: 600; color: #5f6368; margin-bottom: 4px; }
    .card-actions { display: flex; justify-content: space-between; align-items: center; }
    .export-bar { display: flex; gap: 8px; flex-wrap: wrap; }
@endsection

@section('body')
<div class="page-content">
    <div class="page-container">

        <div style="margin-bottom: 4px; font-size: 13px; color: #5f6368;">
            <a href="{{ route('dashboard.show', $job) }}" style="color: #0071e3; text-decoration: none;">← {{ __('ui.back_to_cluster_results') }}</a>
        </div>
        <h1 style="font-size: 20px; font-weight: 600; margin-bottom: 4px;">Job #{{ $job->id }} — {{ __('ui.knowledge') }}</h1>
        <p style="color: #5f6368; font-size: 13px; margin-bottom: 20px;">{{ $job->dataset->name ?? 'Unknown dataset' }} &middot; {{ $knowledgeUnits->count() }} KUs</p>

        {{-- Stats --}}
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value" style="color: #0071e3; font-size: 20px;">
                    {{ $knowledgeUnits->count() }}
                    <span style="font-size: 13px; font-weight: 400; color: #34c759;">
                        （承認済み{{ $knowledgeUnits->where('review_status', 'approved')->count() }}）件
                    </span>
                </div>
                <div class="stat-label">{{ __('ui.cluster_count') }}</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">{{ $knowledgeUnits->sum('row_count') }}</div>
                <div class="stat-label">{{ __('ui.total_rows') }}</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: #ff9500;">{{ $knowledgeUnits->where('review_status', 'draft')->count() }}</div>
                <div class="stat-label">{{ __('ui.draft') }}</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: #34c759;">{{ $knowledgeUnits->where('review_status', 'approved')->count() }}</div>
                <div class="stat-label">{{ __('ui.approved') }}</div>
            </div>
        </div>

        {{-- Bulk actions --}}
        @if($knowledgeUnits->whereIn('review_status', ['draft', 'reviewed'])->count() > 0)
        <div class="card">
            <div class="card-actions">
                <h2 style="margin-bottom: 0;">{{ __('ui.bulk_actions') }}</h2>
                <form method="POST" action="{{ route('knowledge-units.bulk-approve', $job) }}">
                    @csrf
                    <button type="submit" class="btn btn-green"
                            onclick="return confirm('{{ __('ui.approve_confirm') }}')">
                        {{ __('ui.approve_all') }} ({{ $knowledgeUnits->whereIn('review_status', ['draft', 'reviewed'])->count() }})
                    </button>
                </form>
            </div>
        </div>
        @endif

        {{-- Export --}}
        <div class="card">
            <div class="card-actions">
                <h2 style="margin-bottom: 0;">{{ __('ui.export') }}</h2>
                <div class="export-bar">
                    <a href="{{ route('dashboard.knowledge-units.export', ['pipelineJob' => $job->id, 'format' => 'json', 'status' => 'approved']) }}" class="btn btn-outline btn-sm">{{ __('ui.export_json_approved') }}</a>
                    <a href="{{ route('dashboard.knowledge-units.export', ['pipelineJob' => $job->id, 'format' => 'csv', 'status' => 'approved']) }}" class="btn btn-outline btn-sm">{{ __('ui.export_csv_approved') }}</a>
                    <a href="{{ route('dashboard.knowledge-units.export', ['pipelineJob' => $job->id, 'format' => 'json', 'status' => 'all']) }}" class="btn btn-outline btn-sm" style="opacity: 0.65;">{{ __('ui.export_json_all') }}</a>
                    <a href="{{ route('dashboard.knowledge-units.export', ['pipelineJob' => $job->id, 'format' => 'csv', 'status' => 'all']) }}" class="btn btn-outline btn-sm" style="opacity: 0.65;">{{ __('ui.export_csv_all') }}</a>
                </div>
            </div>
        </div>

        {{-- Individual KU cards --}}
        @foreach($knowledgeUnits as $ku)
            <div class="ku-card">
                <div class="ku-header">
                    <div>
                        <a href="{{ route('knowledge-units.show', $ku) }}" class="ku-topic">{{ $ku->topic }}</a>
                        <div class="ku-intent">{{ __('ui.intent') }}: {{ $ku->intent }}</div>
                    </div>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <span class="badge badge-{{ $ku->review_status }}">{{ $ku->review_status }}</span>
                        <a href="{{ route('knowledge-units.show', $ku) }}" class="btn btn-sm btn-outline">{{ __('ui.edit_review') }}</a>
                    </div>
                </div>

                <div class="ku-summary">{{ $ku->summary }}</div>

                <div class="ku-meta">
                    <div><strong>{{ $ku->row_count }}</strong> {{ __('ui.rows') }}</div>
                    <div>{{ __('ui.version') }} <strong>{{ $ku->version }}</strong></div>
                    <div>{{ __('ui.cluster') }} <strong>{{ $ku->cluster_id }}</strong></div>
                    <div>{{ $ku->created_at->format('m/d H:i') }}</div>
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
                        <div class="typical-case-label">{{ __('ui.typical_case') }}</div>
                        {{ Str::limit($ku->typical_cases_json[0], 250) }}
                    </div>
                @endif
            </div>
        @endforeach

    </div>
</div>
@endsection
