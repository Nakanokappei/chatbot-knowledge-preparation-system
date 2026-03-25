{{-- Knowledge datasets listing page: shows all datasets with their status badges,
     KU counts, and creation dates. Links to individual dataset detail pages. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Knowledge Datasets</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f5; padding: 20px; }
        .container { max-width: 1000px; margin: 0 auto; }
        h1 { margin-bottom: 20px; }
        .nav { margin-bottom: 20px; display: flex; gap: 12px; align-items: center; }
        .nav a { color: #2563eb; text-decoration: none; }
        .nav a:hover { text-decoration: underline; }
        .btn { display: inline-block; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-size: 14px; cursor: pointer; border: none; }
        .btn-primary { background: #2563eb; color: white; }
        .btn-primary:hover { background: #1d4ed8; }
        .card { background: white; border-radius: 8px; padding: 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
        .card-title { font-size: 18px; font-weight: 600; }
        .badge { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; }
        .badge-draft { background: #fef3c7; color: #92400e; }
        .badge-published { background: #d1fae5; color: #065f46; }
        .badge-archived { background: #e5e7eb; color: #374151; }
        .meta { color: #6b7280; font-size: 13px; }
        .meta span { margin-right: 16px; }
        .empty { text-align: center; padding: 40px; color: #6b7280; }
        .success { background: #d1fae5; color: #065f46; padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; }
    </style>
</head>
<body>
<div class="container">
    <div class="nav">
        <a href="{{ route('dashboard') }}">Dashboard</a> /
        <strong>Knowledge Datasets</strong>
        <a href="{{ route('kd.create') }}" class="btn btn-primary" style="margin-left: auto;">+ New Dataset</a>
    </div>

    @if(session('success'))
        <div class="success">{{ session('success') }}</div>
    @endif

    <h1>Knowledge Datasets</h1>

    @forelse($datasets as $dataset)
        <div class="card">
            <div class="card-header">
                <a href="{{ route('kd.show', $dataset) }}" class="card-title" style="color: #111; text-decoration: none;">
                    {{ $dataset->name }}
                    <span style="font-weight: normal; color: #6b7280;">v{{ $dataset->version }}</span>
                </a>
                <span class="badge badge-{{ $dataset->status }}">{{ ucfirst($dataset->status) }}</span>
            </div>
            @if($dataset->description)
                <p style="margin-bottom: 8px; color: #374151;">{{ $dataset->description }}</p>
            @endif
            <div class="meta">
                <span>{{ $dataset->ku_count }} Knowledge Units</span>
                <span>Created {{ $dataset->created_at->diffForHumans() }}</span>
            </div>
        </div>
    @empty
        <div class="card empty">
            <p>No datasets yet. Create one from your approved Knowledge Units.</p>
            <br>
            <a href="{{ route('kd.create') }}" class="btn btn-primary">+ New Dataset</a>
        </div>
    @endforelse
</div>
</body>
</html>
