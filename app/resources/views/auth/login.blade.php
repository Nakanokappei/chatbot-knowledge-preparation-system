{{-- Login page: standalone authentication form with inline styles (no layout extends).
     Displays email/password inputs, remember-me checkbox, and validation errors. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('ui.login') }} — {{ __('ui.app_name') }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f7; color: #1d1d1f; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .login-card { background: #fff; border-radius: 16px; padding: 40px; width: 400px; box-shadow: 0 4px 24px rgba(0,0,0,0.08); }
        h1 { font-size: 24px; font-weight: 600; text-align: center; margin-bottom: 8px; }
        .subtitle { color: #5f6368; font-size: 14px; text-align: center; margin-bottom: 32px; }
        label { display: block; font-size: 13px; color: #5f6368; font-weight: 500; margin-bottom: 4px; }
        input[type="email"], input[type="password"] { width: 100%; padding: 10px 14px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 15px; margin-bottom: 16px; }
        input:focus { outline: none; border-color: #0071e3; box-shadow: 0 0 0 3px rgba(0,113,227,0.15); }
        .btn { display: block; width: 100%; padding: 12px; border-radius: 10px; border: none; font-size: 15px; font-weight: 600; cursor: pointer; }
        .btn-primary { background: #0071e3; color: #fff; }
        .btn-primary:hover { background: #0077ed; }
        .remember { display: flex; align-items: center; gap: 6px; margin-bottom: 20px; font-size: 13px; color: #5f6368; }
        .error { background: #f8d7da; color: #721c24; padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
    </style>
</head>
<body>
    {{-- Login card: centered form with app title and credential inputs --}}
    <div class="login-card">
        <h1>{{ __('ui.app_name') }}</h1>
        <p class="subtitle">{{ __('ui.sign_in_to_continue') }}</p>

        @if($errors->any())
            <div class="error">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('login') }}">
            @csrf
            <label for="email">{{ __('ui.email') }}</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" autofocus required>

            <label for="password">{{ __('ui.password') }}</label>
            <input type="password" id="password" name="password" required>

            <label class="remember">
                <input type="checkbox" name="remember"> {{ __('ui.remember_me') }}
            </label>

            <button type="submit" class="btn btn-primary">{{ __('ui.sign_in') }}</button>
        </form>
    </div>
</body>
</html>
