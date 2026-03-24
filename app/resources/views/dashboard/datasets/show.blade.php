<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $dataset->name }} v{{ $dataset->version }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f5; padding: 20px; }
        .container { max-width: 1000px; margin: 0 auto; }
        .nav { margin-bottom: 20px; }
        .nav a { color: #2563eb; text-decoration: none; }
        .card { background: white; border-radius: 8px; padding: 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        h1 { margin-bottom: 4px; }
        .subtitle { color: #6b7280; margin-bottom: 20px; }
        .badge { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; }
        .badge-draft { background: #fef3c7; color: #92400e; }
        .badge-published { background: #d1fae5; color: #065f46; }
        .badge-archived { background: #e5e7eb; color: #374151; }
        .btn { display: inline-block; padding: 8px 16px; border-radius: 6px; font-size: 14px; cursor: pointer; border: none; text-decoration: none; }
        .btn-primary { background: #2563eb; color: white; }
        .btn-success { background: #059669; color: white; }
        .btn-outline { background: white; color: #374151; border: 1px solid #d1d5db; }
        .btn:hover { opacity: 0.9; }
        .actions { display: flex; gap: 8px; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 10px 12px; background: #f9fafb; font-size: 13px; color: #6b7280; border-bottom: 1px solid #e5e7eb; }
        td { padding: 10px 12px; border-bottom: 1px solid #f3f4f6; font-size: 14px; }
        .meta-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; }
        .meta-item { }
        .meta-label { font-size: 12px; color: #6b7280; text-transform: uppercase; }
        .meta-value { font-size: 18px; font-weight: 600; }
        .success { background: #d1fae5; color: #065f46; padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; }
        .error { background: #fee2e2; color: #991b1b; padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; }
    </style>
</head>
<body>
<div class="container">
    <div class="nav">
        <a href="{{ route('dashboard') }}">Dashboard</a> /
        <a href="{{ route('datasets.index') }}">Datasets</a> /
        <strong>{{ $dataset->name }}</strong>
    </div>

    @if(session('success'))
        <div class="success">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="error">{{ $errors->first() }}</div>
    @endif

    <h1>{{ $dataset->name }} <span class="badge badge-{{ $dataset->status }}">{{ ucfirst($dataset->status) }}</span></h1>
    <p class="subtitle">Version {{ $dataset->version }} | {{ $dataset->ku_count }} Knowledge Units | Created {{ $dataset->created_at->format('Y-m-d H:i') }}</p>

    @if($dataset->description)
        <p style="margin-bottom: 16px;">{{ $dataset->description }}</p>
    @endif

    <div class="actions">
        @if($dataset->isEditable())
            <form method="POST" action="{{ route('datasets.publish', $dataset) }}">
                @csrf
                <button type="submit" class="btn btn-success" onclick="return confirm('Publish this dataset? It will become available for retrieval.')">
                    Publish
                </button>
            </form>
        @endif

        @if($dataset->isPublished())
            <form method="POST" action="{{ route('datasets.new-version', $dataset) }}">
                @csrf
                <button type="submit" class="btn btn-primary">New Version (v{{ $dataset->version + 1 }})</button>
            </form>

            <a href="{{ route('datasets.export', $dataset) }}" class="btn btn-outline">Export JSON</a>

            <a href="{{ route('datasets.chat', $dataset) }}" class="btn btn-primary">Chat</a>
        @endif
    </div>

    <!-- Stats -->
    <div class="card">
        <div class="meta-grid">
            <div class="meta-item">
                <div class="meta-label">Knowledge Units</div>
                <div class="meta-value">{{ $dataset->ku_count }}</div>
            </div>
            <div class="meta-item">
                <div class="meta-label">Status</div>
                <div class="meta-value">{{ ucfirst($dataset->status) }}</div>
            </div>
            <div class="meta-item">
                <div class="meta-label">Version</div>
                <div class="meta-value">v{{ $dataset->version }}</div>
            </div>
            <div class="meta-item">
                <div class="meta-label">Created By</div>
                <div class="meta-value">{{ $dataset->creator?->name ?? 'System' }}</div>
            </div>
        </div>
    </div>

    <!-- KU Items Table -->
    <div class="card">
        <h2 style="margin-bottom: 12px;">Knowledge Units</h2>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Topic</th>
                    <th>Intent</th>
                    <th>Rows</th>
                    <th>Confidence</th>
                    <th>KU Version</th>
                </tr>
            </thead>
            <tbody>
                @foreach($dataset->items as $item)
                    <tr>
                        <td>{{ $item->sort_order + 1 }}</td>
                        <td>
                            <a href="{{ route('knowledge-units.show', $item->knowledge_unit_id) }}" style="color: #2563eb; text-decoration: none;">
                                {{ $item->knowledgeUnit->topic }}
                            </a>
                        </td>
                        <td>{{ $item->knowledgeUnit->intent }}</td>
                        <td>{{ $item->knowledgeUnit->row_count }}</td>
                        <td>{{ number_format($item->knowledgeUnit->confidence * 100) }}%</td>
                        <td>v{{ $item->included_version }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
