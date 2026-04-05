{{-- Knowledge packages listing: grouped by active (published/draft/requested) and archived. --}}
@extends('layouts.app')
@section('title', __('ui.knowledge_datasets'))

@section('extra-styles')
    .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
    .page-header h1 { font-size: 20px; font-weight: 600; }
    .kp-card { display: flex; justify-content: space-between; align-items: flex-start; text-decoration: none; color: inherit; transition: box-shadow 0.15s; }
    .kp-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.12); }
    .kp-name { font-size: 16px; font-weight: 600; margin-bottom: 4px; }
    .kp-meta { font-size: 13px; color: #5f6368; margin-top: 2px; }
    .flash-success { background: #d4edda; color: #155724; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
    .group-heading { font-size: 13px; font-weight: 500; color: #5f6368; text-transform: uppercase; letter-spacing: 0.5px; margin: 24px 0 8px; padding-bottom: 4px; border-bottom: 1px solid #e5e5e7; }
    .group-heading:first-of-type { margin-top: 0; }
@endsection

@section('body')
<div class="page-content">
    <div class="page-container">
        <div class="page-header">
            <h1>{{ __('ui.knowledge_datasets') }}</h1>
            <a href="{{ route('kp.create') }}" class="btn btn-primary">{{ __('ui.new_dataset_btn') }}</a>
        </div>

        @if(session('success'))
            <div class="flash-success">{{ session('success') }}</div>
        @endif

        @php
            $activePackages = $packages->filter(fn($p) => $p->status !== 'archived');
            $archivedPackages = $packages->filter(fn($p) => $p->status === 'archived');
        @endphp

        @if($packages->isEmpty())
            <div class="card empty">
                <p>{{ __('ui.no_datasets_hint') }}</p>
                <br>
                <a href="{{ route('kp.create') }}" class="btn btn-primary">{{ __('ui.new_dataset_btn') }}</a>
            </div>
        @else
            {{-- Active packages: published, publication_requested, draft --}}
            @if($activePackages->isNotEmpty())
            @foreach($activePackages as $package)
                <a href="{{ route('kp.show', $package) }}" class="card kp-card">
                    <div>
                        <div class="kp-name">{{ $package->name }} <span style="font-weight: 400; color: #5f6368; font-size: 14px;">v{{ $package->version }}</span></div>
                        <div class="kp-meta">{{ $package->ku_count }} {{ __('ui.knowledge_units') }} · {{ $package->created_at->diffForHumans() }}</div>
                        @if($package->description)
                            <div class="kp-meta">{{ Str::limit($package->description, 100) }}</div>
                        @endif
                    </div>
                    <span class="badge badge-{{ $package->status }}">{{ $package->status === 'publication_requested' ? __('ui.publication_requested') : ucfirst($package->status) }}</span>
                </a>
            @endforeach
            @endif

            {{-- Archived packages --}}
            @if($archivedPackages->isNotEmpty())
            <div class="group-heading">{{ __('ui.archived') }}</div>
            @foreach($archivedPackages as $package)
                <a href="{{ route('kp.show', $package) }}" class="card kp-card">
                    <div style="opacity: 0.5;">
                        <div class="kp-name">{{ $package->name }} <span style="font-weight: 400; color: #5f6368; font-size: 14px;">v{{ $package->version }}</span></div>
                        <div class="kp-meta">{{ $package->ku_count }} {{ __('ui.knowledge_units') }} · {{ $package->created_at->diffForHumans() }}</div>
                        @if($package->description)
                            <div class="kp-meta">{{ Str::limit($package->description, 100) }}</div>
                        @endif
                    </div>
                    <span class="badge badge-archived">{{ __('ui.archived') }}</span>
                </a>
            @endforeach
            @endif
        @endif
    </div>
</div>
@endsection
