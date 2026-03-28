{{-- LLM and embedding model settings page: register AWS Bedrock models, set pricing,
     toggle active/default status. Pricing updates save via AJAX without page reload.
     Separated into two sections: LLM models and embedding models. --}}
@extends('layouts.app')
@section('title', 'Settings — LLM Models')

@section('extra-styles')
        input[type="text"] { padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 14px; width: 100%; }
        .form-row { display: flex; gap: 12px; align-items: flex-end; }
        .form-group { flex: 1; }
        .form-group label { display: block; font-size: 12px; color: #5f6368; margin-bottom: 4px; font-weight: 500; }
        .mono { font-family: 'SF Mono', 'Menlo', monospace; font-size: 12px; color: #5f6368; }
        table th { white-space: nowrap; }
        .actions { display: flex; flex-direction: column; gap: 4px; }
        .actions form { margin: 0; }
        .actions .btn { display: block; width: 100%; text-align: center; white-space: nowrap; padding: 4px 10px; font-size: 12px; box-sizing: border-box; }
        .actions .btn-set-default { border-color: #0071e3; color: #0071e3; }
        .badge-active { background: #d4edda; color: #155724; }
        .badge-inactive { background: #f0f0f2; color: #5f6368; }
        .price-input { width: 70px; padding: 2px 4px; border: 1px solid #d2d2d7; border-radius: 4px; font-size: 12px; }
        .price-input.saved { border-color: #34c759; transition: border-color 0.3s; }
@endsection

@section('body')
    <div class="page-content">
        <div class="page-container">
            <h1 style="font-size: 20px; font-weight: 600; margin-bottom: 4px;">{{ __('ui.llm_models') }}</h1>
            <p style="color: #5f6368; font-size: 13px; margin-bottom: 24px;">{{ __('ui.llm_models_desc') }}
                @if(auth()->user()->isSystemAdmin())
                <a href="https://aws.amazon.com/bedrock/pricing/" target="_blank" rel="noopener" style="color: #0071e3; text-decoration: none;">AWS Bedrock Pricing ↗</a>
                @endif
            </p>

            @if(session('success'))
                <div style="background: #d4edda; color: #155724; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px;">✓ {{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div style="background: #f8d7da; color: #721c24; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px;">✗ {{ session('error') }}</div>
            @endif

            {{-- Add LLM model form --}}
            <div class="card">
                <h2>{{ __('ui.add_model') }}</h2>
                <form method="POST" action="{{ route('settings.store') }}">
                    @csrf
                    @if($systemLlmModels->isNotEmpty())
                    {{-- System templates available: restrict selection to approved models only --}}
                    <div class="form-row">
                        <div class="form-group" style="flex: 2;">
                            <label for="model_id">{{ __('ui.select_model') }}</label>
                            <select id="model_id" name="model_id" required
                                style="padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 14px; width: 100%;"
                                onchange="updateDisplayName(this)">
                                <option value="">{{ __('ui.select_from_system_models') }}</option>
                                @foreach($systemLlmModels as $sysModel)
                                    @if(!$models->contains('model_id', $sysModel->model_id))
                                    <option value="{{ $sysModel->model_id }}"
                                        data-display="{{ $sysModel->display_name }}">
                                        {{ $sysModel->display_name }}
                                    </option>
                                    @endif
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label for="display_name">{{ __('ui.display_name_auto') }}</label>
                            <input type="text" id="display_name" name="display_name" placeholder="Auto-generated from selection">
                        </div>
                        <div><button type="submit" class="btn btn-primary">{{ __('ui.add') }}</button></div>
                    </div>
                    @else
                    {{-- No system templates defined: show notice instead of form --}}
                    <p style="font-size: 13px; color: #5f6368; margin: 0;">{{ __('ui.no_system_models_hint') }}</p>
                    @endif
                </form>
            </div>

            {{-- Registered LLM models table: editable pricing, status toggle, set default --}}
            <div class="card">
                <h2>{{ __('ui.registered_models') }}</h2>
                @if($models->isEmpty())
                    <div class="empty">{{ __('ui.no_models') }}</div>
                @else
                    <table>
                        <thead>
                            <tr><th>{{ __('ui.display_name') }}</th><th>{{ __('ui.model_id') }}</th><th>{{ __('ui.status') }}</th><th></th></tr>
                        </thead>
                        <tbody>
                            @foreach($models as $model)
                            <tr @if(!$model->is_active) style="opacity: 0.5;" @endif>
                                <td style="font-weight: 500;">{{ $model->display_name }}</td>
                                <td><span class="mono">{{ $model->model_id }}</span></td>
                                <td>
                                    @if($model->is_default)
                                        <span class="badge" style="background: #0071e3; color: #fff;">{{ __('ui.default') }}</span>
                                    @else
                                        <span class="badge {{ $model->is_active ? 'badge-active' : 'badge-inactive' }}">
                                            {{ $model->is_active ? __('ui.active') : __('ui.inactive') }}
                                        </span>
                                    @endif
                                </td>
                                <td>
                                    <div class="actions">
                                        @if(!$model->is_default)
                                            <form method="POST" action="{{ route('settings.update', $model) }}">@csrf @method('PUT')<input type="hidden" name="action" value="set_default"><button type="submit" class="btn btn-sm btn-outline btn-set-default">{{ __('ui.set_default') }}</button></form>
                                            <form method="POST" action="{{ route('settings.update', $model) }}">@csrf @method('PUT')<input type="hidden" name="action" value="toggle_active"><button type="submit" class="btn btn-sm btn-outline">{{ $model->is_active ? __('ui.deactivate') : __('ui.activate') }}</button></form>
                                            <form method="POST" action="{{ route('settings.destroy', $model) }}" onsubmit="return confirm('Delete {{ $model->display_name }}?')">@csrf @method('DELETE')<button type="submit" class="btn btn-sm btn-danger">{{ __('ui.delete') }}</button></form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            {{-- Embedding models section: separate registration and management for embedding models --}}
            <hr style="border: none; border-top: 1px solid #e0e0e2; margin: 40px 0 24px;">
            <h1 style="font-size: 20px; font-weight: 600; margin-bottom: 4px;">{{ __('ui.embedding_models') }}</h1>
            <p style="color: #5f6368; font-size: 13px; margin-bottom: 24px;">{{ __('ui.embedding_models_desc') }}</p>

            <div class="card">
                <h2>{{ __('ui.add_embedding_model') }}</h2>
                @if($systemEmbeddingModels->isNotEmpty())
                {{-- System templates available: restrict to approved embedding models only --}}
                <form method="POST" action="{{ route('settings.embedding.store') }}">
                    @csrf
                    <div class="form-row">
                        <div class="form-group" style="flex: 2;">
                            <label for="emb_model_id">{{ __('ui.select_model') }}</label>
                            <select id="emb_model_id" name="model_id" required
                                style="padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 14px; width: 100%;"
                                onchange="updateEmbDisplayName(this)">
                                <option value="">{{ __('ui.select_from_system_models') }}</option>
                                @foreach($systemEmbeddingModels as $sysEmb)
                                    @if(!$embeddingModels->contains('model_id', $sysEmb->model_id))
                                    <option value="{{ $sysEmb->model_id }}"
                                        data-display="{{ $sysEmb->display_name }}"
                                        data-dimension="{{ $sysEmb->dimension }}">
                                        {{ $sysEmb->display_name }} ({{ $sysEmb->dimension }}d)
                                    </option>
                                    @endif
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label for="emb_display_name">{{ __('ui.display_name') }}</label>
                            <input type="text" id="emb_display_name" name="display_name" placeholder="Auto-generated">
                        </div>
                        <div class="form-group" style="width: 90px; flex: none;">
                            <label for="emb_dimension">{{ __('ui.dimension') }}</label>
                            {{-- Dimension is auto-filled from the template and locked; JS sets the value on selection. --}}
                            <input type="number" id="emb_dimension" name="dimension"
                                value="" min="1" max="8192" readonly
                                placeholder="Auto"
                                style="padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 14px; width: 100%; background: #f0f0f2; cursor: not-allowed;">
                        </div>
                        <div><button type="submit" class="btn btn-primary">{{ __('ui.add') }}</button></div>
                    </div>
                </form>
                @else
                {{-- No system templates defined: show notice instead of form --}}
                <p style="font-size: 13px; color: #5f6368; margin: 0;">{{ __('ui.no_system_models_hint') }}</p>
                @endif
            </div>

            <div class="card">
                <h2>{{ __('ui.registered_models') }}</h2>
                @if($embeddingModels->isEmpty())
                    <div class="empty">{{ __('ui.no_embedding_models') }}</div>
                @else
                    <table>
                        <thead>
                            <tr><th>{{ __('ui.display_name') }}</th><th>{{ __('ui.model_id') }}</th><th>{{ __('ui.dimension') }}</th><th>{{ __('ui.status') }}</th><th></th></tr>
                        </thead>
                        <tbody>
                            @foreach($embeddingModels as $em)
                            <tr @if(!$em->is_active) style="opacity: 0.5;" @endif>
                                <td style="font-weight: 500;">{{ $em->display_name }}</td>
                                <td><span class="mono">{{ $em->model_id }}</span></td>
                                <td style="text-align: center;">{{ $em->dimension }}</td>
                                <td>
                                    @if($em->is_default)
                                        <span class="badge" style="background: #0071e3; color: #fff;">{{ __('ui.default') }}</span>
                                    @else
                                        <span class="badge {{ $em->is_active ? 'badge-active' : 'badge-inactive' }}">
                                            {{ $em->is_active ? __('ui.active') : __('ui.inactive') }}
                                        </span>
                                    @endif
                                </td>
                                <td>
                                    <div class="actions">
                                        @if(!$em->is_default)
                                            <form method="POST" action="{{ route('settings.embedding.update', $em) }}">@csrf @method('PUT')<input type="hidden" name="action" value="set_default"><button type="submit" class="btn btn-sm btn-outline btn-set-default">{{ __('ui.set_default') }}</button></form>
                                            <form method="POST" action="{{ route('settings.embedding.update', $em) }}">@csrf @method('PUT')<input type="hidden" name="action" value="toggle_active"><button type="submit" class="btn btn-sm btn-outline">{{ $em->is_active ? __('ui.deactivate') : __('ui.activate') }}</button></form>
                                            <form method="POST" action="{{ route('settings.embedding.destroy', $em) }}" onsubmit="return confirm('Delete {{ $em->display_name }}?')">@csrf @method('DELETE')<button type="submit" class="btn btn-sm btn-danger">{{ __('ui.delete') }}</button></form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>
@endsection

@section('scripts')
        // Auto-fill display name for LLM model dropdown
        function updateDisplayName(select) {
            const option = select.options[select.selectedIndex];
            const displayName = option.getAttribute('data-display') || '';
            document.getElementById('display_name').value = displayName;
        }

        // Auto-fill display name and dimension for embedding model dropdown
        function updateEmbDisplayName(select) {
            const option = select.options[select.selectedIndex];
            const displayName = option.getAttribute('data-display') || '';
            document.getElementById('emb_display_name').value = displayName;

            // Auto-fill dimension from system template data attribute
            const dimension = option.getAttribute('data-dimension');
            const dimInput = document.getElementById('emb_dimension');
            if (dimension) {
                dimInput.value = dimension;
                dimInput.readOnly = true;
                dimInput.style.backgroundColor = '#f0f0f2';
            } else {
                dimInput.readOnly = false;
                dimInput.style.backgroundColor = '';
            }
        }

        // Save pricing via AJAX without page reload
        function savePricing(input) {
            const form = input.closest('form');
            const formData = new FormData(form);
            // Use getAttribute to avoid name collision with <input name="action">
            const actionUrl = form.getAttribute('action');
            fetch(actionUrl, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            }).then(response => {
                if (response.ok || response.redirected) {
                    input.classList.add('saved');
                    setTimeout(() => input.classList.remove('saved'), 1500);
                } else {
                    input.style.borderColor = '#ff3b30';
                }
            }).catch(() => {
                input.style.borderColor = '#ff3b30';
            });
        }
@endsection
