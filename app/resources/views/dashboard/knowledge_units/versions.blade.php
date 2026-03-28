{{-- Version history timeline for a KU: merges version snapshots and review events into a chronological list. --}}
@extends('layouts.app')
@section('title', __('ui.version_history') . ' — KU #' . $ku->id)

@section('extra-styles')
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
@endsection

@section('body')
<div class="page-content">
    <div class="page-container">

        <div style="margin-bottom: 4px; font-size: 13px;">
            <a href="{{ route('knowledge-units.show', $ku) }}" style="color: #0071e3; text-decoration: none;">← KU #{{ $ku->id }}</a>
        </div>
        <h1 style="font-size: 20px; font-weight: 600; margin-bottom: 4px;">{{ __('ui.version_history') }} — {{ $ku->topic }}</h1>
        <p style="color: #5f6368; font-size: 13px; margin-bottom: 20px;">KU #{{ $ku->id }} &middot; v{{ $ku->version }} &middot; {{ $ku->review_status }}</p>

        <div class="card">
            <h2>{{ __('ui.timeline') }}</h2>

            @if($versions->isEmpty() && $reviews->isEmpty())
                <div class="empty">{{ __('ui.no_version_history') }}</div>
            @else
                @php
                    $events = collect();
                    foreach ($versions as $v) {
                        $events->push(['type' => 'version', 'date' => $v->created_at, 'version' => $v->version, 'snapshot' => $v->snapshot_json]);
                    }
                    foreach ($reviews as $r) {
                        $events->push(['type' => 'review', 'date' => $r->created_at, 'status' => $r->review_status, 'comment' => $r->review_comment]);
                    }
                    $events = $events->sortByDesc('date');
                @endphp

                <div class="timeline">
                    @foreach($events as $event)
                        <div class="timeline-item">
                            @if($event['type'] === 'version')
                                <div class="timeline-dot dot-version"></div>
                                <div class="timeline-header">
                                    <span class="timeline-title">{{ __('ui.version') }} {{ $event['version'] }}</span>
                                    <span class="timeline-date">{{ $event['date']->format('Y-m-d H:i') }}</span>
                                </div>
                                @if($event['snapshot'])
                                    <div class="snapshot">
                                        <dl>
                                            @if(isset($event['snapshot']['topic']))<dt>{{ __('ui.topic') }}</dt><dd>{{ $event['snapshot']['topic'] }}</dd>@endif
                                            @if(isset($event['snapshot']['intent']))<dt>{{ __('ui.intent') }}</dt><dd>{{ $event['snapshot']['intent'] }}</dd>@endif
                                            @if(isset($event['snapshot']['summary']))<dt>{{ __('ui.summary') }}</dt><dd>{{ Str::limit($event['snapshot']['summary'], 300) }}</dd>@endif
                                            @if(!empty($event['snapshot']['edit_comment']))<dt>{{ __('ui.edit_comment') }}</dt><dd>{{ $event['snapshot']['edit_comment'] }}</dd>@endif
                                            @if(isset($event['snapshot']['review_status']))<dt>{{ __('ui.status') }}</dt><dd><span class="badge badge-{{ $event['snapshot']['review_status'] }}">{{ $event['snapshot']['review_status'] }}</span></dd>@endif
                                        </dl>
                                    </div>
                                @endif
                            @else
                                <div class="timeline-dot dot-review"></div>
                                <div class="timeline-header">
                                    <span class="timeline-title">{{ __('ui.review') }}: <span class="badge badge-{{ $event['status'] }}">{{ $event['status'] }}</span></span>
                                    <span class="timeline-date">{{ $event['date']->format('Y-m-d H:i') }}</span>
                                </div>
                                @if($event['comment'])
                                    <div class="snapshot"><dd>{{ $event['comment'] }}</dd></div>
                                @endif
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

    </div>
</div>
@endsection
