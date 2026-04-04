{{-- Manual QA registration form: create a Knowledge Unit without the pipeline. --}}
@extends('layouts.app')
@section('title', __('ui.manual_qa_create'))

@section('extra-styles')
    .form-label { display: block; font-size: 13px; font-weight: 500; color: #5f6368; margin-bottom: 4px; }
    .form-input { width: 100%; padding: 9px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 14px; font-family: inherit; }
    .form-input:focus { outline: none; border-color: #0071e3; }
    textarea.form-input { resize: vertical; min-height: 80px; }
    .form-group { margin-bottom: 16px; }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .form-hint { font-size: 12px; color: #86868b; margin-top: 4px; }
    .section-header { font-size: 15px; font-weight: 600; color: #1d1d1f; margin: 24px 0 12px 0; padding-bottom: 8px; border-bottom: 1px solid #f0f0f2; }
    .section-header:first-child { margin-top: 0; }
    .flash-error { background: #f8d7da; color: #721c24; padding: 12px 16px; border-radius: 8px; font-size: 14px; margin-bottom: 16px; }
    .collapsible-trigger { cursor: pointer; user-select: none; display: flex; align-items: center; gap: 6px; }
    .collapsible-trigger::before { content: '\25B6'; font-size: 10px; transition: transform 0.2s; }
    .collapsible-trigger.open::before { transform: rotate(90deg); }
    .collapsible-body { display: none; }
    .collapsible-body.open { display: block; }
    .required-mark { color: #ff3b30; }
@endsection

@section('body')
<div class="page-content">
    <div class="page-container" style="max-width: 720px;">

        <div style="margin-bottom: 4px; font-size: 13px;">
            <a href="{{ route('workspace.index') }}" style="color: #0071e3; text-decoration: none;">&larr; {{ __('ui.back_to_workspace') }}</a>
        </div>

        <h1 style="font-size: 20px; font-weight: 600; margin-bottom: 4px;">{{ __('ui.manual_qa_create') }}</h1>
        <p style="color: #5f6368; font-size: 13px; margin-bottom: 20px;">{{ __('ui.manual_qa_description') }}</p>

        @if($errors->any())
            <div class="flash-error">
                @foreach($errors->all() as $error)
                    <div>&#10007; {{ $error }}</div>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('knowledge-units.store') }}">
            @csrf

            {{-- Section 1: Context Selection --}}
            <div class="card">
                <div class="section-header" style="margin-top: 0;">{{ __('ui.manual_qa_context') }}</div>

                <div class="form-group">
                    <label class="form-label" for="embedding_id">{{ __('ui.embedding') }} <span class="required-mark">*</span></label>
                    <select class="form-input" id="embedding_id" name="embedding_id" required>
                        <option value="">{{ __('ui.manual_qa_select_embedding') }}</option>
                        @foreach($embeddings as $emb)
                            <option value="{{ $emb->id }}" {{ old('embedding_id', $prefillEmbeddingId) == $emb->id ? 'selected' : '' }}>
                                {{ $emb->name }} ({{ $emb->dataset->name ?? 'N/A' }})
                            </option>
                        @endforeach
                    </select>
                    <p class="form-hint">{{ __('ui.manual_qa_embedding_hint') }}</p>
                </div>
            </div>

            {{-- Section 2: Core QA Fields --}}
            <div class="card">
                <div class="section-header" style="margin-top: 0;">{{ __('ui.manual_qa_core') }}</div>

                <div class="form-group">
                    <label class="form-label" for="question">{{ __('ui.manual_qa_question') }} <span class="required-mark">*</span></label>
                    <textarea class="form-input" id="question" name="question" rows="3" required maxlength="2000" placeholder="{{ __('ui.manual_qa_question_placeholder') }}">{{ old('question', $prefillQuestion) }}</textarea>
                </div>
                <div class="form-group">
                    <label class="form-label" for="resolution_summary">{{ __('ui.manual_qa_resolution') }} <span class="required-mark">*</span></label>
                    <textarea class="form-input" id="resolution_summary" name="resolution_summary" rows="4" required maxlength="5000" placeholder="{{ __('ui.manual_qa_resolution_placeholder') }}">{{ old('resolution_summary') }}</textarea>
                </div>
            </div>

            {{-- Section 3: Classification --}}
            <div class="card">
                <div class="section-header" style="margin-top: 0;">{{ __('ui.manual_qa_classification') }}</div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="topic">{{ __('ui.topic') }} <span class="required-mark">*</span></label>
                        <input class="form-input" type="text" id="topic" name="topic" value="{{ old('topic') }}" required maxlength="200" placeholder="{{ __('ui.manual_qa_topic_placeholder') }}">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="intent">{{ __('ui.intent') }} <span class="required-mark">*</span></label>
                        <input class="form-input" type="text" id="intent" name="intent" value="{{ old('intent') }}" required maxlength="200" placeholder="{{ __('ui.manual_qa_intent_placeholder') }}">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="summary">{{ __('ui.summary') }} <span class="required-mark">*</span></label>
                    <textarea class="form-input" id="summary" name="summary" rows="2" required maxlength="5000">{{ old('summary') }}</textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="primary_filter">{{ __('ui.primary_filter') }}</label>
                        <input class="form-input" type="text" id="primary_filter" name="primary_filter" value="{{ old('primary_filter') }}" maxlength="255" placeholder="{{ __('ui.manual_qa_filter_placeholder') }}">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="category">{{ __('ui.category') }}</label>
                        <input class="form-input" type="text" id="category" name="category" value="{{ old('category') }}" maxlength="255">
                    </div>
                </div>
            </div>

            {{-- Section 4: Link Guidance --}}
            <div class="card">
                <div class="section-header" style="margin-top: 0;">{{ __('ui.link_guidance') }}</div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="reference_url">{{ __('ui.reference_url') }}</label>
                        <input class="form-input" type="url" id="reference_url" name="reference_url" value="{{ old('reference_url') }}" maxlength="2048" placeholder="https://docs.example.com/...">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="response_mode">{{ __('ui.response_mode') }}</label>
                        <select class="form-input" id="response_mode" name="response_mode">
                            <option value="answer" {{ old('response_mode', 'answer') === 'answer' ? 'selected' : '' }}>{{ __('ui.response_mode_answer') }}</option>
                            <option value="link_only" {{ old('response_mode') === 'link_only' ? 'selected' : '' }}>{{ __('ui.response_mode_link_only') }}</option>
                        </select>
                    </div>
                </div>
            </div>

            {{-- Section 5: Detailed Information (collapsible) --}}
            <div class="card">
                <div class="section-header collapsible-trigger" style="margin-top: 0; cursor: pointer;" onclick="toggleDetails(this)">
                    {{ __('ui.manual_qa_details') }}
                </div>
                <div class="collapsible-body" id="details-section">
                    <div class="form-group">
                        <label class="form-label" for="symptoms">{{ __('ui.symptoms') }}</label>
                        <textarea class="form-input" id="symptoms" name="symptoms" rows="2">{{ old('symptoms') }}</textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="root_cause">{{ __('ui.root_cause') }}</label>
                        <textarea class="form-input" id="root_cause" name="root_cause" rows="2">{{ old('root_cause') }}</textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="cause_summary">{{ __('ui.cause_summary') }}</label>
                        <textarea class="form-input" id="cause_summary" name="cause_summary" rows="2">{{ old('cause_summary') }}</textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="notes">{{ __('ui.notes') }}</label>
                        <textarea class="form-input" id="notes" name="notes" rows="2">{{ old('notes') }}</textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="keywords">{{ __('ui.keywords') }}</label>
                        <input class="form-input" type="text" id="keywords" name="keywords" value="{{ old('keywords') }}" maxlength="1000" placeholder="{{ __('ui.manual_qa_keywords_placeholder') }}">
                        <p class="form-hint">{{ __('ui.manual_qa_keywords_hint') }}</p>
                    </div>
                </div>
            </div>

            {{-- Actions --}}
            <div style="display: flex; gap: 12px; align-items: center;">
                <button type="submit" class="btn btn-primary">{{ __('ui.manual_qa_save') }}</button>
                <a href="{{ route('workspace.index') }}" class="btn btn-outline">{{ __('ui.cancel') }}</a>
            </div>
        </form>

    </div>
</div>
@endsection

@section('scripts')
<script>
    // Toggle the collapsible details section
    function toggleDetails(trigger) {
        trigger.classList.toggle('open');
        var body = document.getElementById('details-section');
        body.classList.toggle('open');
    }
</script>
@endsection
