{{-- Workspace settings page: manage workspace name, view members and pending invitations. --}}
@extends('layouts.app')
@section('title', __('ui.workspace_settings') . ' — KPS')

@section('extra-styles')
        label { display: block; font-weight: 500; margin-bottom: 4px; font-size: 13px; color: #5f6368; }
        input[type="text"], input[type="email"] {
            width: 100%; padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px;
            font-size: 14px; margin-bottom: 12px;
        }
        .member-list { list-style: none; padding: 0; }
        .member-item { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid #f0f0f0; }
        .member-item:last-child { border-bottom: none; }
        .member-avatar { width: 32px; height: 32px; background: #0071e3; color: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 600; flex-shrink: 0; }
        .member-info { flex: 1; }
        .member-name { font-size: 14px; font-weight: 500; }
        .member-email { font-size: 12px; color: #5f6368; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 500; }
        .badge-pending { background: #fff3cd; color: #856404; }
        .badge-expired { background: #f8d7da; color: #721c24; }
@endsection

@section('body')
    <div class="page-content">
        <div class="page-container" style="max-width: 600px;">
            <h1 style="font-size: 20px; font-weight: 600; margin-bottom: 24px;">{{ __('ui.workspace_settings') }}</h1>

            @if(session('success'))
                <div style="background: #d4edda; color: #155724; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px;">✓ {{ session('success') }}</div>
            @endif

            {{-- Workspace name --}}
            <div class="card">
                <h2>{{ __('ui.workspace_name') }}</h2>
                <form method="POST" action="{{ route('workspace.update') }}">
                    @csrf @method('PUT')
                    <label for="name">{{ __('ui.name') }}</label>
                    <input type="text" name="name" id="name" value="{{ old('name', $workspace->name) }}" required>
                    <button type="submit" class="btn btn-primary">{{ __('ui.save') }}</button>
                </form>
            </div>

            {{-- Members --}}
            <div class="card">
                <h2>{{ __('ui.members') }} ({{ $members->count() }})</h2>
                <ul class="member-list">
                    @foreach($members as $member)
                        <li class="member-item">
                            <div class="member-avatar">{{ mb_strtoupper(mb_substr($member->name, 0, 1)) }}</div>
                            <div class="member-info">
                                <div class="member-name">{{ $member->name }}</div>
                                <div class="member-email">{{ $member->email }}</div>
                            </div>
                            @if($member->id !== auth()->id())
                                <form method="POST" action="{{ route('workspace.update-role', $member) }}" style="margin: 0;">
                                    @csrf @method('PUT')
                                    <select name="role" onchange="this.form.submit()" style="padding: 3px 8px; border: 1px solid #d2d2d7; border-radius: 4px; font-size: 12px;">
                                        <option value="owner" @if($member->role === 'owner') selected @endif>Owner</option>
                                        <option value="member" @if($member->role === 'member') selected @endif>Member</option>
                                    </select>
                                </form>
                            @else
                                <span class="badge" style="background: #e8f5e9; color: #2e7d32;">Owner</span>
                            @endif
                            <div style="font-size: 12px; color: #5f6368;">{{ $member->created_at->format('Y-m-d') }}</div>
                            <form method="POST" action="{{ route('workspace.reset-password', $member) }}" style="margin: 0;"
                                  onsubmit="return confirm('Send password reset email to {{ $member->name }}?')">
                                @csrf
                                <button type="submit" style="background: none; border: 1px solid #d2d2d7; border-radius: 4px; padding: 2px 8px; font-size: 11px; color: #5f6368; cursor: pointer; white-space: nowrap;">{{ __('ui.send_reset') }}</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            </div>

            {{-- Generate invite link --}}
            <div class="card">
                <h2>{{ __('ui.invite_colleague') }}</h2>
                <p style="color: #5f6368; font-size: 13px; margin-bottom: 16px;">{{ __('ui.invite_description') }}</p>

                @if($errors->has('invite_email'))
                    <div style="background: #f8d7da; color: #721c24; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px;">{{ $errors->first('invite_email') }}</div>
                @endif

                <form method="POST" action="{{ route('invitation.send') }}" style="display: flex; gap: 8px; align-items: end;">
                    @csrf
                    <div style="flex: 1;">
                        <label for="invite_email">{{ __('ui.email') }}</label>
                        <input type="email" name="email" id="invite_email" placeholder="colleague@example.com" required style="margin-bottom: 0;">
                    </div>
                    <div style="width: 120px;">
                        <label for="invite_role">{{ __('ui.invite_role') }}</label>
                        <select name="role" id="invite_role" style="width: 100%; padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 14px;">
                            <option value="member">Member</option>
                            <option value="owner">Owner</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary" style="white-space: nowrap;">{{ __('ui.send_invitation') }}</button>
                </form>

                @if(session('invite_url'))
                    <div style="margin-top: 16px; padding: 16px; background: #f0f7ff; border: 1px solid #b3d4fc; border-radius: 8px;">
                        <div style="font-size: 13px; font-weight: 600; color: #1a73e8; margin-bottom: 8px;">{{ __('ui.invite_created') }}</div>
                        <div style="display: flex; align-items: center; gap: 8px; padding: 10px 12px; background: #fff; border: 1px solid #d2d2d7; border-radius: 6px;">
                            <input type="text" id="invite_url_field" value="{{ session('invite_url') }}" readonly style="flex: 1; border: none; background: none; font-family: monospace; font-size: 13px; color: #1d1d1f; padding: 0; margin: 0; outline: none;">
                            <button type="button" id="copy_invite_url" onclick="navigator.clipboard.writeText(document.getElementById('invite_url_field').value); this.textContent='Copied!'; setTimeout(() => this.textContent='{{ __('ui.copy') }}', 2000)" style="background: #0071e3; color: #fff; border: none; padding: 6px 14px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 500; white-space: nowrap;">{{ __('ui.copy') }}</button>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Pending invitations --}}
            @if($pendingInvitations->isNotEmpty())
                <div class="card">
                    <h2>{{ __('ui.pending_invitations') }} ({{ $pendingInvitations->count() }})</h2>
                    <ul class="member-list">
                        @foreach($pendingInvitations as $invitation)
                            <li class="member-item">
                                <div class="member-avatar" style="background: #aaa;">?</div>
                                <div class="member-info">
                                    <div class="member-name">{{ $invitation->email }}</div>
                                    <div class="member-email">{{ __('ui.invited_by_short', ['name' => $invitation->inviter->name]) }} · {{ $invitation->created_at->format('Y-m-d') }}</div>
                                </div>
                                @if($invitation->isExpired())
                                    <span class="badge badge-expired">{{ __('ui.expired') }}</span>
                                @else
                                    <span class="badge badge-pending">{{ __('ui.pending') }}</span>
                                @endif
                                <form method="POST" action="{{ route('invitation.cancel', $invitation) }}" style="margin: 0;">
                                    @csrf @method('DELETE')
                                    <button type="submit" style="background: none; border: 1px solid #d2d2d7; border-radius: 4px; padding: 2px 8px; font-size: 11px; color: #8e8e93; cursor: pointer;">{{ __('ui.cancel') }}</button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>
@endsection
