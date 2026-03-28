{{-- Knowledge package detail: metadata, lifecycle action buttons, stats card, and included KU table. --}}
@extends('layouts.app')
@section('title', $package->name . ' v' . $package->version)

@section('extra-styles')
    .page-header { margin-bottom: 4px; }
    .page-header h1 { font-size: 22px; font-weight: 600; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .subtitle { color: #5f6368; font-size: 13px; margin-bottom: 16px; }
    .actions { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px; }
    .meta-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 16px; }
    .meta-label { font-size: 11px; color: #5f6368; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 2px; }
    .meta-value { font-size: 20px; font-weight: 600; }
    .flash-success { background: #d4edda; color: #155724; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
    .flash-error { background: #f8d7da; color: #721c24; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
    .pending-note { font-size: 13px; color: #5f6368; align-self: center; }
@endsection

@section('body')
<div class="page-content">
    <div class="page-container">

        @if(session('success'))
            <div class="flash-success">{{ session('success') }}</div>
        @endif
        @if($errors->any())
            <div class="flash-error">{{ $errors->first() }}</div>
        @endif

        <div class="page-header">
            <h1>{{ $package->name }} <span class="badge badge-{{ $package->status }}">{{ $package->status === 'pending_review' ? __('ui.pending_review') : ucfirst($package->status) }}</span></h1>
        </div>
        <p class="subtitle">Version {{ $package->version }} &middot; {{ $package->ku_count }} {{ __('ui.knowledge_units') }} &middot; {{ $package->created_at->format('Y-m-d H:i') }}</p>

        @if($package->description)
            <p style="margin-bottom: 16px; color: #424245; font-size: 14px;">{{ $package->description }}</p>
        @endif

        <div class="actions">
            {{-- Draft: submit for publication request, or owner can publish directly --}}
            @if($package->isEditable())
                <form method="POST" action="{{ route('kp.submit-review', $package) }}">
                    @csrf
                    <button type="submit" class="btn btn-primary">{{ __('ui.submit_for_review') }}</button>
                </form>
                @if(auth()->user()->isOwner() || auth()->user()->isSystemAdmin())
                    <form method="POST" action="{{ route('kp.publish', $package) }}">
                        @csrf
                        <button type="submit" class="btn btn-success" onclick="return confirm('{{ __('ui.publish_confirm') }}')">{{ __('ui.publish_directly') }}</button>
                    </form>
                @endif
            @endif

            {{-- Publication requested: owner authorizes or rejects --}}
            @if($package->isPendingReview())
                @if(auth()->user()->isOwner() || auth()->user()->isSystemAdmin())
                    <form method="POST" action="{{ route('kp.publish', $package) }}">
                        @csrf
                        <button type="submit" class="btn btn-success" onclick="return confirm('{{ __('ui.publish_confirm') }}')">{{ __('ui.approve_publish') }}</button>
                    </form>
                    <form method="POST" action="{{ route('kp.reject-review', $package) }}">
                        @csrf
                        <button type="submit" class="btn btn-outline">{{ __('ui.reject_review') }}</button>
                    </form>
                @else
                    <span class="pending-note">{{ __('ui.owner_approval_required') }}</span>
                @endif
            @endif

            {{-- Published: new version, export, chat --}}
            @if($package->isPublished())
                <form method="POST" action="{{ route('kp.new-version', $package) }}">
                    @csrf
                    <button type="submit" class="btn btn-primary">{{ __('ui.new_version') }} (v{{ $package->version + 1 }})</button>
                </form>
                <a href="{{ route('kp.export', $package) }}" class="btn btn-outline">{{ __('ui.export_json') }}</a>
                <a href="{{ route('kp.evaluation', $package) }}" class="btn btn-outline">{{ __('ui.evaluation') }}</a>
                <a href="{{ route('kp.chat', $package) }}" class="btn btn-primary">{{ __('ui.chat') }}</a>
            @endif
        </div>

        {{-- Stats --}}
        <div class="card">
            <div class="meta-grid">
                <div>
                    <div class="meta-label">{{ __('ui.knowledge_units') }}</div>
                    <div class="meta-value">{{ $package->ku_count }}</div>
                </div>
                <div>
                    <div class="meta-label">{{ __('ui.status') }}</div>
                    <div class="meta-value" style="font-size: 15px; font-weight: 500;">{{ ucfirst($package->status) }}</div>
                </div>
                <div>
                    <div class="meta-label">{{ __('ui.version') }}</div>
                    <div class="meta-value">v{{ $package->version }}</div>
                </div>
                <div>
                    <div class="meta-label">{{ __('ui.created_by') }}</div>
                    <div class="meta-value" style="font-size: 15px; font-weight: 500;">{{ $package->creator?->name ?? 'System' }}</div>
                </div>
            </div>
        </div>

        {{-- KU items table --}}
        <div class="card">
            <h2>{{ __('ui.knowledge_units') }}</h2>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>{{ __('ui.topic') }}</th>
                        <th>{{ __('ui.intent') }}</th>
                        <th>{{ __('ui.rows') }}</th>
                        <th>{{ __('ui.confidence') }}</th>
                        <th>{{ __('ui.ku_version') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($package->items as $item)
                        <tr>
                            <td>{{ $item->sort_order + 1 }}</td>
                            <td>
                                <a href="{{ route('knowledge-units.show', $item->knowledge_unit_id) }}" style="color: #0071e3; text-decoration: none;">
                                    {{ $item->knowledgeUnit->topic }}
                                </a>
                            </td>
                            <td style="color: #5f6368; font-size: 13px;">{{ $item->knowledgeUnit->intent }}</td>
                            <td>{{ $item->knowledgeUnit->row_count }}</td>
                            <td>{{ number_format($item->knowledgeUnit->confidence * 100) }}%</td>
                            <td>v{{ $item->included_version }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

    </div>
</div>
@endsection
