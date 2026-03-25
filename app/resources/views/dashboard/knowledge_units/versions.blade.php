{{-- Version history timeline for a knowledge unit: merges version snapshots and
     review status changes into a single chronological timeline with colored dots. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KU #{{ $ku->id }} — Version History</title>
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
        .timeline { position: relative; padding-left: 24px; }
        .timeline::before { content: ''; position: absolute; left: 7px; top: 4px; bottom: 4px; width: 2px; background: #e5e5e7; }
        .timeline-item { position: relative; margin-bottom: 24px; }
        .timeline-dot { position: absolute; left: -24px; top: 4px; width: 14px; height: 14px; border-radius: 50%; border: 2px solid #fff; }
        .dot-version { background: #0071e3; }
        .dot-review { background: #ff9500; }
        .timeline-header { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 6px; }
        .timeline-title { font-weight: 600; font-size: 14px; }
        .timeline-date { font-size: 12px; color: #5f6368; }
        .snapshot { background: #f5f5f7; border-radius: 8px; padding: 12px 16px; font-size: 13px; line-height: 1.6; }
        .snapshot dt { font-weight: 600; color: #5f6368; font-size: 12px; margin-top: 8px; }
        .snapshot dt:first-child { margin-top: 0; }
        .snapshot dd { color: #424245; margin-left: 0; }
        .badge { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 12px; font-weight: 500; }
        .badge-draft { background: #fff3cd; color: #856404; }
        .badge-reviewed { background: #cce5ff; color: #004085; }
        .badge-approved { background: #d4edda; color: #155724; }
        .badge-rejected { background: #f8d7da; color: #721c24; }
        .empty { text-align: center; padding: 40px; color: #5f6368; }
    </style>
</head>
<body>
    <div class="container">
        <a href="{{ route('knowledge-units.show', $ku) }}" class="back">&larr; Back to KU #{{ $ku->id }}</a>
        <h1>Version History — {{ $ku->topic }}</h1>
        <p class="subtitle">KU #{{ $ku->id }} &middot; Current: v{{ $ku->version }} &middot; {{ $ku->review_status }}</p>

        <div class="card">
            <h2>Timeline</h2>

            @if($versions->isEmpty() && $reviews->isEmpty())
                <div class="empty">No version history yet.</div>
            @else
                {{-- Merge versions and reviews into a single timeline sorted by date --}}
                @php
                    $events = collect();

                    foreach ($versions as $v) {
                        $events->push([
                            'type' => 'version',
                            'date' => $v->created_at,
                            'version' => $v->version,
                            'snapshot' => $v->snapshot_json,
                        ]);
                    }

                    foreach ($reviews as $r) {
                        $events->push([
                            'type' => 'review',
                            'date' => $r->created_at,
                            'status' => $r->review_status,
                            'comment' => $r->review_comment,
                        ]);
                    }

                    $events = $events->sortByDesc('date');
                @endphp

                <div class="timeline">
                    @foreach($events as $event)
                        <div class="timeline-item">
                            @if($event['type'] === 'version')
                                <div class="timeline-dot dot-version"></div>
                                <div class="timeline-header">
                                    <span class="timeline-title">Version {{ $event['version'] }}</span>
                                    <span class="timeline-date">{{ $event['date']->format('Y-m-d H:i') }}</span>
                                </div>
                                @if($event['snapshot'])
                                    <div class="snapshot">
                                        <dl>
                                            @if(isset($event['snapshot']['topic']))
                                                <dt>Topic</dt>
                                                <dd>{{ $event['snapshot']['topic'] }}</dd>
                                            @endif
                                            @if(isset($event['snapshot']['intent']))
                                                <dt>Intent</dt>
                                                <dd>{{ $event['snapshot']['intent'] }}</dd>
                                            @endif
                                            @if(isset($event['snapshot']['summary']))
                                                <dt>Summary</dt>
                                                <dd>{{ Str::limit($event['snapshot']['summary'], 300) }}</dd>
                                            @endif
                                            @if(!empty($event['snapshot']['edit_comment']))
                                                <dt>Edit Comment</dt>
                                                <dd>{{ $event['snapshot']['edit_comment'] }}</dd>
                                            @endif
                                            @if(isset($event['snapshot']['review_status']))
                                                <dt>Status</dt>
                                                <dd><span class="badge badge-{{ $event['snapshot']['review_status'] }}">{{ $event['snapshot']['review_status'] }}</span></dd>
                                            @endif
                                        </dl>
                                    </div>
                                @endif
                            @else
                                <div class="timeline-dot dot-review"></div>
                                <div class="timeline-header">
                                    <span class="timeline-title">
                                        Review: <span class="badge badge-{{ $event['status'] }}">{{ $event['status'] }}</span>
                                    </span>
                                    <span class="timeline-date">{{ $event['date']->format('Y-m-d H:i') }}</span>
                                </div>
                                @if($event['comment'])
                                    <div class="snapshot">
                                        <dd>{{ $event['comment'] }}</dd>
                                    </div>
                                @endif
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</body>
</html>
