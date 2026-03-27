{{-- Profile settings page: allows the user to update their name, email, and password.
     Shows tenant info and join date. --}}
@extends('layouts.app')
@section('title', 'Profile — KPS')

@section('extra-styles')
        label { display: block; font-weight: 500; margin-bottom: 4px; font-size: 13px; color: #5f6368; }
        input[type="text"], input[type="email"], input[type="password"] {
            width: 100%; padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px;
            font-size: 14px; margin-bottom: 12px;
        }
@endsection

@section('body')
    <div class="page-content">
        <div class="page-container" style="max-width: 600px;">
            <h1 style="font-size: 20px; font-weight: 600; margin-bottom: 4px;">{{ __('ui.profile_settings') }}</h1>
            <p style="color: #5f6368; font-size: 13px; margin-bottom: 24px;">
                Tenant: {{ $user->tenant->name ?? 'N/A' }} · Joined: {{ $user->created_at->format('Y-m-d') }}
            </p>

            @if(session('success'))
                <div style="background: #d4edda; color: #155724; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px;">✓ {{ session('success') }}</div>
            @endif
            @if($errors->any())
                <div style="background: #f8d7da; color: #721c24; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px;">✗ {{ $errors->first() }}</div>
            @endif

            {{-- Profile update form: name and email --}}
            <div class="card">
                <h2>{{ __('ui.profile') }}</h2>
                <form method="POST" action="{{ route('profile.update') }}">
                    @csrf @method('PUT')
                    <label for="name">{{ __('ui.name') }}</label>
                    <input type="text" name="name" id="name" value="{{ old('name', $user->name) }}" required>
                    <label for="email">{{ __('ui.email') }}</label>
                    <input type="email" name="email" id="email" value="{{ old('email', $user->email) }}" required>
                    <button type="submit" class="btn btn-primary">{{ __('ui.save') }}</button>
                </form>
            </div>

            {{-- Password change form: current password + new password with confirmation --}}
            <div class="card">
                <h2>{{ __('ui.change_password') }}</h2>
                <form method="POST" action="{{ route('profile.password') }}">
                    @csrf @method('PUT')
                    <label for="password">{{ __('ui.new_password') }}</label>
                    <input type="password" name="password" id="password" required>
                    <label for="password_confirmation">{{ __('ui.confirm_new_password') }}</label>
                    <input type="password" name="password_confirmation" id="password_confirmation" required>
                    <button type="submit" class="btn btn-primary">{{ __('ui.change_password') }}</button>
                </form>
            </div>

        </div>
    </div>
@endsection
