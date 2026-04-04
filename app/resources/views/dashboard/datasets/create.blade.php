{{-- Create knowledge package: select embeddings (not individual KUs). All approved KUs from selected embeddings are included. --}}
@extends('layouts.app')
@section('title', __('ui.create_knowledge_dataset'))

@section('extra-styles')
    .form-label { display: block; font-size: 13px; font-weight: 500; color: #5f6368; margin-bottom: 4px; }
    .form-input { width: 100%; padding: 9px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 14px; font-family: inherit; margin-bottom: 16px; }
    .form-input:focus { outline: none; border-color: #0071e3; }
    textarea.form-input { min-height: 60px; resize: vertical; }
    .emb-list { border: 1px solid #d2d2d7; border-radius: 8px; margin-bottom: 16px; overflow: hidden; }
    .emb-item { padding: 12px 14px; border-bottom: 1px solid #f0f0f2; display: flex; align-items: center; gap: 12px; cursor: pointer; }
    .emb-item:last-child { border-bottom: none; }
    .emb-item:hover { background: #f5f5f7; }
    .emb-item input[type="checkbox"] { flex-shrink: 0; }
    .emb-name { font-weight: 500; font-size: 14px; }
    .emb-meta { font-size: 12px; color: #5f6368; margin-top: 2px; }
    .emb-badge { font-size: 11px; font-weight: 600; background: #d4edda; color: #155724; padding: 2px 8px; border-radius: 10px; }
@endsection

@section('body')
<div class="page-content">
    <div class="page-container" style="max-width: 640px;">
        <h1 style="font-size: 20px; font-weight: 600; margin-bottom: 20px;">{{ __('ui.create_knowledge_dataset') }}</h1>

        <div class="card">
            <form method="POST" action="{{ route('kp.store') }}">
                @csrf

                <label class="form-label" for="name">{{ __('ui.dataset_name') }}</label>
                <input class="form-input" type="text" name="name" id="name" value="{{ old('name') }}" required placeholder="e.g. Customer Support v1">

                <label class="form-label" for="description">{{ __('ui.description_optional') }}</label>
                <textarea class="form-input" name="description" id="description" rows="2" placeholder="{{ __('ui.package_description_placeholder') }}">{{ old('description') }}</textarea>

                @error('embedding_ids')
                    <div style="color: #ff3b30; font-size: 13px; margin-bottom: 12px;">{{ $message }}</div>
                @enderror

                <label class="form-label">{{ __('ui.select_embeddings') }}</label>
                <p style="font-size: 12px; color: #86868b; margin-bottom: 8px;">{{ __('ui.select_embeddings_hint') }}</p>

                <div class="emb-list">
                    @forelse($embeddings as $emb)
                        <label class="emb-item">
                            <input type="checkbox" name="embedding_ids[]" value="{{ $emb->id }}"
                                   {{ in_array($emb->id, old('embedding_ids', [])) ? 'checked' : '' }}>
                            <div style="flex: 1;">
                                <div class="emb-name">{{ $emb->name }}</div>
                                <div class="emb-meta">{{ $emb->dataset->name ?? 'N/A' }}</div>
                            </div>
                            <span class="emb-badge">{{ $emb->approved_ku_count }} KUs</span>
                        </label>
                    @empty
                        <div style="padding: 20px; text-align: center; color: #5f6368; font-size: 13px;">{{ __('ui.no_approved_kus') }}</div>
                    @endforelse
                </div>

                <button type="submit" class="btn btn-primary" {{ $embeddings->isEmpty() ? 'disabled' : '' }}>
                    {{ __('ui.create_dataset') }}
                </button>
            </form>
        </div>
    </div>
</div>
@endsection
