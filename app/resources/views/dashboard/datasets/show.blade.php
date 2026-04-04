{{-- Knowledge package detail: metadata, lifecycle action buttons, stats card, and included KU table. --}}
@extends('layouts.app')
@section('title', $package->name . ' v' . $package->version)

@section('extra-styles')
    .page-header { margin-bottom: 4px; }
    .page-header h1 { font-size: 22px; font-weight: 600; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .subtitle { color: #5f6368; font-size: 13px; margin-bottom: 16px; }
    .actions { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px; }
    .meta-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 16px; }
    .meta-label { font-size: 11px; color: #5f6368; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 2px; }
    .meta-value { font-size: 20px; font-weight: 600; }
    .flash-success { background: #d4edda; color: #155724; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
    .flash-error { background: #f8d7da; color: #721c24; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
    .pending-note { font-size: 13px; color: #5f6368; align-self: center; }
@endsection

@section('body')
<div class="page-content">
    <div class="page-container">

        @if(session('success'))
            <div class="flash-success">{{ session('success') }}</div>
        @endif
        @if($errors->any())
            <div class="flash-error">{{ $errors->first() }}</div>
        @endif

        <div class="page-header">
            <h1>{{ $package->name }} <span class="badge badge-{{ $package->status }}">{{ $package->status === 'publication_requested' ? __('ui.publication_requested') : ucfirst($package->status) }}</span></h1>
        </div>
        <p class="subtitle">Version {{ $package->version }} &middot; {{ $package->ku_count }} {{ __('ui.knowledge_units') }} &middot; {{ $package->created_at->format('Y-m-d H:i') }}</p>

        @if($package->description)
            <p style="margin-bottom: 16px; color: #424245; font-size: 14px;">{{ $package->description }}</p>
        @endif

        <div class="actions">
            {{-- Draft: submit for publication request, or owner can publish directly --}}
            @if($package->isEditable())
                <form method="POST" action="{{ route('kp.submit-review', $package) }}">
                    @csrf
                    <button type="submit" class="btn btn-primary">{{ __('ui.submit_for_review') }}</button>
                </form>
                @if(auth()->user()->isOwner() || auth()->user()->isSystemAdmin())
                    <form method="POST" action="{{ route('kp.publish', $package) }}">
                        @csrf
                        <button type="submit" class="btn btn-success" onclick="return confirm('{{ __('ui.publish_confirm') }}')">{{ __('ui.publish_directly') }}</button>
                    </form>
                @endif
            @endif

            {{-- Publication requested: owner authorizes or rejects --}}
            @if($package->isPendingReview())
                @if(auth()->user()->isOwner() || auth()->user()->isSystemAdmin())
                    <form method="POST" action="{{ route('kp.publish', $package) }}">
                        @csrf
                        <button type="submit" class="btn btn-success" onclick="return confirm('{{ __('ui.publish_confirm') }}')">{{ __('ui.approve_publish') }}</button>
                    </form>
                    <form method="POST" action="{{ route('kp.reject-review', $package) }}">
                        @csrf
                        <button type="submit" class="btn btn-outline">{{ __('ui.reject_review') }}</button>
                    </form>
                @else
                    <span class="pending-note">{{ __('ui.owner_approval_required') }}</span>
                @endif
            @endif

            {{-- Published: new version, export, chat --}}
            @if($package->isPublished())
                <form method="POST" action="{{ route('kp.new-version', $package) }}">
                    @csrf
                    <button type="submit" class="btn btn-primary">{{ __('ui.new_version') }} (v{{ $package->version + 1 }})</button>
                </form>
                <a href="{{ route('kp.export', $package) }}" class="btn btn-outline">{{ __('ui.export_json') }}</a>
                <a href="{{ route('kp.evaluation', $package) }}" class="btn btn-outline">{{ __('ui.evaluation') }}</a>
                <a href="{{ route('kp.chat', $package) }}" class="btn btn-primary">{{ __('ui.chat') }}</a>
            @endif
        </div>

        {{-- Stats --}}
        <div class="card">
            <div class="meta-grid">
                <div>
                    <div class="meta-label">{{ __('ui.knowledge_units') }}</div>
                    <div class="meta-value">{{ $package->ku_count }}</div>
                </div>
                <div>
                    <div class="meta-label">{{ __('ui.status') }}</div>
                    <div class="meta-value" style="font-size: 15px; font-weight: 500;">{{ ucfirst($package->status) }}</div>
                </div>
                <div>
                    <div class="meta-label">{{ __('ui.version') }}</div>
                    <div class="meta-value">v{{ $package->version }}</div>
                </div>
                <div>
                    <div class="meta-label">{{ __('ui.created_by') }}</div>
                    <div class="meta-value" style="font-size: 15px; font-weight: 500;">{{ $package->creator?->name ?? 'System' }}</div>
                </div>
            </div>
        </div>

        {{-- KU items table --}}
        <div class="card">
            <h2>{{ __('ui.knowledge_units') }}</h2>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>{{ __('ui.topic') }}</th>
                        <th>{{ __('ui.intent') }}</th>
                        <th>{{ __('ui.rows') }}</th>
                        <th>{{ __('ui.confidence') }}</th>
                        <th>{{ __('ui.ku_version') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($package->items as $item)
                        <tr>
                            <td>{{ $item->sort_order + 1 }}</td>
                            <td>
                                <a href="{{ route('knowledge-units.show', $item->knowledge_unit_id) }}" style="color: #0071e3; text-decoration: none;">
                                    {{ $item->knowledgeUnit->topic }}
                                </a>
                            </td>
                            <td style="color: #5f6368; font-size: 13px;">{{ $item->knowledgeUnit->intent }}</td>
                            <td>{{ $item->knowledgeUnit->row_count }}</td>
                            <td>{{ number_format($item->knowledgeUnit->confidence * 100) }}%</td>
                            <td>v{{ $item->included_version }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Embed Settings — only shown for published packages --}}
        @if($package->isPublished())
        <div class="card" id="embed-section">
            <h2>{{ __('ui.embed_settings') }}</h2>

            {{-- Generate new key form --}}
            <div style="display: flex; gap: 8px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 16px;">
                <div style="flex: 1; min-width: 200px;">
                    <label style="font-size: 12px; color: #5f6368; display: block; margin-bottom: 4px;">{{ __('ui.embed_domains') }}</label>
                    <input type="text" id="embed-domains" placeholder="{{ __('ui.embed_domains_placeholder') }}"
                           style="width: 100%; padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 13px;">
                    <span style="font-size: 11px; color: #86868b;">{{ __('ui.embed_domains_hint') }}</span>
                </div>
                <button onclick="generateApiKey()" class="btn btn-primary" style="white-space: nowrap;">{{ __('ui.embed_generate_key') }}</button>
            </div>

            {{-- Generated key display (hidden until generated) --}}
            <div id="embed-key-result" style="display: none; background: #fff3cd; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px;">
                <p style="font-size: 12px; font-weight: 600; color: #856404; margin-bottom: 6px;">{{ __('ui.embed_key_warning') }}</p>
                <div style="display: flex; gap: 8px; align-items: center;">
                    <code id="embed-key-value" style="flex: 1; font-size: 12px; padding: 6px 10px; background: #fff; border: 1px solid #d2d2d7; border-radius: 6px; word-break: break-all;"></code>
                    <button onclick="copyKey()" class="btn btn-sm btn-outline">{{ __('ui.embed_copy_key') }}</button>
                </div>
            </div>

            {{-- Embed code snippet + demo link --}}
            <div id="embed-snippet" style="display: none; margin-bottom: 16px;">
                <label style="font-size: 12px; font-weight: 600; color: #5f6368; display: block; margin-bottom: 4px;">{{ __('ui.embed_snippet') }}</label>
                <pre id="embed-snippet-code" style="background: #f5f5f7; padding: 12px; border-radius: 8px; font-size: 11px; overflow-x: auto; white-space: pre-wrap;"></pre>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 6px;">
                    <span style="font-size: 11px; color: #86868b;">{{ __('ui.embed_snippet_hint') }}</span>
                    <a id="embed-demo-link" href="#" target="_blank" class="btn btn-sm btn-primary" style="font-size: 12px; gap: 4px;">
                        🌐 {{ __('ui.demo_site') }}
                    </a>
                </div>
            </div>

            {{-- Existing keys table --}}
            <table id="embed-keys-table">
                <thead>
                    <tr>
                        <th>{{ __('ui.embed_key_prefix') }}</th>
                        <th>{{ __('ui.embed_domains') }}</th>
                        <th>{{ __('ui.embed_status') }}</th>
                        <th>{{ __('ui.embed_total_requests') }}</th>
                        <th>{{ __('ui.embed_last_used') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="embed-keys-body">
                    <tr><td colspan="6" style="text-align: center; color: #86868b; padding: 20px;">{{ __('ui.loading') }}...</td></tr>
                </tbody>
            </table>
        </div>
        @endif

    </div>
</div>
@endsection

@section('scripts')
@if($package->isPublished())
(function() {
    var packageId = {{ $package->id }};
    var appUrl = @json(rtrim(config('app.url'), '/'));

    // Load existing keys on page load
    loadKeys();

    function loadKeys() {
        var tbody = document.getElementById('embed-keys-body');
        fetch('/knowledge-packages/' + packageId + '/api-keys', {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
        .then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function(data) {
            if (!data.keys || data.keys.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#86868b;padding:20px;">{{ __("ui.embed_no_keys") }}</td></tr>';
                return;
            }
            tbody.innerHTML = '';
            data.keys.forEach(function(k) {
                var tr = document.createElement('tr');
                tr.innerHTML = '<td><code style="font-size:12px;">' + esc(k.key_prefix) + '...</code></td>'
                    + '<td style="font-size:12px;">' + esc(k.allowed_domains.join(', ')) + '</td>'
                    + '<td><span class="badge badge-' + (k.status === 'active' ? 'approved' : 'rejected') + '">' + esc(k.status) + '</span></td>'
                    + '<td>' + k.total_requests + '</td>'
                    + '<td style="font-size:12px;color:#5f6368;">' + (k.last_used_at ? new Date(k.last_used_at).toLocaleDateString() : '-') + '</td>'
                    + '<td>' + (k.status === 'active' ? '<button onclick="revokeKey(' + k.id + ')" class="btn btn-sm btn-danger">{{ __("ui.embed_revoke") }}</button>' : '') + '</td>';
                tbody.appendChild(tr);
            });
        })
        .catch(function() {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#86868b;padding:20px;">{{ __("ui.embed_no_keys") }}</td></tr>';
        });
    }

    window.generateApiKey = function() {
        var domains = document.getElementById('embed-domains').value.trim();
        if (!domains) { alert('{{ __("ui.embed_domains_required") }}'); return; }

        fetch('/knowledge-packages/' + packageId + '/api-keys', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ allowed_domains: domains }),
        })
        .then(function(r) {
            if (!r.ok) {
                return r.text().then(function(t) {
                    try { var d = JSON.parse(t); } catch(e) { throw new Error('Server error ' + r.status); }
                    var msg = d.error || d.message || (d.errors ? Object.values(d.errors).flat().join(', ') : 'Error ' + r.status);
                    throw new Error(msg);
                });
            }
            return r.json();
        })
        .then(function(data) {
            // Show the plaintext key
            document.getElementById('embed-key-value').textContent = data.key;
            document.getElementById('embed-key-result').style.display = 'block';

            // Show embed snippet
            var snippet = '<' + 'script src="' + appUrl + '/widget.js"\n'
                + '        data-key="' + data.key + '"\n'
                + '        data-title="{{ $package->name }}"\n'
                + '        data-theme="light">\n'
                + '</' + 'script>';
            document.getElementById('embed-snippet-code').textContent = snippet;
            document.getElementById('embed-snippet').style.display = 'block';
            document.getElementById('embed-demo-link').href = '/embed/demo/' + encodeURIComponent(data.key);

            document.getElementById('embed-domains').value = '';
            loadKeys();
        })
        .catch(function(err) {
            alert(err.message || 'An error occurred.');
        });
    };

    window.copyKey = function() {
        var key = document.getElementById('embed-key-value').textContent;
        navigator.clipboard.writeText(key);
    };

    window.revokeKey = function(id) {
        if (!confirm('{{ __("ui.embed_revoke_confirm") }}')) return;

        fetch('/api-keys/' + id, {
            method: 'DELETE',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
        .then(function() { loadKeys(); });
    };

    function esc(str) {
        var d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }
})();
@endif
@endsection
