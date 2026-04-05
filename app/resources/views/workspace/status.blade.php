{{-- Workspace status page: shown to users when their workspace is frozen or suspended.
     Standalone page (no layout) — similar to login page styling. --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('ui.workspace_status') }} — {{ __('ui.app_name') }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f7; color: #1d1d1f; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .status-card { background: #fff; border-radius: 16px; padding: 40px; width: 480px; box-shadow: 0 4px 24px rgba(0,0,0,0.08); text-align: center; }
        h1 { font-size: 22px; font-weight: 600; margin-bottom: 12px; }
        .icon { font-size: 48px; margin-bottom: 16px; }
        .message { color: #5f6368; font-size: 14px; line-height: 1.7; margin-bottom: 24px; }
        .badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 600; }
        .badge-frozen { background: #fff3cd; color: #856404; }
        .badge-suspended { background: #f8d7da; color: #721c24; }
        .logout-btn { display: inline-block; padding: 10px 24px; border-radius: 10px; border: 1px solid #d2d2d7; background: #fff; color: #1d1d1f; font-size: 14px; font-weight: 500; text-decoration: none; cursor: pointer; }
        .logout-btn:hover { background: #f0f0f2; }
    </style>
</head>
<body>
    <div class="status-card">
        @php
            $status = auth()->user()->workspace->status ?? 'suspended';
        @endphp

        @if($status === 'frozen')
            <div class="icon">&#x1F9CA;</div>
            <h1>{{ __('ui.workspace_frozen_title') }}</h1>
            <span class="badge badge-frozen">{{ __('ui.status_frozen') }}</span>
            <p class="message" style="margin-top: 16px;">{{ __('ui.workspace_frozen_message') }}</p>
            <a href="{{ route('workspace.index') }}" class="logout-btn" style="margin-right: 8px;">{{ __('ui.back_to_dashboard') }}</a>
        @else
            <div class="icon">&#x1F6D1;</div>
            <h1>{{ __('ui.workspace_suspended_title') }}</h1>
            <span class="badge badge-suspended">{{ __('ui.status_suspended') }}</span>
            <p class="message" style="margin-top: 16px;">{{ __('ui.workspace_suspended_message') }}</p>
        @endif

        <form method="POST" action="{{ route('logout') }}" style="display: inline; margin-top: 12px;">
            @csrf
            <button type="submit" class="logout-btn">{{ __('ui.logout') }}</button>
        </form>
    </div>
</body>
</html>
