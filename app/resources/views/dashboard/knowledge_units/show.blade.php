{{-- Knowledge unit detail/edit page: displays a single KU with review workflow actions,
     editable fields (topic, intent, summary, cause/resolution, notes), metadata, keywords,
     and typical cases. Approved KUs are locked from editing. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KU #{{ $ku->id }} — {{ $ku->topic }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f7; color: #1d1d1f; }
        .container { max-width: 960px; margin: 0 auto; padding: 24px; }
        h1 { font-size: 22px; font-weight: 600; margin-bottom: 4px; }
        .subtitle { color: #5f6368; font-size: 14px; margin-bottom: 24px; }
        a { color: #0071e3; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .back { font-size: 14px; margin-bottom: 16px; display: inline-block; }
        .card { background: #fff; border-radius: 12px; padding: 24px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
        .card h2 { font-size: 16px; font-weight: 600; margin-bottom: 16px; }
        .badge { display: inline-block; padding: 3px 12px; border-radius: 12px; font-size: 13px; font-weight: 500; }
        .badge-draft { background: #fff3cd; color: #856404; }
        .badge-reviewed { background: #cce5ff; color: #004085; }
        .badge-approved { background: #d4edda; color: #155724; }
        .badge-rejected { background: #f8d7da; color: #721c24; }
        .btn { display: inline-block; padding: 8px 18px; border-radius: 8px; border: none; font-size: 14px; font-weight: 500; cursor: pointer; text-decoration: none; }
        .btn-primary { background: #0071e3; color: #fff; }
        .btn-primary:hover { background: #0077ed; }
        .btn-green { background: #30d158; color: #fff; }
        .btn-green:hover { background: #28b84c; }
        .btn-orange { background: #ff9500; color: #fff; }
        .btn-orange:hover { background: #e68600; }
        .btn-danger { background: #ff3b30; color: #fff; }
        .btn-danger:hover { background: #e0352b; }
        .btn-outline { background: transparent; border: 1px solid #d2d2d7; color: #1d1d1f; }
        .btn-outline:hover { background: #f5f5f7; text-decoration: none; }
        .btn-sm { padding: 5px 14px; font-size: 13px; }
        label { display: block; font-size: 13px; color: #5f6368; font-weight: 500; margin-bottom: 4px; }
        input[type="text"], textarea { width: 100%; padding: 10px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 14px; font-family: inherit; }
        textarea { resize: vertical; min-height: 80px; }
        .form-group { margin-bottom: 16px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .meta { display: flex; gap: 20px; flex-wrap: wrap; font-size: 13px; color: #5f6368; margin-bottom: 16px; }
        .meta strong { color: #1d1d1f; }
        .keywords { display: flex; gap: 6px; flex-wrap: wrap; }
        .keyword { background: #f0f0f2; padding: 2px 8px; border-radius: 6px; font-size: 12px; color: #424245; }
        .review-bar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .typical-case { background: #f5f5f7; border-radius: 8px; padding: 10px 14px; font-size: 13px; color: #424245; line-height: 1.5; margin-bottom: 8px; }
        .locked-notice { background: #f8d7da; color: #721c24; padding: 12px 16px; border-radius: 8px; font-size: 14px; margin-bottom: 16px; }
        .flash-success { background: #d4edda; color: #155724; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }
        .flash-error { background: #f8d7da; color: #721c24; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }
    </style>
</head>
<body>
    <div class="container">
        <a href="{{ route('dashboard.knowledge-units', $ku->pipeline_job_id) }}" class="back">&larr; Back to Knowledge Units</a>

        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
            <h1>KU #{{ $ku->id }} — {{ $ku->topic }}</h1>
            <span class="badge badge-{{ $ku->review_status }}">{{ $ku->review_status }}</span>
        </div>
        <p class="subtitle">
            Intent: {{ $ku->intent }} &middot;
            Version {{ $ku->version }} &middot;
            {{ $ku->row_count }} rows &middot;
            Cluster #{{ $ku->cluster_id }}
        </p>

        @if(session('success'))
            <div class="flash-success">&#10003; {{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="flash-error">&#10007; {{ session('error') }}</div>
        @endif

        {{-- Review actions: status transition buttons (approve, review, reject, revert) --}}
        @if(count($allowedTransitions) > 0)
            <div class="card">
                <h2>Review</h2>
                <div class="review-bar">
                    @foreach($allowedTransitions as $status)
                        <form method="POST" action="{{ route('knowledge-units.review', $ku) }}" style="display: inline;">
                            @csrf
                            <input type="hidden" name="new_status" value="{{ $status }}">
                            @if($status === 'approved')
                                <button type="submit" class="btn btn-green" onclick="return confirm('Approve this Knowledge Unit? It will become read-only.')">Approve</button>
                            @elseif($status === 'reviewed')
                                <button type="submit" class="btn btn-primary">Mark as Reviewed</button>
                            @elseif($status === 'rejected')
                                <button type="submit" class="btn btn-danger">Reject</button>
                            @elseif($status === 'draft')
                                <button type="submit" class="btn btn-orange">Revert to Draft</button>
                            @endif
                        </form>
                    @endforeach
                    <a href="{{ route('knowledge-units.versions', $ku) }}" class="btn btn-sm btn-outline">Version History ({{ $ku->versions->count() }})</a>
                </div>
            </div>
        @else
            <div class="card" style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h2 style="margin-bottom: 4px;">Review</h2>
                    <span style="font-size: 13px; color: #155724;">This Knowledge Unit is approved and locked.</span>
                </div>
                <a href="{{ route('knowledge-units.versions', $ku) }}" class="btn btn-sm btn-outline">Version History ({{ $ku->versions->count() }})</a>
            </div>
        @endif

        {{-- Edit form: editable fields with version bump on save; disabled when KU is approved --}}
        <div class="card">
            <h2>Edit</h2>

            @if(!$ku->isEditable())
                <div class="locked-notice">
                    This Knowledge Unit is approved and cannot be edited. To make changes, reject it first to revert to draft status.
                </div>
            @endif

            <form method="POST" action="{{ route('knowledge-units.update', $ku) }}">
                @csrf
                @method('PUT')

                <div class="form-row">
                    <div class="form-group">
                        <label for="topic">Topic</label>
                        <input type="text" id="topic" name="topic" value="{{ $ku->topic }}" @if(!$ku->isEditable()) disabled @endif>
                    </div>
                    <div class="form-group">
                        <label for="intent">Intent</label>
                        <input type="text" id="intent" name="intent" value="{{ $ku->intent }}" @if(!$ku->isEditable()) disabled @endif>
                    </div>
                </div>

                <div class="form-group">
                    <label for="summary">Summary</label>
                    <textarea id="summary" name="summary" rows="3" @if(!$ku->isEditable()) disabled @endif>{{ $ku->summary }}</textarea>
                </div>

                <div class="form-group">
                    <label for="cause_summary">Cause Summary</label>
                    <textarea id="cause_summary" name="cause_summary" rows="3" placeholder="Describe the root cause of this issue pattern..." @if(!$ku->isEditable()) disabled @endif>{{ $ku->cause_summary }}</textarea>
                </div>

                <div class="form-group">
                    <label for="resolution_summary">Resolution Summary</label>
                    <textarea id="resolution_summary" name="resolution_summary" rows="3" placeholder="Describe how to resolve this issue..." @if(!$ku->isEditable()) disabled @endif>{{ $ku->resolution_summary }}</textarea>
                </div>

                <div class="form-group">
                    <label for="notes">Notes</label>
                    <textarea id="notes" name="notes" rows="2" placeholder="Internal notes..." @if(!$ku->isEditable()) disabled @endif>{{ $ku->notes }}</textarea>
                </div>

                <div class="form-group">
                    <label for="edit_comment">Edit Comment</label>
                    <input type="text" id="edit_comment" name="edit_comment" placeholder="What did you change?" @if(!$ku->isEditable()) disabled @endif>
                </div>

                @if($ku->isEditable())
                    <button type="submit" class="btn btn-primary">Save Changes (creates v{{ $ku->version + 1 }})</button>
                @endif
            </form>
        </div>

        {{-- Metadata card: row count, confidence score, version, pipeline job, and keyword tags --}}
        <div class="card">
            <h2>Metadata</h2>
            <div class="meta">
                <div>Row count: <strong>{{ $ku->row_count }}</strong></div>
                <div>Confidence: <strong>{{ $ku->confidence }}</strong></div>
                <div>Version: <strong>{{ $ku->version }}</strong></div>
                <div>Pipeline Job: <strong>#{{ $ku->pipeline_job_id }}</strong></div>
                <div>Created: <strong>{{ $ku->created_at->format('Y-m-d H:i') }}</strong></div>
                @if($ku->edited_at)
                    <div>Last edited: <strong>{{ $ku->edited_at->format('Y-m-d H:i') }}</strong></div>
                @endif
            </div>

            @if($ku->keywords_json)
                <label style="margin-bottom: 8px;">Keywords</label>
                <div class="keywords">
                    @foreach($ku->keywords_json as $kw)
                        <span class="keyword">{{ $kw }}</span>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Typical cases: representative example texts extracted from the cluster --}}
        @if($ku->typical_cases_json && count($ku->typical_cases_json) > 0)
            <div class="card">
                <h2>Typical Cases</h2>
                @foreach($ku->typical_cases_json as $i => $case)
                    <div class="typical-case">
                        <span style="font-size: 11px; font-weight: 600; color: #5f6368;">Case {{ $i + 1 }}</span><br>
                        {{ $case }}
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</body>
</html>
