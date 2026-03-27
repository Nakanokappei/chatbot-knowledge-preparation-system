{{-- Create new workspace form for system administrators.
     Layout follows the workspace settings page pattern. --}}
@extends('layouts.admin')
@section('title', __('ui.create_workspace') . ' — KPS')

@section('extra-styles')
        label { display: block; font-weight: 500; margin-bottom: 4px; font-size: 13px; color: #5f6368; }
        input[type="text"] {
            width: 100%; padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px;
            font-size: 14px; margin-bottom: 12px;
        }
@endsection

@section('body')
    <div class="page-content">
        <div class="page-container" style="max-width: 600px;">
            <h1 style="font-size: 20px; font-weight: 600; margin-bottom: 24px;">{{ __('ui.create_workspace') }}</h1>

            @if($errors->any())
                <div style="background: #f8d7da; color: #721c24; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px;">
                    @foreach($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <div class="card">
                <h2>{{ __('ui.workspace_name') }}</h2>
                <form method="POST" action="{{ route('admin.workspaces.store') }}">
                    @csrf
                    <label for="name">{{ __('ui.name') }}</label>
                    <input type="text" name="name" id="name" value="{{ old('name') }}" required autofocus
                           placeholder="{{ __('ui.workspace_name_placeholder') }}">
                    <div style="display: flex; gap: 8px; margin-top: 8px;">
                        <button type="submit" class="btn btn-primary">{{ __('ui.create') }}</button>
                        <a href="{{ route('admin.index') }}" class="btn btn-outline">{{ __('ui.cancel') }}</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
