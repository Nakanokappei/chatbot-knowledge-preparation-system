@extends('layouts.app')
@section('title', 'Settings — LLM Models')

@section('extra-styles')
        input[type="text"] { padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 14px; width: 100%; }
        .form-row { display: flex; gap: 12px; align-items: flex-end; }
        .form-group { flex: 1; }
        .form-group label { display: block; font-size: 12px; color: #86868b; margin-bottom: 4px; font-weight: 500; }
        .mono { font-family: 'SF Mono', 'Menlo', monospace; font-size: 12px; color: #86868b; }
        .actions { display: flex; gap: 6px; }
        .badge-active { background: #d4edda; color: #155724; }
        .badge-inactive { background: #f0f0f2; color: #86868b; }
@endsection

@section('body')
    <div class="page-content">
        <div class="page-container">
            <h1 style="font-size: 20px; font-weight: 600; margin-bottom: 4px;">LLM Models</h1>
            <p style="color: #86868b; font-size: 13px; margin-bottom: 24px;">Manage available models for cluster analysis.</p>

            @if(session('success'))
                <div style="background: #d4edda; color: #155724; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px;">✓ {{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div style="background: #f8d7da; color: #721c24; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px;">✗ {{ session('error') }}</div>
            @endif

            <div class="card">
                <h2>Add Model from AWS Bedrock</h2>
                <form method="POST" action="{{ route('settings.models.store') }}">
                    @csrf
                    <div class="form-row">
                        <div class="form-group" style="flex: 2;">
                            <label for="model_id">Select Model</label>
                            <select id="model_id" name="model_id" required
                                style="padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 14px; width: 100%;"
                                onchange="updateDisplayName(this)">
                                <option value="">-- Choose a model --</option>
                                @php $prevProvider = ''; @endphp
                                @foreach($bedrockModels as $bm)
                                    @if($bm['provider'] !== $prevProvider)
                                        @if($prevProvider !== '') </optgroup> @endif
                                        <optgroup label="{{ $bm['provider'] }}">
                                        @php $prevProvider = $bm['provider']; @endphp
                                    @endif
                                    @if(!$models->contains('model_id', $bm['model_id']))
                                    @php $p = \App\Http\Controllers\SettingsController::findPricingForModel($pricing, $bm['model_id']); @endphp
                                    <option value="{{ $bm['model_id'] }}"
                                        data-display="{{ $bm['provider'] }} {{ $bm['display_name'] }}">
                                        {{ $bm['display_name'] }}@if($p) — In: ${{ number_format($p['input'], 6) }} / Out: ${{ number_format($p['output'] ?? 0, 6) }} per {{ $p['unit'] ?? '1K tokens' }}@endif
                                    </option>
                                    @endif
                                @endforeach
                                @if($prevProvider !== '') </optgroup> @endif
                            </select>
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label for="display_name">Display Name (auto-filled)</label>
                            <input type="text" id="display_name" name="display_name" placeholder="Auto-generated from selection">
                        </div>
                        <div><button type="submit" class="btn btn-primary">Add</button></div>
                    </div>
                    @if(empty($bedrockModels))
                        <p style="color: #ff9500; font-size: 12px; margin-top: 8px;">
                            Could not fetch models from AWS Bedrock. Check AWS credentials.
                        </p>
                    @endif
                </form>
            </div>

            <div class="card">
                <h2>Registered Models</h2>
                @if($models->isEmpty())
                    <div class="empty">No models registered yet.</div>
                @else
                    <table>
                        <thead>
                            <tr><th>Display Name</th><th>Model ID</th><th>Input Cost</th><th>Output Cost</th><th>Status</th><th></th></tr>
                        </thead>
                        <tbody>
                            @foreach($models as $model)
                            @php $p = \App\Http\Controllers\SettingsController::findPricingForModel($pricing, $model->model_id); @endphp
                            <tr @if(!$model->is_active) style="opacity: 0.5;" @endif>
                                <td style="font-weight: 500;">{{ $model->display_name }}</td>
                                <td><span class="mono">{{ $model->model_id }}</span></td>
                                <td style="white-space: nowrap; font-size: 12px;">
                                    @if($p && $p['input'] !== null)
                                        ${{ number_format($p['input'], 6) }}<span style="color: #86868b;">/{{ $p['unit'] ?? '1K tokens' }}</span>
                                    @else
                                        <span style="color: #d2d2d7;">N/A</span>
                                    @endif
                                </td>
                                <td style="white-space: nowrap; font-size: 12px;">
                                    @if($p && $p['output'] !== null)
                                        ${{ number_format($p['output'], 6) }}<span style="color: #86868b;">/{{ $p['unit'] ?? '1K tokens' }}</span>
                                    @else
                                        <span style="color: #d2d2d7;">N/A</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge {{ $model->is_active ? 'badge-active' : 'badge-inactive' }}">
                                        {{ $model->is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                                <td>
                                    <div class="actions">
                                        <form method="POST" action="{{ route('settings.models.update', $model) }}">@csrf @method('PUT')<input type="hidden" name="action" value="toggle_active"><button type="submit" class="btn btn-sm btn-outline">{{ $model->is_active ? 'Deactivate' : 'Activate' }}</button></form>
                                        <form method="POST" action="{{ route('settings.models.destroy', $model) }}" onsubmit="return confirm('Delete {{ $model->display_name }}?')">@csrf @method('DELETE')<button type="submit" class="btn btn-sm btn-danger">Delete</button></form>
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
        // Auto-fill display name when model is selected from dropdown
        function updateDisplayName(select) {
            const option = select.options[select.selectedIndex];
            const displayName = option.getAttribute('data-display') || '';
            document.getElementById('display_name').value = displayName;
        }
@endsection
