{{-- Tenant settings page: manage tenant name, view members and pending invitations. --}}
@extends('layouts.app')
@section('title', __('ui.tenant_settings') . ' — KPS')

@section('extra-styles')
        label { display: block; font-weight: 500; margin-bottom: 4px; font-size: 13px; color: #5f6368; }
        input[type="text"] {
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
            <h1 style="font-size: 20px; font-weight: 600; margin-bottom: 24px;">{{ __('ui.tenant_settings') }}</h1>

            @if(session('success'))
                <div style="background: #d4edda; color: #155724; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px;">✓ {{ session('success') }}</div>
            @endif

            {{-- Tenant name --}}
            <div class="card">
                <h2>{{ __('ui.tenant_name') }}</h2>
                <form method="POST" action="{{ route('tenant.update') }}">
                    @csrf @method('PUT')
                    <label for="name">{{ __('ui.name') }}</label>
                    <input type="text" name="name" id="name" value="{{ old('name', $tenant->name) }}" required>
                    <button type="submit" class="btn btn-primary">{{ __('ui.save') }}</button>
                </form>
            </div>

            {{-- Members --}}
            <div class="card">
                <h2>{{ __('ui.members') }} ({{ $members->count() }})</h2>
                <ul class="member-list">
                    @foreach($members as $member)
                        <li class="member-item">
                            <div class="member-avatar">{{ strtoupper(substr($member->name, 0, 1)) }}</div>
                            <div class="member-info">
                                <div class="member-name">{{ $member->name }}</div>
                                <div class="member-email">{{ $member->email }}</div>
                            </div>
                            <div style="font-size: 12px; color: #5f6368;">{{ $member->created_at->format('Y-m-d') }}</div>
                        </li>
                    @endforeach
                </ul>
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
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>
@endsection
