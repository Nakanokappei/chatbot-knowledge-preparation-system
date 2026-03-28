{{-- Knowledge packages listing: shows all packages with status badges, KU counts, and creation dates. --}}
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

        @forelse($packages as $package)
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
        @empty
            <div class="card empty">
                <p>{{ __('ui.no_datasets_hint') }}</p>
                <br>
                <a href="{{ route('kp.create') }}" class="btn btn-primary">{{ __('ui.new_dataset_btn') }}</a>
            </div>
        @endforelse
    </div>
</div>
@endsection
