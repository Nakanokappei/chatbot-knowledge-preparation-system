{{-- Profile settings page: allows the user to update their name, email, password,
     and manage API tokens for external system integration. --}}
@extends('layouts.app')
@section('title', 'Profile — KPS')

@section('extra-styles')
        label { display: block; font-weight: 500; margin-bottom: 4px; font-size: 13px; color: #5f6368; }
        input[type="text"], input[type="email"], input[type="password"] {
            width: 100%; padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px;
            font-size: 14px; margin-bottom: 12px;
        }
        /* Token section styles */
        .token-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 12px; }
        .token-table th { text-align: left; padding: 8px 6px; border-bottom: 2px solid #e5e7eb; color: #5f6368; font-weight: 500; }
        .token-table td { padding: 8px 6px; border-bottom: 1px solid #f0f0f0; }
        .ability-badge { display: inline-block; background: #e8f0fe; color: #1a73e8; padding: 2px 8px; border-radius: 4px; font-size: 11px; margin: 1px 2px; }
        .token-reveal { background: #f8f9fa; border: 1px solid #d2d2d7; border-radius: 8px; padding: 16px; margin-top: 12px; }
        .token-reveal code { display: block; background: #1d1d1f; color: #34c759; padding: 12px; border-radius: 6px; font-size: 13px; word-break: break-all; margin: 8px 0; }
        .checkbox-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 16px; margin: 8px 0 16px; }
        .checkbox-grid label { display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer; margin-bottom: 0; }
        .checkbox-grid input[type="checkbox"] { width: 16px; height: 16px; accent-color: #0071e3; }
        #token-form select { padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 14px; width: 100%; margin-bottom: 12px; }
        .btn-danger { background: #ff3b30; color: #fff; border: none; padding: 4px 12px; border-radius: 6px; font-size: 12px; cursor: pointer; }
        .btn-danger:hover { background: #d32f2f; }
        .btn-copy { background: #e5e7eb; border: none; padding: 4px 12px; border-radius: 6px; font-size: 12px; cursor: pointer; }
        .btn-copy:hover { background: #d2d2d7; }
        .text-muted { color: #86868b; }
        .text-expired { color: #ff3b30; }
@endsection

@section('body')
    <div class="page-content">
        <div class="page-container" style="max-width: 600px;">
            <h1 style="font-size: 20px; font-weight: 600; margin-bottom: 4px;">{{ __('ui.profile_settings') }}</h1>
            <p style="color: #5f6368; font-size: 13px; margin-bottom: 24px;">
                @if(!auth()->user()->isSystemAdmin() && $user->workspace)
                    {{ __('ui.profile_workspace') }}: {{ $user->workspace->name }} ·
                @endif
                {{ __('ui.profile_joined') }}: {{ $user->created_at->format('Y-m-d') }}
            </p>

            @if(session('success'))
                <div style="background: #d4edda; color: #155724; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px;">{{ session('success') }}</div>
            @endif
            @if($errors->any())
                <div style="background: #f8d7da; color: #721c24; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px;">{{ $errors->first() }}</div>
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

            {{-- Password change form --}}
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

            {{-- API Token management section --}}
            @if(!auth()->user()->isSystemAdmin())
            <div class="card">
                <h2>{{ __('ui.api_tokens') }}</h2>
                <p style="color: #5f6368; font-size: 13px; margin-bottom: 12px;">{{ __('ui.api_tokens_desc') }}</p>
                <p style="font-size: 13px; margin-bottom: 16px;">
                    <a href="{{ route('api.guide') }}" style="color: #0071e3; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
                        <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><circle cx="7" cy="7" r="5.5" stroke="currentColor" stroke-width="1.2"/><line x1="7" y1="4.5" x2="7" y2="7.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/><circle cx="7" cy="9.5" r=".7" fill="currentColor"/></svg>
                        {{ __('ui.api_guide_link') }}
                    </a>
                </p>

                {{-- Token creation form --}}
                <form id="token-form" onsubmit="return createToken(event)">
                    <label for="token-name">{{ __('ui.token_name') }}</label>
                    <input type="text" id="token-name" placeholder="{{ __('ui.token_name_placeholder') }}" required>

                    <label>{{ __('ui.token_abilities') }}</label>
                    <div class="checkbox-grid">
                        <label><input type="checkbox" name="abilities[]" value="retrieve"> retrieve</label>
                        <label><input type="checkbox" name="abilities[]" value="chat"> chat</label>
                        <label><input type="checkbox" name="abilities[]" value="datasets:read"> datasets:read</label>
                        <label><input type="checkbox" name="abilities[]" value="datasets:write"> datasets:write</label>
                        <label><input type="checkbox" name="abilities[]" value="pipeline-jobs:read"> pipeline-jobs:read</label>
                        <label><input type="checkbox" name="abilities[]" value="pipeline-jobs:write"> pipeline-jobs:write</label>
                    </div>

                    <label for="token-expiration">{{ __('ui.token_expiration') }}</label>
                    <select id="token-expiration">
                        <option value="30">{{ __('ui.token_expires_30') }}</option>
                        <option value="60">{{ __('ui.token_expires_60') }}</option>
                        <option value="90" selected>{{ __('ui.token_expires_90') }}</option>
                        <option value="365">{{ __('ui.token_expires_365') }}</option>
                        <option value="0">{{ __('ui.token_expires_never') }}</option>
                    </select>

                    <button type="submit" class="btn btn-primary" id="create-token-btn">{{ __('ui.create_token') }}</button>
                </form>

                {{-- Revealed token (shown once after creation, then hidden) --}}
                <div id="token-reveal" class="token-reveal" style="display: none;">
                    <p style="font-weight: 600; font-size: 13px; margin-bottom: 4px;">{{ __('ui.token_created') }}</p>
                    <code id="token-value"></code>
                    <button type="button" class="btn-copy" onclick="copyToken()">{{ __('ui.copy_token') }}</button>
                    <span id="copy-feedback" style="font-size: 12px; color: #34c759; margin-left: 8px; display: none;">{{ __('ui.token_copied') }}</span>
                </div>

                {{-- Existing tokens table --}}
                <div id="tokens-list" style="margin-top: 20px;"></div>
            </div>
            @endif {{-- !isSystemAdmin --}}

        </div>
    </div>
@endsection

@section('scripts')
<script>
    // Translation strings passed from Blade to JavaScript
    const t = {
        noTokens: @json(__('ui.no_tokens')),
        lastUsed: @json(__('ui.last_used')),
        expiresAt: @json(__('ui.expires_at')),
        never: @json(__('ui.never')),
        neverUsed: @json(__('ui.never_used')),
        revoke: @json(__('ui.revoke')),
        confirmRevoke: @json(__('ui.confirm_revoke_token')),
        abilities: @json(__('ui.token_abilities')),
        tokenName: @json(__('ui.token_name')),
        expired: @json(__('ui.expired')),
    };
    const csrfToken = '{{ csrf_token() }}';

    // Load token list on page load
    document.addEventListener('DOMContentLoaded', loadTokens);

    /**
     * Fetch the current user's tokens and render the table.
     */
    async function loadTokens() {
        const res = await fetch('{{ route("profile.tokens") }}');
        const tokens = await res.json();
        const container = document.getElementById('tokens-list');

        if (tokens.length === 0) {
            container.innerHTML = `<p class="text-muted" style="font-size: 13px;">${t.noTokens}</p>`;
            return;
        }

        let html = `<table class="token-table">
            <thead><tr>
                <th>${t.tokenName}</th>
                <th>${t.abilities}</th>
                <th>${t.lastUsed}</th>
                <th>${t.expiresAt}</th>
                <th></th>
            </tr></thead><tbody>`;

        tokens.forEach(token => {
            // Format abilities as badges
            const badges = token.abilities.map(a => `<span class="ability-badge">${a}</span>`).join('');

            // Format last used date or show "never used"
            const lastUsed = token.last_used_at
                ? new Date(token.last_used_at).toLocaleDateString()
                : `<span class="text-muted">${t.neverUsed}</span>`;

            // Format expiration date with expired highlighting
            let expiresAt = `<span class="text-muted">${t.never}</span>`;
            if (token.expires_at) {
                const expDate = new Date(token.expires_at);
                const isExpired = expDate < new Date();
                expiresAt = isExpired
                    ? `<span class="text-expired">${t.expired}</span>`
                    : expDate.toLocaleDateString();
            }

            html += `<tr>
                <td style="font-weight: 500;">${escapeHtml(token.name)}</td>
                <td>${badges}</td>
                <td>${lastUsed}</td>
                <td>${expiresAt}</td>
                <td><button class="btn-danger" onclick="revokeToken(${token.id})">${t.revoke}</button></td>
            </tr>`;
        });

        html += '</tbody></table>';
        container.innerHTML = html;
    }

    /**
     * Create a new API token via AJAX and display the plain-text value.
     */
    async function createToken(e) {
        e.preventDefault();

        // Collect selected abilities from checkboxes
        const abilities = [...document.querySelectorAll('#token-form input[name="abilities[]"]:checked')]
            .map(cb => cb.value);

        if (abilities.length === 0) {
            alert('{{ __("ui.token_abilities") }}: 1+');
            return false;
        }

        const btn = document.getElementById('create-token-btn');
        btn.disabled = true;

        const res = await fetch('{{ route("profile.tokens.create") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({
                name: document.getElementById('token-name').value,
                abilities: abilities,
                expiration: document.getElementById('token-expiration').value,
            }),
        });

        btn.disabled = false;

        if (!res.ok) {
            const err = await res.json();
            alert(err.message || 'Error');
            return false;
        }

        const data = await res.json();

        // Show the plain-text token (only available now, not stored)
        document.getElementById('token-value').textContent = data.plainTextToken;
        document.getElementById('token-reveal').style.display = 'block';

        // Reset form and reload table
        document.getElementById('token-form').reset();
        document.getElementById('token-expiration').value = '90';
        loadTokens();

        return false;
    }

    /**
     * Copy the revealed token to clipboard.
     */
    function copyToken() {
        const value = document.getElementById('token-value').textContent;
        navigator.clipboard.writeText(value).then(() => {
            const fb = document.getElementById('copy-feedback');
            fb.style.display = 'inline';
            setTimeout(() => fb.style.display = 'none', 2000);
        });
    }

    /**
     * Revoke (delete) a token after user confirmation.
     */
    async function revokeToken(id) {
        if (!confirm(t.confirmRevoke)) return;

        await fetch(`/profile/tokens/${id}`, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': csrfToken },
        });

        // Hide the reveal box if it was showing
        document.getElementById('token-reveal').style.display = 'none';
        loadTokens();
    }

    /**
     * Escape HTML to prevent XSS when rendering user-supplied token names.
     */
    function escapeHtml(text) {
        const el = document.createElement('span');
        el.textContent = text;
        return el.innerHTML;
    }
</script>
@endsection
