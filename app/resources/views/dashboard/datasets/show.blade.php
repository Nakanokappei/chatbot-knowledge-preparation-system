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
            {{-- Draft: publish button --}}
            @if($package->status === 'draft')
                <form method="POST" action="{{ route('kp.publish', $package) }}">
                    @csrf
                    <button type="submit" class="btn btn-success" onclick="return confirm('{{ __('ui.publish_confirm') }}')">{{ __('ui.publish') }}</button>
                </form>
            @endif

            {{-- Published: refresh KUs, export, evaluation, chat --}}
            @if($package->isPublished())
                <form method="POST" action="{{ route('kp.refresh-kus', $package) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline" onclick="return confirm('{{ __('ui.refresh_kus_confirm') }}')">{{ __('ui.refresh_kus') }}</button>
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

        {{-- Publish Settings — only shown for published packages, before KU table --}}
        @if($package->isPublished())
        <div class="card" id="embed-section">
            <h2>{{ __('ui.publish_settings') }}</h2>

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

            {{-- Active keys with embed code --}}
            <div id="embed-keys-body">
                <p style="text-align: center; color: #86868b; padding: 20px; font-size: 13px;">{{ __('ui.loading') }}...</p>
            </div>
        </div>
        @endif

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
        var container = document.getElementById('embed-keys-body');
        fetch('/knowledge-packages/' + packageId + '/api-keys', {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
        .then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function(data) {
            if (!data.keys || data.keys.length === 0) {
                container.innerHTML = '<p style="text-align:center;color:#86868b;padding:20px;font-size:13px;">{{ __("ui.embed_no_keys") }}</p>';
                return;
            }
            container.innerHTML = '';
            data.keys.forEach(function(k) {
                var card = document.createElement('div');
                card.style.cssText = 'border:1px solid ' + (k.status === 'active' ? '#d2d2d7' : '#f0f0f2') + ';border-radius:10px;padding:14px 18px;margin-bottom:12px;' + (k.status !== 'active' ? 'opacity:0.5;' : '');

                var header = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">'
                    + '<div style="display:flex;align-items:center;gap:8px;">'
                    + '<span class="badge badge-' + (k.status === 'active' ? 'approved' : 'rejected') + '">' + esc(k.status) + '</span>'
                    + '<span style="font-size:12px;color:#5f6368;">' + esc(k.allowed_domains.join(', ')) + '</span>'
                    + '<span style="font-size:11px;color:#aaa;">' + k.total_requests + ' requests</span>'
                    + '</div>'
                    + (k.status === 'active' ? '<button onclick="revokeKey(' + k.id + ')" class="btn btn-sm btn-danger" style="font-size:11px;">{{ __("ui.embed_revoke") }}</button>' : '')
                    + '</div>';

                var body = '';
                if (k.status === 'active' && k.api_key) {
                    var snippet = '<' + 'script src="' + appUrl + '/widget.js"\n'
                        + '        data-key="' + esc(k.api_key) + '"\n'
                        + '        data-title="{{ $package->name }}"\n'
                        + '        data-theme="light">\n'
                        + '</' + 'script>';
                    body = '<pre style="background:#f5f5f7;padding:10px;border-radius:8px;font-size:11px;overflow-x:auto;white-space:pre-wrap;margin-bottom:8px;">' + esc(snippet) + '</pre>'
                        + '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">'
                        + '<button onclick="navigator.clipboard.writeText(' + JSON.stringify(snippet).replace(/'/g, "\\'") + ')" class="btn btn-sm btn-outline" style="font-size:11px;">{{ __("ui.embed_copy_snippet") }}</button>'
                        + '<span style="font-size:11px;color:#5f6368;margin-left:8px;">{{ __("ui.demo_preview") }}</span>'
                        + '<a href="/embed/chat/' + encodeURIComponent(k.api_key) + '" target="_blank" class="btn btn-sm btn-outline" style="font-size:11px;gap:3px;">🖼️ iframe</a>'
                        + '<a href="/embed/demo/' + encodeURIComponent(k.api_key) + '" target="_blank" class="btn btn-sm btn-primary" style="font-size:11px;gap:3px;">📜 script</a>'
                        + '</div>';
                } else if (k.status === 'active') {
                    body = '<p style="font-size:12px;color:#86868b;">{{ __("ui.embed_key_no_plaintext") }}</p>';
                }

                card.innerHTML = header + body;
                container.appendChild(card);
            });
        })
        .catch(function() {
            container.innerHTML = '<p style="text-align:center;color:#86868b;padding:20px;font-size:13px;">{{ __("ui.embed_no_keys") }}</p>';
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
            document.getElementById('embed-domains').value = '';
            loadKeys();
        })
        .catch(function(err) {
            alert(err.message || 'An error occurred.');
        });
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
