{{-- Main application layout: provides the shared chrome for all authenticated pages.
     Includes top navigation bar, user dropdown menu with locale switcher, common CSS
     (buttons, badges, cards, tables, status indicators), and shared JavaScript
     (user dropdown, sidebar toggle, timestamp localization). --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', __('ui.app_name'))</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Roboto, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #F6F6F6; color: #1d1d1f; height: 100vh; display: flex; flex-direction: column; font-size: 15px; line-height: 22px; }

        /* ── Top navigation bar ─────────────────────────────── */
        .topbar { background: #F6F6F6; border-bottom: none; padding: 0 24px; height: 48px; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
        .topbar-left { display: flex; align-items: center; gap: 16px; }
        .topbar-left h1 { font-size: 18px; font-weight: 600; }
        .topbar-left h1 a { color: #1d1d1f; text-decoration: none; }
        .hamburger { background: none; border: none; cursor: pointer; padding: 6px; border-radius: 6px; display: flex; align-items: center; justify-content: center; }
        .hamburger:hover { background: #E9E9E9; }
        .hamburger svg { width: 18px; height: 18px; color: #1d1d1f; }
        .topbar-nav { display: flex; gap: 4px; }
        .topbar-nav a { font-size: 15px; color: #5f6368; text-decoration: none; padding: 6px 12px; border-radius: 6px; transition: all 0.15s; }
        .topbar-nav a:hover { background: #E9E9E9; color: #1d1d1f; }
        .topbar-nav a.active { background: #0071e3; color: #fff; }
        .topbar-right { display: flex; align-items: center; gap: 12px; }

        /* User dropdown */
        .user-menu { position: relative; }
        .user-btn { background: #F6F6F6; border: none; border-radius: 8px; padding: 4px 10px; font-size: 13px; cursor: pointer; display: flex; align-items: center; gap: 6px; }
        .user-avatar { width: 22px; height: 22px; background: #0071e3; color: #fff; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 600; }
        .user-dropdown { display: none; position: absolute; right: 0; top: calc(100% + 4px); background: #fff; border-radius: 10px; box-shadow: 0 4px 16px rgba(0,0,0,0.12); min-width: 180px; z-index: 100; overflow: hidden; }
        .user-dropdown.show { display: block; }
        .user-dropdown-header { padding: 10px 14px; border-bottom: 1px solid #f0f0f2; }
        .user-dropdown-header .name { font-size: 13px; font-weight: 600; }
        .user-dropdown-header .email { font-size: 11px; color: #5f6368; }
        .user-dropdown a, .user-dropdown button { display: block; width: 100%; text-align: left; padding: 8px 14px; font-size: 13px; text-decoration: none; color: #1d1d1f; background: none; border: none; cursor: pointer; }
        .user-dropdown a:hover, .user-dropdown button:hover { background: #f5f5f7; }
        .user-dropdown .logout { color: #1d1d1f; border-top: 1px solid #f0f0f2; }

        /* ── Common elements ─────────────────────────────────── */
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 8px; border: none; font-size: 13px; font-weight: 500; cursor: pointer; text-decoration: none; transition: all 0.15s; }
        .btn-primary { background: #0071e3; color: #fff; }
        .btn-primary:hover { background: #0077ed; }
        .btn-success { background: #34c759; color: #fff; }
        .btn-success:hover { background: #2db84e; }
        .btn-outline { background: transparent; border: 1px solid #d2d2d7; color: #1d1d1f; }
        .btn-outline:hover { background: #f5f5f7; }
        .btn-danger { background: #ff3b30; color: #fff; }
        .btn-green { background: #30d158; color: #fff; }
        .btn-green:hover { background: #28b84c; }
        .btn-orange { background: #ff9500; color: #fff; }
        .btn-orange:hover { background: #e68600; }
        .btn-sm { padding: 4px 10px; font-size: 12px; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 500; }
        .badge-draft { background: #f0f0f2; color: #5f6368; }
        .badge-reviewed { background: #cce5ff; color: #004085; }
        .badge-approved { background: #d4edda; color: #155724; }
        .badge-rejected { background: #f8d7da; color: #721c24; }
        .badge-published { background: #d4edda; color: #155724; }
        .badge-publication_requested { background: #fff3cd; color: #856404; }
        .badge-archived { background: #f0f0f2; color: #5f6368; }
        .card { background: #fff; border-radius: 12px; padding: 24px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
        .card h2 { font-size: 16px; font-weight: 600; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; font-size: 15px; }
        th { text-align: left; padding: 8px 12px; color: #5f6368; font-weight: 500; border-bottom: 1px solid #e5e5e7; }
        td { padding: 10px 12px; border-bottom: 1px solid #f0f0f2; }
        .status { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 12px; font-weight: 500; }
        .status-submitted { background: #f0f0f2; color: #5f6368; }
        .status-validating, .status-preprocessing, .status-embedding, .status-clustering { background: #fff3cd; color: #856404; }
        .status-completed { background: #d4edda; color: #155724; }
        .status-failed { background: #f8d7da; color: #721c24; }
        .progress-bar { width: 100%; height: 6px; background: #e5e5e7; border-radius: 3px; overflow: hidden; }
        .progress-bar-fill { height: 100%; background: #34c759; border-radius: 3px; transition: width 0.5s; }
        .empty { text-align: center; padding: 40px; color: #5f6368; }

        /* Page content area */
        .page-content { flex: 1; overflow-y: auto; padding: 24px; background: #fff; border-radius: 12px 0 0 0; }
        .page-container { max-width: 960px; margin: 0 auto; }

        /* ── PoC notice banner ───────────────────────────────── */
        .poc-notice { background: #fff3cd; color: #856404; font-size: 12px; text-align: center; padding: 5px 40px; flex-shrink: 0; letter-spacing: 0.01em; position: relative; }
        .poc-notice-close { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #856404; font-size: 16px; line-height: 1; cursor: pointer; padding: 2px 6px; border-radius: 4px; opacity: 0.7; }
        .poc-notice-close:hover { opacity: 1; background: rgba(0,0,0,0.06); }

        @yield('extra-styles')
    </style>
    {{-- System admin: override topbar, body background, and primary buttons to match the admin console theme --}}
    @if(auth()->check() && auth()->user()->isSystemAdmin())
    <style>
        body, .topbar, .user-btn { background: #F0E6FA !important; }
        .hamburger:hover { background: #E0D0F0 !important; }
        .topbar-nav a:hover { background: #E0D0F0 !important; color: #1d1d1f !important; }
        .topbar-nav a.active { background: #7C3AED !important; color: #fff !important; }
        .user-avatar { background: #7C3AED !important; }
        .btn-primary { background: #7C3AED !important; }
        .btn-primary:hover { background: #6D28D9 !important; }
    </style>
    @endif
</head>
<body>
    {{-- PoC notice banner: shown to owner and member roles only; hidden once dismissed via localStorage --}}
    @if(auth()->check() && !auth()->user()->isSystemAdmin())
    <div class="poc-notice" id="poc-notice">
        {{ __('ui.poc_notice') }}
        <button class="poc-notice-close" onclick="dismissPocNotice()" aria-label="閉じる">×</button>
    </div>
    @endif

    {{-- Top navigation bar: hamburger menu, app title, nav links, and user dropdown --}}
    <div class="topbar">
        <div class="topbar-left">
            {{-- System admins have no sidebar on this layout (e.g. /profile): render the
                 hamburger as a link back to the admin dashboard instead of a sidebar toggle. --}}
            @if(auth()->user()->isSystemAdmin())
            <a href="{{ route('admin.index', ['workspace' => 'all']) }}" class="hamburger" title="{{ __('ui.all_workspaces') }}" style="text-decoration: none;">
                <svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
                    <line x1="2" y1="4" x2="16" y2="4"/><line x1="2" y1="9" x2="16" y2="9"/><line x1="2" y1="14" x2="16" y2="14"/>
                </svg>
            </a>
            @else
            <button class="hamburger" onclick="toggleSidebar()" title="{{ __('ui.toggle_sidebar') }}">
                <svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
                    <line x1="2" y1="4" x2="16" y2="4"/><line x1="2" y1="9" x2="16" y2="9"/><line x1="2" y1="14" x2="16" y2="14"/>
                </svg>
            </button>
            @endif
            <h1><a href="{{ route('workspace.index') }}">KPS</a></h1>
            <nav class="topbar-nav">
                @if(auth()->user()->isSystemAdmin())
                {{-- System admins only reach this layout on cross-role pages (e.g. /profile).
                     Show admin nav links so they can navigate back to their dashboard. --}}
                <a href="{{ route('admin.index') }}" class="{{ request()->routeIs('admin.index') ? 'active' : '' }}">{{ __('ui.nav_dashboard') }}</a>
                <a href="{{ route('admin.system') }}" class="{{ request()->routeIs('admin.system') ? 'active' : '' }}">{{ __('ui.nav_system_health') }}</a>
                <a href="{{ route('admin.settings.index') }}" class="{{ request()->routeIs('admin.settings.*') ? 'active' : '' }}">{{ __('ui.nav_settings') }}</a>
                @else
                {{-- Workspace nav: all three links are owner-only.
                     Members work entirely within the sidebar on the main workspace view. --}}
                @if(auth()->user()->isOwner())
                <a href="{{ route('kp.index') }}" class="{{ request()->routeIs('kp.*') || request()->routeIs('knowledge-units.*') ? 'active' : '' }}">{{ __('ui.datasets') }}</a>
                <a href="{{ route('usage') }}" class="{{ request()->routeIs('usage') ? 'active' : '' }}">{{ __('ui.usage') }}</a>
                <a href="{{ route('settings.index') }}" class="{{ request()->routeIs('settings.*') ? 'active' : '' }}">{{ __('ui.nav_settings') }}</a>
                @endif
                @endif
            </nav>
        </div>
        <div class="topbar-right">
            @php
                $roleLabel = match(auth()->user()->role) {
                    'owner' => __('ui.role_owner'),
                    'member' => __('ui.role_member'),
                    'system_admin' => __('ui.role_system_admin'),
                    default => auth()->user()->role,
                };
            @endphp
            {{-- Workspace name: standalone link/label, clicking it does not open the user dropdown.
                 System admin has no workspace; show nothing here (role badge already shows the role). --}}
            @if(auth()->user()->workspace)
                @if(auth()->user()->isOwner())
                    <a href="{{ route('workspace.settings') }}" style="color: #5f6368; font-size: 13px; text-decoration: none;" onmouseover="this.style.color='#0071e3'" onmouseout="this.style.color='#5f6368'">{{ auth()->user()->workspace->name }}</a>
                @else
                    <span style="color: #5f6368; font-size: 13px;">{{ auth()->user()->workspace->name }}</span>
                @endif
            @endif
            {{-- Role badge: purple for system admin, grey for workspace roles --}}
            @if(auth()->user()->isSystemAdmin())
            <span style="font-size: 11px; color: #fff; background: #7C3AED; padding: 2px 10px; border-radius: 8px; font-weight: 500;">{{ $roleLabel }}</span>
            @else
            <span style="font-size: 11px; color: #86868b; background: #f0f0f2; padding: 1px 8px; border-radius: 8px;">{{ $roleLabel }}</span>
            @endif
            <div class="user-menu" id="user-menu">
                <button class="user-btn" onclick="document.getElementById('user-dropdown').classList.toggle('show')">
                    <span class="user-avatar">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
                    {{ auth()->user()->name }}
                </button>
                <div class="user-dropdown" id="user-dropdown">
                    <div class="user-dropdown-header">
                        <div class="name">{{ auth()->user()->name }}</div>
                        <div class="email">{{ auth()->user()->email }}</div>
                    </div>
                    <a href="{{ route('profile.edit') }}">{{ __('ui.nav_profile') }}</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="logout">{{ __('ui.logout') }}</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    @yield('body')

    <script>
        // Restore dismissed state for PoC notice banner on page load
        (function() {
            if (localStorage.getItem('poc_notice_dismissed') === '1') {
                const el = document.getElementById('poc-notice');
                if (el) el.style.display = 'none';
            }
        })();

        // Hide the PoC notice banner and persist the choice in localStorage
        function dismissPocNotice() {
            const el = document.getElementById('poc-notice');
            if (el) el.style.display = 'none';
            localStorage.setItem('poc_notice_dismissed', '1');
        }

        // Close user dropdown when clicking outside
        document.addEventListener('click', function(e) {
            const menu = document.getElementById('user-menu');
            const dropdown = document.getElementById('user-dropdown');
            if (menu && dropdown && !menu.contains(e.target)) {
                dropdown.classList.remove('show');
            }
        });

        // Toggle sidebar when on a workspace page; navigate to workspace when on other pages
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            if (!sidebar) {
                window.location.href = '{{ route('dashboard') }}';
                return;
            }
            sidebar.classList.toggle('collapsed');
        }

        // Convert all <time> elements with datetime attribute to local time
        function localizeTimestamps() {
            document.querySelectorAll('time[datetime]').forEach(el => {
                const utc = new Date(el.getAttribute('datetime'));
                if (isNaN(utc)) return;
                const fmt = el.dataset.format || 'short';
                if (fmt === 'date') {
                    el.textContent = utc.toLocaleDateString(undefined, { month: '2-digit', day: '2-digit' });
                } else if (fmt === 'full') {
                    el.textContent = utc.toLocaleString(undefined, { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' });
                } else {
                    el.textContent = utc.toLocaleString(undefined, { month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' });
                }
            });
        }
        document.addEventListener('DOMContentLoaded', localizeTimestamps);

        @yield('scripts')
    </script>
</body>
</html>
