{{-- First-run setup page: create the initial system administrator.
     Displayed only when no users exist and SETUP_PASSPHRASE is configured. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KPS Setup</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f7; color: #1d1d1f; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .setup-card { background: #fff; border-radius: 16px; padding: 40px; width: 440px; box-shadow: 0 4px 24px rgba(0,0,0,0.08); }
        h1 { font-size: 24px; font-weight: 600; text-align: center; margin-bottom: 8px; color: #7C3AED; }
        .subtitle { color: #5f6368; font-size: 14px; text-align: center; margin-bottom: 32px; }
        label { display: block; font-size: 13px; color: #5f6368; font-weight: 500; margin-bottom: 4px; }
        input[type="email"], input[type="password"], input[type="text"] { width: 100%; padding: 10px 14px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 15px; margin-bottom: 16px; }
        input:focus { outline: none; border-color: #7C3AED; box-shadow: 0 0 0 3px rgba(124,58,237,0.15); }
        .btn { display: block; width: 100%; padding: 12px; border-radius: 10px; border: none; font-size: 15px; font-weight: 600; cursor: pointer; text-align: center; text-decoration: none; }
        .btn-primary { background: #7C3AED; color: #fff; }
        .btn-primary:hover { background: #6d28d9; }
        .error { background: #f8d7da; color: #721c24; padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
        .success-box { background: #f3f0ff; border: 1px solid #c4b5fd; border-radius: 12px; padding: 20px; margin-top: 24px; }
        .success-box .label { font-size: 13px; font-weight: 600; color: #7C3AED; margin-bottom: 12px; }
        .url-row { display: flex; align-items: center; gap: 8px; padding: 10px 12px; background: #fff; border: 1px solid #d2d2d7; border-radius: 6px; margin-bottom: 12px; }
        .url-row input { flex: 1; border: none; background: none; font-family: monospace; font-size: 12px; color: #1d1d1f; padding: 0; margin: 0; outline: none; }
        .copy-btn { background: #7C3AED; color: #fff; border: none; padding: 6px 14px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 500; white-space: nowrap; }
        .hint { font-size: 12px; color: #5f6368; margin-top: 8px; }
    </style>
</head>
<body>
    <div class="setup-card">
        <h1>KPS Setup</h1>
        <p class="subtitle">Create the first system administrator</p>

        @if($errors->any())
            <div class="error">{{ $errors->first() }}</div>
        @endif

        {{-- Setup form: email + passphrase to generate system_admin invitation --}}
        <form method="POST" action="{{ route('setup') }}">
            @csrf
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" autofocus required>

            <label for="passphrase">Passphrase</label>
            <input type="password" id="passphrase" name="passphrase" required>

            <button type="submit" class="btn btn-primary">Generate Invitation Link</button>
        </form>

        {{-- Success state: show mailto link and copyable registration URL --}}
        @if(session('mailto_url'))
            <div class="success-box">
                <div class="label">Invitation link generated for {{ session('invite_email') }}</div>

                <a href="{{ session('mailto_url') }}" class="btn btn-primary" style="margin-bottom: 16px;">Open Email Client</a>

                <div class="url-row">
                    <input type="text" id="register_url" value="{{ session('register_url') }}" readonly>
                    <button type="button" class="copy-btn"
                        onclick="navigator.clipboard.writeText(document.getElementById('register_url').value); this.textContent='Copied!'; setTimeout(() => this.textContent='Copy', 2000)">Copy</button>
                </div>

                <p class="hint">If the mailto link doesn't work, copy the URL above and send it manually.</p>
            </div>
        @endif
    </div>
</body>
</html>
