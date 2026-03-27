{{-- Invitation registration page: invited user sets their name and password. --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('ui.accept_invitation') }} — {{ __('ui.app_name') }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f7; color: #1d1d1f; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .card { background: #fff; border-radius: 16px; padding: 40px; width: 400px; box-shadow: 0 4px 24px rgba(0,0,0,0.08); }
        h1 { font-size: 22px; font-weight: 600; text-align: center; margin-bottom: 8px; }
        .subtitle { color: #5f6368; font-size: 14px; text-align: center; margin-bottom: 24px; line-height: 1.5; }
        label { display: block; font-size: 13px; color: #5f6368; font-weight: 500; margin-bottom: 4px; }
        input[type="text"], input[type="email"], input[type="password"] { width: 100%; padding: 10px 14px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 15px; margin-bottom: 16px; }
        input:focus { outline: none; border-color: #0071e3; box-shadow: 0 0 0 3px rgba(0,113,227,0.15); }
        input[readonly] { background: #f5f5f7; color: #5f6368; }
        .btn { display: block; width: 100%; padding: 12px; border-radius: 10px; border: none; font-size: 15px; font-weight: 600; cursor: pointer; }
        .btn-primary { background: #0071e3; color: #fff; }
        .btn-primary:hover { background: #0077ed; }
        .error { background: #f8d7da; color: #721c24; padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
        .invited-by { background: #e8f0fe; color: #1a73e8; padding: 8px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 13px; text-align: center; }
    </style>
</head>
<body>
    <div class="card">
        <h1>{{ __('ui.accept_invitation') }}</h1>
        <p class="subtitle">{{ __('ui.invite_register_description') }}</p>

        <div class="invited-by">
            {{ __('ui.invited_by', ['name' => $invitation->inviter->name, 'workspace' => $invitation->workspace->name ?? 'KPS']) }}
        </div>

        @if($errors->any())
            <div class="error">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('invitation.register', ['token' => $token]) }}">
            @csrf

            <label for="email">{{ __('ui.email') }}</label>
            <input type="email" id="email" value="{{ $invitation->email }}" readonly>

            <label for="name">{{ __('ui.name') }}</label>
            <input type="text" id="name" name="name" value="{{ old('name') }}" required autofocus>

            <label for="password">{{ __('ui.password') }}</label>
            <input type="password" id="password" name="password" required>

            <label for="password_confirmation">{{ __('ui.confirm_new_password') }}</label>
            <input type="password" id="password_confirmation" name="password_confirmation" required>

            <button type="submit" class="btn btn-primary">{{ __('ui.create_account') }}</button>
        </form>
    </div>
</body>
</html>
