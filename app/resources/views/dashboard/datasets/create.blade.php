{{-- Create knowledge dataset page: form for naming a new dataset and selecting
     which approved knowledge units to include. Supports select all/deselect all. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Knowledge Dataset</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f5; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; }
        h1 { margin-bottom: 20px; }
        .nav { margin-bottom: 20px; }
        .nav a { color: #2563eb; text-decoration: none; }
        .card { background: white; border-radius: 8px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        label { display: block; font-weight: 600; margin-bottom: 4px; font-size: 14px; }
        input[type="text"], textarea { width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; margin-bottom: 16px; }
        textarea { min-height: 80px; }
        .btn { padding: 10px 20px; border-radius: 6px; font-size: 14px; cursor: pointer; border: none; }
        .btn-primary { background: #2563eb; color: white; }
        .btn-primary:hover { background: #1d4ed8; }
        .ku-list { max-height: 400px; overflow-y: auto; border: 1px solid #d1d5db; border-radius: 6px; margin-bottom: 16px; }
        .ku-item { padding: 10px 14px; border-bottom: 1px solid #f3f4f6; display: flex; align-items: center; gap: 10px; }
        .ku-item:last-child { border-bottom: none; }
        .ku-item:hover { background: #f9fafb; }
        .ku-item input[type="checkbox"] { flex-shrink: 0; }
        .ku-topic { font-weight: 600; }
        .ku-meta { font-size: 12px; color: #6b7280; }
        .error { color: #dc2626; font-size: 13px; margin-bottom: 12px; }
        .select-bar { padding: 8px 14px; background: #f9fafb; border-bottom: 1px solid #d1d5db; font-size: 13px; display: flex; gap: 12px; }
        .select-bar a { color: #2563eb; cursor: pointer; text-decoration: none; }
    </style>
</head>
<body>
<div class="container">
    <div class="nav">
        <a href="{{ route('kd.index') }}">Datasets</a> / <strong>New Dataset</strong>
    </div>

    <h1>Create Knowledge Dataset</h1>

    <div class="card">
        <form method="POST" action="{{ route('kd.store') }}">
            @csrf

            <label for="name">Dataset Name</label>
            <input type="text" name="name" id="name" value="{{ old('name') }}" required placeholder="e.g. Customer Support v1">

            <label for="description">Description (optional)</label>
            <textarea name="description" id="description" placeholder="What is this dataset for?">{{ old('description') }}</textarea>

            @error('knowledge_unit_ids')
                <div class="error">{{ $message }}</div>
            @enderror

            {{-- Knowledge unit selection list with select/deselect all controls --}}
            <label>Select Approved Knowledge Units ({{ $approvedKUs->count() }} available)</label>

            <div class="ku-list">
                <div class="select-bar">
                    <a onclick="document.querySelectorAll('.ku-checkbox').forEach(c => c.checked = true)">Select All</a>
                    <a onclick="document.querySelectorAll('.ku-checkbox').forEach(c => c.checked = false)">Deselect All</a>
                    <span id="selected-count" style="margin-left: auto;">0 selected</span>
                </div>
                @forelse($approvedKUs as $ku)
                    <label class="ku-item">
                        <input type="checkbox" name="knowledge_unit_ids[]" value="{{ $ku->id }}"
                               class="ku-checkbox" onchange="updateCount()"
                               {{ in_array($ku->id, old('knowledge_unit_ids', [])) ? 'checked' : '' }}>
                        <div>
                            <div class="ku-topic">{{ $ku->topic }}</div>
                            <div class="ku-meta">
                                {{ $ku->intent }} | {{ $ku->row_count }} rows |
                                Confidence: {{ number_format($ku->confidence * 100) }}% |
                                Job #{{ $ku->pipeline_job_id }}
                            </div>
                        </div>
                    </label>
                @empty
                    <div style="padding: 20px; text-align: center; color: #6b7280;">
                        No approved Knowledge Units available. Review and approve KUs first.
                    </div>
                @endforelse
            </div>

            <button type="submit" class="btn btn-primary" {{ $approvedKUs->isEmpty() ? 'disabled' : '' }}>
                Create Dataset
            </button>
        </form>
    </div>
</div>

<script>
// Update the selected checkbox count display
function updateCount() {
    const checked = document.querySelectorAll('.ku-checkbox:checked').length;
    document.getElementById('selected-count').textContent = checked + ' selected';
}
// Initialize count on page load
updateCount();
</script>
</body>
</html>
