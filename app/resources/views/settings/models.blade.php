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
                <a href="https://aws.amazon.com/bedrock/pricing/" target="_blank" rel="noopener" style="color: #0071e3; text-decoration: none;">AWS Bedrock Pricing ↗</a>
            </p>

            @if(session('success'))
                <div style="background: #d4edda; color: #155724; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px;">✓ {{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div style="background: #f8d7da; color: #721c24; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px;">✗ {{ session('error') }}</div>
            @endif

            {{-- Add LLM model form: dropdown of available Bedrock models with auto-fill display name --}}
            <div class="card">
                <h2>{{ __('ui.add_model') }}</h2>
                <form method="POST" action="{{ route('settings.models.store') }}">
                    @csrf
                    <div class="form-row">
                        <div class="form-group" style="flex: 2;">
                            <label for="model_id">{{ __('ui.select_model') }}</label>
                            <select id="model_id" name="model_id" required
                                style="padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 14px; width: 100%;"
                                onchange="updateDisplayName(this)">
                                <option value="">{{ __('ui.choose_model') }}</option>
                                @php $prevProvider = ''; @endphp
                                @foreach($bedrockModels as $bedrockModel)
                                    @if($bedrockModel['provider'] !== $prevProvider)
                                        @if($prevProvider !== '') </optgroup> @endif
                                        <optgroup label="{{ $bedrockModel['provider'] }}">
                                        @php $prevProvider = $bedrockModel['provider']; @endphp
                                    @endif
                                    @if(!$models->contains('model_id', $bedrockModel['model_id']))
                                    @php $modelPricing = \App\Http\Controllers\SettingsController::findPricingForModel($pricing, $bedrockModel['model_id']); @endphp
                                    <option value="{{ $bedrockModel['model_id'] }}"
                                        data-display="{{ $bedrockModel['provider'] }} {{ $bedrockModel['display_name'] }}">
                                        {{ $bedrockModel['display_name'] }}@if($modelPricing) — In: ${{ number_format($modelPricing['input'], 6) }} / Out: ${{ number_format($modelPricing['output'] ?? 0, 6) }} per {{ $modelPricing['unit'] ?? '1K tokens' }}@endif
                                    </option>
                                    @endif
                                @endforeach
                                @if($prevProvider !== '') </optgroup> @endif
                            </select>
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label for="display_name">{{ __('ui.display_name_auto') }}</label>
                            <input type="text" id="display_name" name="display_name" placeholder="Auto-generated from selection">
                        </div>
                        <div><button type="submit" class="btn btn-primary">{{ __('ui.add') }}</button></div>
                    </div>
                    @if(empty($bedrockModels))
                        <p style="color: #ff9500; font-size: 12px; margin-top: 8px;">
                            Could not fetch models from AWS Bedrock. Check AWS credentials.
                        </p>
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
                            <tr><th>{{ __('ui.display_name') }}</th><th>{{ __('ui.model_id') }}</th><th>{{ __('ui.input_cost') }}</th><th>{{ __('ui.output_cost') }}</th><th>{{ __('ui.status') }}</th><th></th></tr>
                        </thead>
                        <tbody>
                            @foreach($models as $model)
                            <tr @if(!$model->is_active) style="opacity: 0.5;" @endif>
                                <td style="font-weight: 500;">{{ $model->display_name }}</td>
                                <td><span class="mono">{{ $model->model_id }}</span></td>
                                <td style="white-space: nowrap; font-size: 12px;">
                                    <form method="POST" action="{{ route('settings.models.update', $model) }}" style="display: inline; margin: 0;">
                                        @csrf @method('PUT')
                                        <input type="hidden" name="action" value="update_pricing">
                                        <span style="color: #5f6368;">$</span><input type="number" name="input_price_per_1m" value="{{ $model->input_price_per_1m }}"
                                            step="0.01" min="0" class="price-input"
                                            onchange="savePricing(this)">
                                        <span style="color: #5f6368;">/1M</span>
                                    </form>
                                </td>
                                <td style="white-space: nowrap; font-size: 12px;">
                                    <form method="POST" action="{{ route('settings.models.update', $model) }}" style="display: inline; margin: 0;">
                                        @csrf @method('PUT')
                                        <input type="hidden" name="action" value="update_pricing">
                                        <span style="color: #5f6368;">$</span><input type="number" name="output_price_per_1m" value="{{ $model->output_price_per_1m }}"
                                            step="0.01" min="0" class="price-input"
                                            onchange="savePricing(this)">
                                        <span style="color: #5f6368;">/1M</span>
                                    </form>
                                </td>
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
                                            <form method="POST" action="{{ route('settings.models.update', $model) }}">@csrf @method('PUT')<input type="hidden" name="action" value="set_default"><button type="submit" class="btn btn-sm btn-outline btn-set-default">{{ __('ui.set_default') }}</button></form>
                                            <form method="POST" action="{{ route('settings.models.update', $model) }}">@csrf @method('PUT')<input type="hidden" name="action" value="toggle_active"><button type="submit" class="btn btn-sm btn-outline">{{ $model->is_active ? __('ui.deactivate') : __('ui.activate') }}</button></form>
                                            <form method="POST" action="{{ route('settings.models.destroy', $model) }}" onsubmit="return confirm('Delete {{ $model->display_name }}?')">@csrf @method('DELETE')<button type="submit" class="btn btn-sm btn-danger">{{ __('ui.delete') }}</button></form>
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
                <form method="POST" action="{{ route('settings.embedding.store') }}">
                    @csrf
                    <div class="form-row">
                        <div class="form-group" style="flex: 2;">
                            <label for="emb_model_id">{{ __('ui.select_model') }}</label>
                            <select id="emb_model_id" name="model_id" required
                                style="padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 14px; width: 100%;"
                                onchange="updateEmbDisplayName(this)">
                                <option value="">{{ __('ui.choose_embedding_model') }}</option>
                                @php $prevEmbProvider = ''; @endphp
                                @foreach($bedrockEmbeddingModels as $bm)
                                    @if($bm['provider'] !== $prevEmbProvider)
                                        @if($prevEmbProvider !== '') </optgroup> @endif
                                        <optgroup label="{{ $bm['provider'] }}">
                                        @php $prevEmbProvider = $bm['provider']; @endphp
                                    @endif
                                    @if(!$embeddingModels->contains('model_id', $bm['model_id']))
                                    @php $ep = \App\Http\Controllers\SettingsController::findPricingForModel($pricing, $bm['model_id']); @endphp
                                    <option value="{{ $bm['model_id'] }}"
                                        data-display="{{ $bm['provider'] }} {{ $bm['display_name'] }}">
                                        {{ $bm['display_name'] }}@if($ep && $ep['input']) — ${{ number_format($ep['input'], 6) }} per {{ $ep['unit'] ?? '1K tokens' }}@endif
                                    </option>
                                    @endif
                                @endforeach
                                @if($prevEmbProvider !== '') </optgroup> @endif
                            </select>
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label for="emb_display_name">{{ __('ui.display_name') }}</label>
                            <input type="text" id="emb_display_name" name="display_name" placeholder="Auto-generated">
                        </div>
                        <div class="form-group" style="width: 90px; flex: none;">
                            <label for="emb_dimension">{{ __('ui.dimension') }}</label>
                            <input type="number" id="emb_dimension" name="dimension" value="1024" min="1" max="8192"
                                style="padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 14px; width: 100%;">
                        </div>
                        <div><button type="submit" class="btn btn-primary">{{ __('ui.add') }}</button></div>
                    </div>
                </form>
            </div>

            <div class="card">
                <h2>{{ __('ui.registered_models') }}</h2>
                @if($embeddingModels->isEmpty())
                    <div class="empty">{{ __('ui.no_embedding_models') }}</div>
                @else
                    <table>
                        <thead>
                            <tr><th>{{ __('ui.display_name') }}</th><th>{{ __('ui.model_id') }}</th><th>{{ __('ui.dimension') }}</th><th>{{ __('ui.cost') }}</th><th>{{ __('ui.status') }}</th><th></th></tr>
                        </thead>
                        <tbody>
                            @foreach($embeddingModels as $em)
                            <tr @if(!$em->is_active) style="opacity: 0.5;" @endif>
                                <td style="font-weight: 500;">{{ $em->display_name }}</td>
                                <td><span class="mono">{{ $em->model_id }}</span></td>
                                <td style="text-align: center;">{{ $em->dimension }}</td>
                                <td style="white-space: nowrap; font-size: 12px;">
                                    <form method="POST" action="{{ route('settings.embedding.update', $em) }}" style="display: inline; margin: 0;">
                                        @csrf @method('PUT')
                                        <input type="hidden" name="action" value="update_pricing">
                                        <span style="color: #5f6368;">$</span><input type="number" name="input_price_per_1m" value="{{ $em->input_price_per_1m }}"
                                            step="0.0001" min="0" class="price-input"
                                            onchange="savePricing(this)">
                                        <span style="color: #5f6368;">/1M</span>
                                    </form>
                                </td>
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

        // Auto-fill display name for embedding model dropdown
        function updateEmbDisplayName(select) {
            const option = select.options[select.selectedIndex];
            const displayName = option.getAttribute('data-display') || '';
            document.getElementById('emb_display_name').value = displayName;
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
