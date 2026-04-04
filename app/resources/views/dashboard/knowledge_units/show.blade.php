{{-- Knowledge unit detail/edit: review workflow buttons, editable fields, metadata, and typical cases. --}}
@extends('layouts.app')
@section('title', 'KU #' . $ku->id . ' — ' . $ku->topic)

@section('extra-styles')
    .form-label { display: block; font-size: 13px; font-weight: 500; color: #5f6368; margin-bottom: 4px; }
    .form-input { width: 100%; padding: 9px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 14px; font-family: inherit; }
    .form-input:focus { outline: none; border-color: #0071e3; }
    textarea.form-input { resize: vertical; min-height: 80px; }
    .form-group { margin-bottom: 16px; }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .review-bar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .ku-meta { display: flex; gap: 16px; flex-wrap: wrap; font-size: 13px; color: #5f6368; margin-bottom: 16px; }
    .ku-meta strong { color: #1d1d1f; }
    .keywords { display: flex; gap: 6px; flex-wrap: wrap; }
    .keyword { background: #f0f0f2; padding: 2px 8px; border-radius: 6px; font-size: 12px; color: #424245; }
    .typical-case { background: #f5f5f7; border-radius: 8px; padding: 10px 14px; font-size: 13px; color: #424245; line-height: 1.5; margin-bottom: 8px; }
    .locked-notice { background: #fff3cd; color: #856404; padding: 12px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; }
    .flash-success { background: #d4edda; color: #155724; padding: 12px 16px; border-radius: 8px; font-size: 14px; margin-bottom: 16px; }
    .flash-error { background: #f8d7da; color: #721c24; padding: 12px 16px; border-radius: 8px; font-size: 14px; margin-bottom: 16px; }
@endsection

@section('body')
<div class="page-content">
    <div class="page-container">

        <div style="margin-bottom: 4px; font-size: 13px;">
            @if($ku->pipeline_job_id)
                <a href="{{ route('dashboard.knowledge-units', $ku->pipeline_job_id) }}" style="color: #0071e3; text-decoration: none;">&larr; {{ __('ui.back_to_knowledge_units') }}</a>
            @else
                <a href="{{ route('workspace.index') }}" style="color: #0071e3; text-decoration: none;">&larr; {{ __('ui.back_to_workspace') }}</a>
            @endif
        </div>

        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 4px;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <h1 style="font-size: 20px; font-weight: 600;">KU #{{ $ku->id }} — {{ $ku->topic }}</h1>
                @if($ku->isManual())
                    <span class="badge" style="background: #e8f0fe; color: #1a73e8; font-size: 11px; padding: 2px 8px;">{{ __('ui.source_type_manual') }}</span>
                @endif
            </div>
            <span class="badge badge-{{ $ku->review_status }}" style="font-size: 12px; padding: 3px 10px;">{{ $ku->review_status }}</span>
        </div>
        <p style="color: #5f6368; font-size: 13px; margin-bottom: 20px;">
            {{ $ku->intent }} &middot; v{{ $ku->version }}
            @if($ku->row_count > 0) &middot; {{ $ku->row_count }} rows @endif
            @if($ku->cluster_id) &middot; Cluster #{{ $ku->cluster_id }} @endif
        </p>

        @if(session('success'))
            <div class="flash-success">&#10003; {{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="flash-error">&#10007; {{ session('error') }}</div>
        @endif

        {{-- Status toggle: approved / excluded --}}
        <div class="card" style="display: flex; justify-content: space-between; align-items: center;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <form method="POST" action="{{ route('knowledge-units.review', $ku) }}" style="display: inline;">
                    @csrf
                    @if($ku->review_status === 'approved')
                        <input type="hidden" name="new_status" value="draft">
                        <button type="submit" class="btn btn-outline" style="gap: 6px;">
                            ✅ {{ __('ui.approved') }} &mdash; {{ __('ui.click_to_exclude') }}
                        </button>
                    @else
                        <input type="hidden" name="new_status" value="approved">
                        <button type="submit" class="btn btn-green" style="gap: 6px;">
                            ⬜ {{ __('ui.excluded') }} &mdash; {{ __('ui.click_to_approve') }}
                        </button>
                    @endif
                </form>
            </div>
            <a href="{{ route('knowledge-units.versions', $ku) }}" class="btn btn-sm btn-outline">{{ __('ui.version_history') }} ({{ $ku->versions->count() }})</a>
        </div>

        {{-- Edit form --}}
        <div class="card">
            <h2>{{ __('ui.edit') }}</h2>

            @if(!$ku->isEditable())
                <div class="locked-notice">{{ __('ui.ku_locked_hint') }}</div>
            @endif

            <form method="POST" action="{{ route('knowledge-units.update', $ku) }}">
                @csrf
                @method('PUT')

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="topic">{{ __('ui.topic') }}</label>
                        <input class="form-input" type="text" id="topic" name="topic" value="{{ $ku->topic }}" @if(!$ku->isEditable()) disabled @endif>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="intent">{{ __('ui.intent') }}</label>
                        <input class="form-input" type="text" id="intent" name="intent" value="{{ $ku->intent }}" @if(!$ku->isEditable()) disabled @endif>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="summary">{{ __('ui.summary') }}</label>
                    <textarea class="form-input" id="summary" name="summary" rows="3" @if(!$ku->isEditable()) disabled @endif>{{ $ku->summary }}</textarea>
                </div>
                <div class="form-group">
                    <label class="form-label" for="cause_summary">{{ __('ui.cause_summary') }}</label>
                    <textarea class="form-input" id="cause_summary" name="cause_summary" rows="3" @if(!$ku->isEditable()) disabled @endif>{{ $ku->cause_summary }}</textarea>
                </div>
                <div class="form-group">
                    <label class="form-label" for="resolution_summary">{{ __('ui.resolution_summary') }}</label>
                    <textarea class="form-input" id="resolution_summary" name="resolution_summary" rows="3" @if(!$ku->isEditable()) disabled @endif>{{ $ku->resolution_summary }}</textarea>
                </div>
                <div class="form-group">
                    <label class="form-label" for="notes">{{ __('ui.notes') }}</label>
                    <textarea class="form-input" id="notes" name="notes" rows="2" @if(!$ku->isEditable()) disabled @endif>{{ $ku->notes }}</textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="reference_url">{{ __('ui.reference_url') }}</label>
                        <input class="form-input" type="url" id="reference_url" name="reference_url" value="{{ $ku->reference_url }}" placeholder="https://docs.example.com/..." @if(!$ku->isEditable()) disabled @endif>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="response_mode">{{ __('ui.response_mode') }}</label>
                        <select class="form-input" id="response_mode" name="response_mode" @if(!$ku->isEditable()) disabled @endif>
                            <option value="answer" {{ ($ku->response_mode ?? 'answer') === 'answer' ? 'selected' : '' }}>{{ __('ui.response_mode_answer') }}</option>
                            <option value="link_only" {{ ($ku->response_mode ?? 'answer') === 'link_only' ? 'selected' : '' }}>{{ __('ui.response_mode_link_only') }}</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="edit_comment">{{ __('ui.edit_comment') }}</label>
                    <input class="form-input" type="text" id="edit_comment" name="edit_comment" placeholder="What did you change?" @if(!$ku->isEditable()) disabled @endif>
                </div>

                @if($ku->isEditable())
                    <button type="submit" class="btn btn-primary">{{ __('ui.save_changes') }} (creates v{{ $ku->version + 1 }})</button>
                @endif
            </form>
        </div>

        {{-- Metadata --}}
        <div class="card">
            <h2>{{ __('ui.metadata') }}</h2>
            <div class="ku-meta">
                <div>{{ __('ui.row_count') }}: <strong>{{ $ku->row_count }}</strong></div>
                <div>{{ __('ui.confidence') }}: <strong>{{ $ku->confidence }}</strong></div>
                <div>{{ __('ui.version') }}: <strong>{{ $ku->version }}</strong></div>
                @if($ku->pipeline_job_id)
                    <div>{{ __('ui.pipeline_job') }}: <strong>#{{ $ku->pipeline_job_id }}</strong></div>
                @else
                    <div>{{ __('ui.source') }}: <strong>{{ __('ui.source_type_' . $ku->source_type) }}</strong></div>
                @endif
                <div>{{ __('ui.created') }}: <strong>{{ $ku->created_at->format('Y-m-d H:i') }}</strong></div>
                @if($ku->edited_at)
                    <div>{{ __('ui.last_edited') }}: <strong>{{ $ku->edited_at->format('Y-m-d H:i') }}</strong></div>
                @endif
            </div>
            @if($ku->keywords_json)
                <label class="form-label" style="margin-bottom: 8px;">{{ __('ui.keywords') }}</label>
                <div class="keywords">
                    @foreach($ku->keywords_json as $kw)
                        <span class="keyword">{{ $kw }}</span>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Typical cases --}}
        @if($ku->typical_cases_json && count($ku->typical_cases_json) > 0)
            <div class="card">
                <h2>{{ __('ui.typical_cases') }}</h2>
                @foreach($ku->typical_cases_json as $i => $case)
                    <div class="typical-case">
                        <span style="font-size: 11px; font-weight: 600; color: #5f6368;">{{ __('ui.case_label', ['number' => $i + 1]) }}</span><br>
                        {{ $case }}
                    </div>
                @endforeach
            </div>
        @endif

    </div>
</div>
@endsection
