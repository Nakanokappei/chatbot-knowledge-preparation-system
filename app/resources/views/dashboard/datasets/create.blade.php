{{-- Create knowledge dataset: name/description form + scrollable approved KU selection list. --}}
@extends('layouts.app')
@section('title', __('ui.create_knowledge_dataset'))

@section('extra-styles')
    .form-label { display: block; font-size: 13px; font-weight: 500; color: #5f6368; margin-bottom: 4px; }
    .form-input { width: 100%; padding: 9px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 14px; font-family: inherit; margin-bottom: 16px; }
    .form-input:focus { outline: none; border-color: #0071e3; }
    textarea.form-input { min-height: 80px; resize: vertical; }
    .ku-list { max-height: 400px; overflow-y: auto; border: 1px solid #d2d2d7; border-radius: 8px; margin-bottom: 16px; }
    .ku-list-bar { padding: 8px 14px; background: #f5f5f7; border-bottom: 1px solid #d2d2d7; font-size: 13px; display: flex; gap: 12px; align-items: center; border-radius: 8px 8px 0 0; }
    .ku-list-bar a { color: #0071e3; cursor: pointer; text-decoration: none; }
    .ku-item { padding: 10px 14px; border-bottom: 1px solid #f0f0f2; display: flex; align-items: flex-start; gap: 10px; cursor: pointer; }
    .ku-item:last-child { border-bottom: none; }
    .ku-item:hover { background: #f5f5f7; }
    .ku-item input[type="checkbox"] { flex-shrink: 0; margin-top: 3px; }
    .ku-topic { font-weight: 500; font-size: 14px; }
    .ku-meta { font-size: 12px; color: #5f6368; margin-top: 2px; }
    .page-header h1 { font-size: 20px; font-weight: 600; margin-bottom: 20px; }
@endsection

@section('body')
<div class="page-content">
    <div class="page-container" style="max-width: 720px;">
        <h1 style="font-size: 20px; font-weight: 600; margin-bottom: 20px;">{{ __('ui.create_knowledge_dataset') }}</h1>

        <div class="card">
            <form method="POST" action="{{ route('kd.store') }}">
                @csrf

                <label class="form-label" for="name">{{ __('ui.dataset_name') }}</label>
                <input class="form-input" type="text" name="name" id="name" value="{{ old('name') }}" required placeholder="e.g. Customer Support v1">

                <label class="form-label" for="description">{{ __('ui.description_optional') }}</label>
                <textarea class="form-input" name="description" id="description" placeholder="What is this dataset for?">{{ old('description') }}</textarea>

                @error('knowledge_unit_ids')
                    <div style="color: #ff3b30; font-size: 13px; margin-bottom: 12px;">{{ $message }}</div>
                @enderror

                <label class="form-label">{{ __('ui.select_approved_kus', ['count' => $approvedKUs->count()]) }}</label>

                <div class="ku-list">
                    <div class="ku-list-bar">
                        <a onclick="document.querySelectorAll('.ku-checkbox').forEach(c => c.checked = true); updateCount()">{{ __('ui.select_all_btn') }}</a>
                        <a onclick="document.querySelectorAll('.ku-checkbox').forEach(c => c.checked = false); updateCount()">{{ __('ui.deselect_all') }}</a>
                        <span id="selected-count" style="margin-left: auto; color: #5f6368;">0 {{ __('ui.selected') }}</span>
                    </div>
                    @forelse($approvedKUs as $ku)
                        <label class="ku-item">
                            <input type="checkbox" name="knowledge_unit_ids[]" value="{{ $ku->id }}"
                                   class="ku-checkbox" onchange="updateCount()"
                                   {{ in_array($ku->id, old('knowledge_unit_ids', [])) ? 'checked' : '' }}>
                            <div>
                                <div class="ku-topic">{{ $ku->topic }}</div>
                                <div class="ku-meta">{{ $ku->intent }} &middot; {{ $ku->row_count }} rows &middot; {{ number_format($ku->confidence * 100) }}% &middot; Job #{{ $ku->pipeline_job_id }}</div>
                            </div>
                        </label>
                    @empty
                        <div style="padding: 20px; text-align: center; color: #5f6368; font-size: 13px;">{{ __('ui.no_approved_kus') }}</div>
                    @endforelse
                </div>

                <button type="submit" class="btn btn-primary" {{ $approvedKUs->isEmpty() ? 'disabled' : '' }}>
                    {{ __('ui.create_dataset') }}
                </button>
            </form>
        </div>
    </div>
</div>
@endsection

@section('scripts')
function updateCount() {
    const n = document.querySelectorAll('.ku-checkbox:checked').length;
    document.getElementById('selected-count').textContent = n + ' {{ __('ui.selected') }}';
}
updateCount();
@endsection
