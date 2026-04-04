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
                <a href="{{ route('kp.export-faq', $package) }}" class="btn btn-outline">{{ __('ui.export_faq') }}</a>
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

        {{-- Chatbot Appearance — customize widget text, icon, and openers --}}
        <div class="card" id="appearance-section">
            <h2>{{ __('ui.chatbot_appearance') }}</h2>
            <p style="font-size: 13px; color: #86868b; margin-bottom: 16px;">{{ __('ui.chatbot_appearance_hint') }}</p>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                {{-- Title --}}
                <div>
                    <label style="font-size: 12px; color: #5f6368; display: block; margin-bottom: 4px;">{{ __('ui.appearance_title') }}</label>
                    <input type="text" id="cfg-title" maxlength="100"
                           placeholder="{{ $package->name }}"
                           value="{{ $package->embed_config_json['title'] ?? '' }}"
                           style="width: 100%; padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 13px;">
                </div>
                {{-- Theme --}}
                <div>
                    <label style="font-size: 12px; color: #5f6368; display: block; margin-bottom: 4px;">{{ __('ui.appearance_theme') }}</label>
                    <select id="cfg-theme" style="width: 100%; padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 13px; background: #fff;">
                        <option value="light" {{ ($package->embed_config_json['theme'] ?? 'light') === 'light' ? 'selected' : '' }}>Light</option>
                        <option value="dark" {{ ($package->embed_config_json['theme'] ?? '') === 'dark' ? 'selected' : '' }}>Dark</option>
                    </select>
                </div>
                {{-- Accent color --}}
                <div>
                    <label style="font-size: 12px; color: #5f6368; display: block; margin-bottom: 4px;">{{ __('ui.appearance_color') }}</label>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <input type="color" id="cfg-color" value="{{ $package->embed_config_json['color'] ?? '#0071e3' }}"
                               style="width: 40px; height: 36px; border: 1px solid #d2d2d7; border-radius: 8px; padding: 2px; cursor: pointer;">
                        <input type="text" id="cfg-color-text" value="{{ $package->embed_config_json['color'] ?? '#0071e3' }}" maxlength="7"
                               style="flex: 1; padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 13px; font-family: monospace;">
                    </div>
                </div>
                {{-- Placeholder --}}
                <div>
                    <label style="font-size: 12px; color: #5f6368; display: block; margin-bottom: 4px;">{{ __('ui.appearance_placeholder') }}</label>
                    <input type="text" id="cfg-placeholder" maxlength="200"
                           placeholder="Type your question..."
                           value="{{ $package->embed_config_json['placeholder'] ?? '' }}"
                           style="width: 100%; padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 13px;">
                </div>
            </div>

            {{-- Greeting --}}
            <div style="margin-top: 16px;">
                <label style="font-size: 12px; color: #5f6368; display: block; margin-bottom: 4px;">{{ __('ui.appearance_greeting') }}</label>
                <textarea id="cfg-greeting" maxlength="500" rows="2"
                          placeholder="{{ __('ui.appearance_greeting_placeholder') }}"
                          style="width: 100%; padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 13px; resize: vertical;">{{ $package->embed_config_json['greeting'] ?? '' }}</textarea>
            </div>

            {{-- Bot icon URL --}}
            <div style="margin-top: 16px;">
                <label style="font-size: 12px; color: #5f6368; display: block; margin-bottom: 4px;">{{ __('ui.appearance_icon_url') }}</label>
                <div style="display: flex; gap: 8px; align-items: center;">
                    <input type="url" id="cfg-icon-url" maxlength="500"
                           placeholder="https://example.com/bot-icon.png"
                           value="{{ $package->embed_config_json['icon_url'] ?? '' }}"
                           style="flex: 1; padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 13px;">
                    <div id="icon-preview" style="width: 36px; height: 36px; border-radius: 50%; border: 1px solid #d2d2d7; overflow: hidden; flex-shrink: 0; display: flex; align-items: center; justify-content: center; background: #f5f5f7;">
                        @if(!empty($package->embed_config_json['icon_url']))
                            <img src="{{ $package->embed_config_json['icon_url'] }}" style="width: 100%; height: 100%; object-fit: cover;" onerror="this.style.display='none'">
                        @else
                            <span style="font-size: 10px; color: #aaa;">N/A</span>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Openers (suggested questions) --}}
            <div style="margin-top: 16px;">
                <label style="font-size: 12px; color: #5f6368; display: block; margin-bottom: 4px;">{{ __('ui.appearance_openers') }}</label>
                <span style="font-size: 11px; color: #86868b; display: block; margin-bottom: 8px;">{{ __('ui.appearance_openers_hint') }}</span>
                @for($i = 0; $i < 3; $i++)
                <input type="text" class="cfg-opener" maxlength="200"
                       placeholder="{{ __('ui.appearance_opener_placeholder', ['n' => $i + 1]) }}"
                       value="{{ $package->embed_config_json['openers'][$i] ?? '' }}"
                       style="width: 100%; padding: 8px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 13px; margin-bottom: 6px;">
                @endfor
                {{-- Hint: top KU topics by usage_count --}}
                @php
                    $topKUs = $package->items
                        ->map(fn($item) => $item->knowledgeUnit)
                        ->filter(fn($ku) => $ku && ($ku->row_count ?? 0) > 0)
                        ->sortByDesc('row_count')
                        ->take(5);
                @endphp
                @if($topKUs->isNotEmpty())
                <div style="margin-top: 4px; font-size: 11px; color: #86868b;">
                    💡 {{ __('ui.appearance_openers_suggestion') }}
                    @foreach($topKUs as $ku)
                        <span style="background: #f0f0f2; padding: 2px 8px; border-radius: 10px; margin: 2px; display: inline-block; cursor: pointer;" onclick="fillOpener('{{ addslashes($ku->question ?: $ku->topic) }}')">{{ Str::limit($ku->question ?: $ku->topic, 40) }} ({{ $ku->row_count }})</span>
                    @endforeach
                </div>
                @endif
            </div>

            {{-- Save button --}}
            <div style="margin-top: 16px; display: flex; align-items: center; gap: 12px;">
                <button onclick="saveAppearance()" class="btn btn-primary">{{ __('ui.save') }}</button>
                <span id="appearance-status" style="font-size: 13px; color: #34c759; display: none;">{{ __('ui.saved') }}</span>
            </div>
        </div>

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
    var defaultTitle = @json($package->name);
    var csrfToken = document.querySelector('meta[name="csrf-token"]').content;

    // Sync color picker with text input
    var colorPicker = document.getElementById('cfg-color');
    var colorText = document.getElementById('cfg-color-text');
    if (colorPicker && colorText) {
        colorPicker.addEventListener('input', function() { colorText.value = this.value; });
        colorText.addEventListener('input', function() {
            if (/^#[0-9a-fA-F]{3,6}$/.test(this.value)) colorPicker.value = this.value;
        });
    }

    // Live preview for icon URL
    var iconInput = document.getElementById('cfg-icon-url');
    if (iconInput) {
        iconInput.addEventListener('change', function() {
            var preview = document.getElementById('icon-preview');
            if (this.value) {
                preview.innerHTML = '<img src="' + esc(this.value) + '" style="width:100%;height:100%;object-fit:cover;" onerror="this.parentNode.innerHTML=\'<span style=\\\'font-size:10px;color:#aaa\\\'>Error</span>\'">';
            } else {
                preview.innerHTML = '<span style="font-size:10px;color:#aaa;">N/A</span>';
            }
        });
    }

    // Fill opener from suggestion chip
    window.fillOpener = function(text) {
        var inputs = document.querySelectorAll('.cfg-opener');
        for (var i = 0; i < inputs.length; i++) {
            if (!inputs[i].value.trim()) { inputs[i].value = text; return; }
        }
        // All full: replace the last one
        inputs[inputs.length - 1].value = text;
    };

    // Save appearance config via AJAX
    window.saveAppearance = function() {
        var openers = [];
        document.querySelectorAll('.cfg-opener').forEach(function(el) {
            if (el.value.trim()) openers.push(el.value.trim());
        });

        var payload = {
            title: document.getElementById('cfg-title').value.trim() || null,
            greeting: document.getElementById('cfg-greeting').value.trim() || null,
            placeholder: document.getElementById('cfg-placeholder').value.trim() || null,
            theme: document.getElementById('cfg-theme').value,
            color: document.getElementById('cfg-color-text').value || '#0071e3',
            icon_url: document.getElementById('cfg-icon-url').value.trim() || null,
            openers: openers.length > 0 ? openers : [],
        };

        fetch('/knowledge-packages/' + packageId + '/embed-config', {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(payload),
        })
        .then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function() {
            var status = document.getElementById('appearance-status');
            status.style.display = 'inline';
            setTimeout(function() { status.style.display = 'none'; }, 3000);
            // Reload key list so snippets reflect new config
            loadKeys();
        })
        .catch(function(err) { alert('Save failed: ' + err.message); });
    };

    // Read current appearance config from form fields
    function getAppearanceConfig() {
        return {
            title: document.getElementById('cfg-title').value.trim() || defaultTitle,
            theme: document.getElementById('cfg-theme').value || 'light',
            color: document.getElementById('cfg-color-text').value || '#0071e3',
            greeting: document.getElementById('cfg-greeting').value.trim() || '',
            placeholder: document.getElementById('cfg-placeholder').value.trim() || '',
            icon_url: document.getElementById('cfg-icon-url').value.trim() || '',
            openers: Array.from(document.querySelectorAll('.cfg-opener')).map(function(el) { return el.value.trim(); }).filter(Boolean),
        };
    }

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
            var cfg = getAppearanceConfig();

            data.keys.forEach(function(k) {
                var card = document.createElement('div');
                card.style.cssText = 'border:1px solid ' + (k.status === 'active' ? '#d2d2d7' : '#f0f0f2') + ';border-radius:10px;padding:14px 18px;margin-bottom:12px;' + (k.status !== 'active' ? 'opacity:0.5;' : '');

                var headerActions = '';
                if (k.status === 'active') {
                    headerActions = '<button onclick="revokeKey(' + k.id + ')" class="btn btn-sm btn-danger" style="font-size:11px;">{{ __("ui.embed_revoke") }}</button>';
                } else {
                    headerActions = '<button onclick="deleteKey(' + k.id + ')" class="btn btn-sm btn-danger" style="font-size:11px;">{{ __("ui.delete") }}</button>';
                }

                var header = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">'
                    + '<div style="display:flex;align-items:center;gap:8px;">'
                    + '<span class="badge badge-' + (k.status === 'active' ? 'approved' : 'rejected') + '">' + esc(k.status) + '</span>'
                    + '<span style="font-size:12px;color:#5f6368;">' + esc(k.allowed_domains.join(', ')) + '</span>'
                    + '<span style="font-size:11px;color:#aaa;">' + k.total_requests + ' requests</span>'
                    + '</div>'
                    + headerActions
                    + '</div>';

                var body = '';
                if (k.status === 'active' && k.api_key) {
                    // Build iframe URL (includes saved appearance params)
                    var iframeUrl = appUrl + '/embed/chat/' + encodeURIComponent(k.api_key);
                    var iframeTag = '<' + 'iframe src="' + iframeUrl + '" width="400" height="600" style="border:none;"></' + 'iframe>';

                    // Build widget snippet with saved appearance config
                    var snippetLines = [
                        '<' + 'script src="' + appUrl + '/widget.js"',
                        '        data-key="' + esc(k.api_key) + '"',
                        '        data-title="' + esc(cfg.title) + '"',
                        '        data-theme="' + esc(cfg.theme) + '"',
                    ];
                    if (cfg.color && cfg.color !== '#0071e3') snippetLines.push('        data-color="' + esc(cfg.color) + '"');
                    if (cfg.greeting) snippetLines.push('        data-greeting="' + esc(cfg.greeting) + '"');
                    if (cfg.placeholder) snippetLines.push('        data-placeholder="' + esc(cfg.placeholder) + '"');
                    if (cfg.icon_url) snippetLines.push('        data-icon="' + esc(cfg.icon_url) + '"');
                    if (cfg.openers.length > 0) snippetLines.push('        data-openers=\'' + JSON.stringify(cfg.openers) + '\'');
                    snippetLines.push('></' + 'script>');
                    var snippet = snippetLines.join('\n');

                    // Row 1: Full-page (iframe)
                    body = '<div style="display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid #f0f0f2;">'
                        + '<span style="font-size:13px;font-weight:500;min-width:100px;">{{ __("ui.embed_type_fullpage") }}</span>'
                        + '<button onclick="navigator.clipboard.writeText(' + JSON.stringify(iframeTag).replace(/'/g, "\\'") + ')" class="btn btn-sm btn-outline" style="font-size:11px;">{{ __("ui.embed_copy_iframe") }}</button>'
                        + '<a href="/embed/chat/' + encodeURIComponent(k.api_key) + '" target="_blank" class="btn btn-sm btn-outline" style="font-size:11px;">{{ __("ui.demo_preview") }}</a>'
                        + '</div>';

                    // Row 2: Widget (script)
                    body += '<div style="display:flex;align-items:center;gap:8px;padding:8px 0;">'
                        + '<span style="font-size:13px;font-weight:500;min-width:100px;">{{ __("ui.embed_type_widget") }}</span>'
                        + '<button onclick="navigator.clipboard.writeText(' + JSON.stringify(snippet).replace(/'/g, "\\'") + ')" class="btn btn-sm btn-outline" style="font-size:11px;">{{ __("ui.embed_copy_snippet") }}</button>'
                        + '<a href="/embed/demo/' + encodeURIComponent(k.api_key) + '" target="_blank" class="btn btn-sm btn-outline" style="font-size:11px;">{{ __("ui.demo_preview") }}</a>'
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
                'X-CSRF-TOKEN': csrfToken,
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
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
        })
        .then(function() { loadKeys(); });
    };

    window.deleteKey = function(id) {
        if (!confirm('{{ __("ui.embed_delete_confirm") }}')) return;

        fetch('/api-keys/' + id + '/destroy', {
            method: 'DELETE',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
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
