<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings — LLM Models</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f7; color: #1d1d1f; }
        .container { max-width: 960px; margin: 0 auto; padding: 24px; }
        h1 { font-size: 24px; font-weight: 600; margin-bottom: 8px; }
        .subtitle { color: #86868b; font-size: 14px; margin-bottom: 32px; }
        .card { background: #fff; border-radius: 12px; padding: 24px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
        .card h2 { font-size: 16px; font-weight: 600; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th { text-align: left; padding: 8px 12px; color: #86868b; font-weight: 500; border-bottom: 1px solid #e5e5e7; }
        td { padding: 10px 12px; border-bottom: 1px solid #f0f0f2; vertical-align: middle; }
        .btn { display: inline-block; padding: 6px 14px; border-radius: 8px; border: none; font-size: 13px; font-weight: 500; cursor: pointer; text-decoration: none; }
        .btn-primary { background: #0071e3; color: #fff; }
        .btn-primary:hover { background: #0077ed; }
        .btn-sm { padding: 4px 12px; font-size: 12px; }
        .btn-outline { background: transparent; border: 1px solid #d2d2d7; color: #1d1d1f; }
        .btn-outline:hover { background: #f5f5f7; }
        .btn-danger { background: #ff3b30; color: #fff; }
        .btn-danger:hover { background: #e0352b; }
        .btn-green { background: #30d158; color: #fff; }
        .btn-green:hover { background: #28b84c; }
        .badge { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 12px; font-weight: 500; }
        .badge-default { background: #007aff; color: #fff; }
        .badge-active { background: #d4edda; color: #155724; }
        .badge-inactive { background: #f0f0f2; color: #86868b; }
        input[type="text"] { padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 14px; width: 100%; }
        .form-row { display: flex; gap: 12px; align-items: flex-end; }
        .form-group { flex: 1; }
        .form-group label { display: block; font-size: 12px; color: #86868b; margin-bottom: 4px; font-weight: 500; }
        .nav-link { color: #0071e3; text-decoration: none; font-size: 14px; }
        .nav-link:hover { text-decoration: underline; }
        .mono { font-family: 'SF Mono', 'Menlo', monospace; font-size: 12px; color: #86868b; }
        .actions { display: flex; gap: 6px; }
        .empty { text-align: center; padding: 40px; color: #86868b; }
    </style>
</head>
<body>
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
            <h1>LLM Models</h1>
            <a href="{{ route('dashboard') }}" class="nav-link">&larr; Dashboard</a>
        </div>
        <p class="subtitle">Manage available models for cluster analysis. Add new models as they become available.</p>

        @if(session('success'))
            <div style="background: #d4edda; color: #155724; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px;">
                &#10003; {{ session('success') }}
            </div>
        @endif
        @if(session('error'))
            <div style="background: #f8d7da; color: #721c24; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px;">
                &#10007; {{ session('error') }}
            </div>
        @endif

        <!-- Add New Model -->
        <div class="card">
            <h2>Add Model</h2>
            <form method="POST" action="{{ route('settings.models.store') }}">
                @csrf
                <div class="form-row">
                    <div class="form-group">
                        <label for="display_name">Display Name</label>
                        <input type="text" id="display_name" name="display_name" placeholder="e.g. Haiku 4.5 (Fast / Low cost)" required>
                    </div>
                    <div class="form-group">
                        <label for="model_id">Bedrock Model ID</label>
                        <input type="text" id="model_id" name="model_id" placeholder="e.g. jp.anthropic.claude-haiku-4-5-20251001-v1:0" required>
                    </div>
                    <div>
                        <button type="submit" class="btn btn-primary">Add</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Model List -->
        <div class="card">
            <h2>Registered Models</h2>
            @if($models->isEmpty())
                <div class="empty">No models registered yet. Add one above.</div>
            @else
                <table>
                    <thead>
                        <tr>
                            <th>Display Name</th>
                            <th>Model ID</th>
                            <th>Status</th>
                            <th>Order</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($models as $model)
                        <tr @if(!$model->is_active) style="opacity: 0.5;" @endif>
                            <td style="font-weight: 500;">
                                {{ $model->display_name }}
                                @if($model->is_default)
                                    <span class="badge badge-default">Default</span>
                                @endif
                            </td>
                            <td><span class="mono">{{ $model->model_id }}</span></td>
                            <td>
                                @if($model->is_active)
                                    <span class="badge badge-active">Active</span>
                                @else
                                    <span class="badge badge-inactive">Inactive</span>
                                @endif
                            </td>
                            <td style="font-size: 13px; color: #86868b;">{{ $model->sort_order }}</td>
                            <td>
                                <div class="actions">
                                    @if(!$model->is_default)
                                        <form method="POST" action="{{ route('settings.models.update', $model) }}">
                                            @csrf
                                            @method('PUT')
                                            <input type="hidden" name="action" value="set_default">
                                            <button type="submit" class="btn btn-sm btn-green">Set Default</button>
                                        </form>
                                    @endif

                                    <form method="POST" action="{{ route('settings.models.update', $model) }}">
                                        @csrf
                                        @method('PUT')
                                        <input type="hidden" name="action" value="toggle_active">
                                        <button type="submit" class="btn btn-sm btn-outline">
                                            {{ $model->is_active ? 'Deactivate' : 'Activate' }}
                                        </button>
                                    </form>

                                    @if(!$model->is_default)
                                        <form method="POST" action="{{ route('settings.models.destroy', $model) }}"
                                              style="display: inline;"
                                              onsubmit="return confirm('Delete {{ $model->display_name }}?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                        </form>
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
</body>
</html>
