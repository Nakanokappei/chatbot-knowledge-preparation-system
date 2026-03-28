{{-- API usage guide and interactive sandbox.
     All demo requests use session authentication — no API token is required.
     All queries are automatically scoped to the authenticated user's workspace. --}}
@extends('layouts.app')
@section('title', __('ui.api_guide') . ' — KPS')

@section('extra-styles')
        .guide-header { margin-bottom: 28px; }
        .guide-header h1 { font-size: 20px; font-weight: 600; margin-bottom: 4px; }
        .guide-header p { font-size: 13px; color: #5f6368; }

        .endpoint-card { background: #fff; border-radius: 12px; border: 1px solid #e5e5e7; margin-bottom: 20px; overflow: hidden; }
        .endpoint-header { padding: 16px 20px; display: flex; align-items: center; gap: 12px; cursor: pointer; user-select: none; }
        .endpoint-header:hover { background: #fafafa; }
        .method-badge { font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 4px; flex-shrink: 0; font-family: 'Courier New', monospace; }
        .method-get  { background: #d4edda; color: #155724; }
        .method-post { background: #cce5ff; color: #004085; }
        .endpoint-path { font-family: 'Courier New', monospace; font-size: 13px; font-weight: 600; color: #1d1d1f; flex: 1; }
        .endpoint-desc { font-size: 13px; color: #5f6368; }
        .chevron { flex-shrink: 0; transition: transform 0.2s; color: #8e8e93; }
        .endpoint-card.open .chevron { transform: rotate(180deg); }

        .endpoint-body { display: none; padding: 0 20px 20px; border-top: 1px solid #f0f0f2; }
        .endpoint-card.open .endpoint-body { display: block; }

        .section-label { font-size: 11px; font-weight: 600; color: #5f6368; text-transform: uppercase; letter-spacing: 0.5px; margin: 16px 0 8px; }
        .param-row { display: flex; gap: 12px; margin-bottom: 6px; font-size: 13px; align-items: flex-start; }
        .param-name { font-family: 'Courier New', monospace; color: #1d1d1f; min-width: 150px; flex-shrink: 0; }
        .param-type { font-size: 11px; color: #86868b; min-width: 60px; flex-shrink: 0; }
        .param-required { font-size: 11px; color: #ff3b30; }
        .param-desc { color: #5f6368; }

        pre.code-block { background: #1d1d1f; color: #34c759; padding: 14px 16px; border-radius: 8px; font-size: 12px; overflow-x: auto; margin: 0; line-height: 1.6; white-space: pre-wrap; }
        .code-tabs { display: flex; gap: 2px; margin-bottom: 8px; }
        .code-tab { padding: 4px 12px; font-size: 12px; border: none; border-radius: 4px; cursor: pointer; background: #f0f0f2; color: #5f6368; }
        .code-tab.active { background: #1d1d1f; color: #fff; }

        .sandbox { background: #f5f5f7; border-radius: 8px; padding: 16px; margin-top: 16px; }
        .sandbox h4 { font-size: 13px; font-weight: 600; margin-bottom: 12px; }
        .sandbox label { font-size: 12px; font-weight: 500; color: #5f6368; display: block; margin-bottom: 4px; }
        .sandbox input, .sandbox textarea, .sandbox select {
            width: 100%; padding: 7px 10px; border: 1px solid #d2d2d7; border-radius: 6px;
            font-size: 13px; margin-bottom: 10px; font-family: inherit; background: #fff;
        }
        .sandbox textarea { height: 72px; resize: vertical; }
        .sandbox-run { background: #0071e3; color: #fff; border: none; padding: 7px 16px; border-radius: 6px; font-size: 13px; cursor: pointer; }
        .sandbox-run:hover { background: #0062c4; }
        .sandbox-run:disabled { background: #a0a0a5; cursor: not-allowed; }
        .response-box { margin-top: 12px; display: none; }
        .response-status { font-size: 12px; font-weight: 600; margin-bottom: 6px; }
        .response-status.ok { color: #34c759; }
        .response-status.err { color: #ff3b30; }
        pre.response-body { background: #1d1d1f; color: #a5f3fc; padding: 12px; border-radius: 6px; font-size: 12px; max-height: 300px; overflow-y: auto; white-space: pre-wrap; margin: 0; }

        .auth-note { background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; padding: 12px 16px; font-size: 13px; margin-bottom: 24px; }
        .auth-note strong { color: #856404; }
        .base-url-box { background: #f5f5f7; border-radius: 8px; padding: 12px 16px; font-size: 13px; margin-bottom: 24px; }
        .base-url-box code { font-family: 'Courier New', monospace; color: #0071e3; }
@endsection

@section('body')
<div class="page-content">
    <div class="page-container">

        <div class="guide-header">
            <h1>{{ __('ui.api_guide') }}</h1>
            <p>{{ __('ui.api_guide_subtitle') }}</p>
        </div>

        {{-- Base URL --}}
        <div class="base-url-box">
            <strong>{{ __('ui.api_base_url') }}: </strong><code>{{ rtrim(config('app.url'), '/') }}/api</code>
        </div>

        {{-- Authentication info --}}
        <div class="auth-note">
            <strong>{{ __('ui.api_auth_label') }}</strong>: {!! __('ui.api_auth_note') !!}<br>
            <strong>{{ __('ui.api_sandbox_no_token') }}</strong>
            {{ __('ui.api_sandbox_workspace_scope') }}（<strong>{{ auth()->user()->workspace->name ?? '—' }}</strong>）
        </div>

        {{-- Published packages for the sandbox selectors --}}
        @php $datasetOptions = $packages->map(fn($p) => ['id' => $p->id, 'name' => $p->name])->toArray(); @endphp

        {{-- ── Endpoint 1: GET /datasets ───────────────────────────────────── --}}
        <div class="endpoint-card open" id="ep-datasets">
            <div class="endpoint-header" onclick="toggleCard('ep-datasets')">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/datasets</span>
                <span class="endpoint-desc">{{ __('ui.api_ep_datasets_title') }}</span>
                <svg class="chevron" width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </div>
            <div class="endpoint-body">
                <div class="section-label">{{ __('ui.api_section_description') }}</div>
                <p style="font-size: 13px; color: #5f6368; margin-bottom: 8px;">
                    {{ __('ui.api_ep_datasets_desc') }}
                </p>

                <div class="section-label">{{ __('ui.api_section_response_example') }}</div>
                <pre class="code-block">[
  {
    "id": 1,
    "name": "{{ __('ui.api_example_query') }}",
    "status": "published",
    "created_at": "2026-03-01T09:00:00Z"
  }
]</pre>

                <div class="section-label">{{ __('ui.api_section_code_example') }}</div>
                <div class="code-tabs">
                    <button class="code-tab active" onclick="showTab('datasets-curl', this)">curl</button>
                    <button class="code-tab" onclick="showTab('datasets-js', this)">JavaScript</button>
                </div>
                <pre class="code-block" id="datasets-curl">curl -H "Authorization: Bearer YOUR_TOKEN" \
  {{ rtrim(config('app.url'), '/') }}/api/datasets</pre>
                <pre class="code-block" id="datasets-js" style="display:none;">const res = await fetch('/api/datasets', {
  headers: { 'Authorization': 'Bearer YOUR_TOKEN' }
});
const data = await res.json();</pre>

                <div class="sandbox">
                    <h4>{{ __('ui.api_section_sandbox') }}</h4>
                    <button class="sandbox-run" onclick="runRequest('datasets-response', 'GET', '/api/datasets')">
                        {{ __('ui.api_sandbox_run') }}
                    </button>
                    <div class="response-box" id="datasets-response">
                        <div class="response-status"></div>
                        <pre class="response-body"></pre>
                    </div>
                </div>
            </div>
        </div>

        {{-- ── Endpoint 2: POST /retrieve ──────────────────────────────────── --}}
        <div class="endpoint-card" id="ep-retrieve">
            <div class="endpoint-header" onclick="toggleCard('ep-retrieve')">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/retrieve</span>
                <span class="endpoint-desc">{{ __('ui.api_ep_retrieve_title') }}</span>
                <svg class="chevron" width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </div>
            <div class="endpoint-body">
                <div class="section-label">{{ __('ui.api_section_description') }}</div>
                <p style="font-size: 13px; color: #5f6368; margin-bottom: 8px;">
                    {{ __('ui.api_ep_retrieve_desc') }}
                </p>

                <div class="section-label">{{ __('ui.api_section_parameters') }}</div>
                <div class="param-row"><span class="param-name">query</span><span class="param-type">string</span><span class="param-required">{{ __('ui.api_param_required') }}</span><span class="param-desc">{{ __('ui.api_param_query_desc') }}</span></div>
                <div class="param-row"><span class="param-name">package_id</span><span class="param-type">integer</span><span class="param-required">{{ __('ui.api_param_required') }}</span><span class="param-desc">{{ __('ui.api_param_package_id_desc') }}</span></div>
                <div class="param-row"><span class="param-name">top_k</span><span class="param-type">integer</span><span class="param-desc">{{ __('ui.api_param_topk_desc') }}</span></div>
                <div class="param-row"><span class="param-name">min_similarity</span><span class="param-type">float</span><span class="param-desc">{{ __('ui.api_param_min_sim_desc') }}</span></div>

                <div class="section-label">{{ __('ui.api_section_code_example') }}</div>
                <div class="code-tabs">
                    <button class="code-tab active" onclick="showTab('retrieve-curl', this)">curl</button>
                    <button class="code-tab" onclick="showTab('retrieve-js', this)">JavaScript</button>
                </div>
                <pre class="code-block" id="retrieve-curl">curl -X POST \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"query": "{{ __('ui.api_example_query') }}", "package_id": 1, "top_k": 5}' \
  {{ rtrim(config('app.url'), '/') }}/api/retrieve</pre>
                <pre class="code-block" id="retrieve-js" style="display:none;">const res = await fetch('/api/retrieve', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({
    query: '{{ __('ui.api_example_query') }}',
    package_id: 1,
    top_k: 5,
  }),
});
const data = await res.json();</pre>

                @if($packages->isEmpty())
                    <div class="sandbox">
                        <p style="font-size: 13px; color: #a0a0a5; text-align: center; padding: 8px 0;">
                            {!! __('ui.api_no_package_hint') !!}<br>
                            <a href="{{ route('kp.index') }}" style="color: #0071e3;">{{ __('ui.api_no_package_link_text') }}</a> {{ __('ui.api_no_package_link_suffix') }}
                        </p>
                    </div>
                @else
                    <div class="sandbox">
                        <h4>{{ __('ui.api_section_sandbox') }}</h4>
                        <label>{{ __('ui.api_sandbox_package_label') }}</label>
                        <select id="retrieve-dataset">
                            @foreach($packages as $p)
                                <option value="{{ $p->id }}">{{ $p->name }} (ID: {{ $p->id }})</option>
                            @endforeach
                        </select>
                        <label>{{ __('ui.api_sandbox_query_label') }}</label>
                        <textarea id="retrieve-query" placeholder="{{ __('ui.api_sandbox_query_placeholder') }}"></textarea>
                        <label>top_k</label>
                        <input type="number" id="retrieve-topk" value="5" min="1" max="20" style="max-width: 80px;">
                        <button class="sandbox-run" onclick="runRetrieve()">{{ __('ui.api_sandbox_run') }}</button>
                        <div class="response-box" id="retrieve-response">
                            <div class="response-status"></div>
                            <pre class="response-body"></pre>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        {{-- ── Endpoint 3: POST /chat ───────────────────────────────────────── --}}
        <div class="endpoint-card" id="ep-chat">
            <div class="endpoint-header" onclick="toggleCard('ep-chat')">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/chat</span>
                <span class="endpoint-desc">{{ __('ui.api_ep_chat_title') }}</span>
                <svg class="chevron" width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </div>
            <div class="endpoint-body">
                <div class="section-label">{{ __('ui.api_section_description') }}</div>
                <p style="font-size: 13px; color: #5f6368; margin-bottom: 8px;">
                    {!! __('ui.api_ep_chat_desc') !!}
                </p>

                <div class="section-label">{{ __('ui.api_section_parameters') }}</div>
                <div class="param-row"><span class="param-name">message</span><span class="param-type">string</span><span class="param-required">{{ __('ui.api_param_required') }}</span><span class="param-desc">{{ __('ui.api_param_message_desc') }}</span></div>
                <div class="param-row"><span class="param-name">package_id</span><span class="param-type">integer</span><span class="param-required">{{ __('ui.api_param_required') }}</span><span class="param-desc">{{ __('ui.api_param_package_id_desc') }}</span></div>
                <div class="param-row"><span class="param-name">conversation_id</span><span class="param-type">UUID</span><span class="param-desc">{{ __('ui.api_param_conv_id_desc') }}</span></div>
                <div class="param-row"><span class="param-name">top_k</span><span class="param-type">integer</span><span class="param-desc">{{ __('ui.api_param_topk_chat_desc') }}</span></div>

                <div class="section-label">{{ __('ui.api_section_code_example') }}</div>
                <div class="code-tabs">
                    <button class="code-tab active" onclick="showTab('chat-curl', this)">curl</button>
                    <button class="code-tab" onclick="showTab('chat-js', this)">JavaScript</button>
                </div>
                <pre class="code-block" id="chat-curl">curl -X POST \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"message": "{{ __('ui.api_example_message') }}", "package_id": 1}' \
  {{ rtrim(config('app.url'), '/') }}/api/chat</pre>
                <pre class="code-block" id="chat-js" style="display:none;">const res = await fetch('/api/chat', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({
    message: '{{ __('ui.api_example_message') }}',
    package_id: 1,
  }),
});
const data = await res.json();</pre>

                @if($packages->isEmpty())
                    <div class="sandbox">
                        <p style="font-size: 13px; color: #a0a0a5; text-align: center; padding: 8px 0;">
                            {!! __('ui.api_no_package_hint') !!}<br>
                            <a href="{{ route('kp.index') }}" style="color: #0071e3;">{{ __('ui.api_no_package_link_text') }}</a> {{ __('ui.api_no_package_link_suffix') }}
                        </p>
                    </div>
                @else
                    <div class="sandbox">
                        <h4>{{ __('ui.api_section_sandbox') }}</h4>
                        <label>{{ __('ui.api_sandbox_package_label') }}</label>
                        <select id="chat-dataset">
                            @foreach($packages as $p)
                                <option value="{{ $p->id }}">{{ $p->name }} (ID: {{ $p->id }})</option>
                            @endforeach
                        </select>
                        <label>{{ __('ui.api_sandbox_message_label') }}</label>
                        <textarea id="chat-message" placeholder="{{ __('ui.api_sandbox_msg_placeholder') }}"></textarea>
                        <button class="sandbox-run" onclick="runChat()" id="chat-run-btn">{{ __('ui.api_sandbox_run_llm') }}</button>
                        <div class="response-box" id="chat-response">
                            <div class="response-status"></div>
                            <pre class="response-body"></pre>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        {{-- ── Endpoint 4: GET /pipeline-jobs ──────────────────────────────── --}}
        <div class="endpoint-card" id="ep-jobs">
            <div class="endpoint-header" onclick="toggleCard('ep-jobs')">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/pipeline-jobs</span>
                <span class="endpoint-desc">{{ __('ui.api_ep_jobs_title') }}</span>
                <svg class="chevron" width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </div>
            <div class="endpoint-body">
                <div class="section-label">{{ __('ui.api_section_description') }}</div>
                <p style="font-size: 13px; color: #5f6368; margin-bottom: 8px;">
                    {{ __('ui.api_ep_jobs_desc') }}
                </p>

                <div class="section-label">{{ __('ui.api_section_code_example') }}</div>
                <pre class="code-block">curl -H "Authorization: Bearer YOUR_TOKEN" \
  {{ rtrim(config('app.url'), '/') }}/api/pipeline-jobs</pre>

                <div class="sandbox">
                    <h4>{{ __('ui.api_section_sandbox') }}</h4>
                    <button class="sandbox-run" onclick="runRequest('jobs-response', 'GET', '/api/pipeline-jobs')">{{ __('ui.api_sandbox_run') }}</button>
                    <div class="response-box" id="jobs-response">
                        <div class="response-status"></div>
                        <pre class="response-body"></pre>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>
@endsection

@section('scripts')
const csrfToken = '{{ csrf_token() }}';

// Localised UI strings for JavaScript (rendered server-side per locale)
const i18n = {
    running:     '{{ __("ui.api_sandbox_running") }}',
    networkErr:  '{{ __("ui.api_network_error") }}',
    runLlm:      '{{ __("ui.api_sandbox_run_llm") }}',
    generatingLlm: '{{ __("ui.api_sandbox_running_llm") }}',
    enterQuery:  '{{ __("ui.api_enter_query") }}',
    enterMsg:    '{{ __("ui.api_enter_message") }}',
};

// Toggle endpoint card open/close
function toggleCard(id) {
    document.getElementById(id).classList.toggle('open');
}

// Switch code example tabs
function showTab(targetId, btn) {
    const card = btn.closest('.endpoint-body');
    card.querySelectorAll('pre.code-block').forEach(p => p.style.display = 'none');
    card.querySelectorAll('.code-tab').forEach(t => t.classList.remove('active'));
    document.getElementById(targetId).style.display = 'block';
    btn.classList.add('active');
}

// Show/hide code blocks per tab on load: first block is visible, rest hidden
document.querySelectorAll('.endpoint-body').forEach(body => {
    const blocks = body.querySelectorAll('pre.code-block');
    let seenFirst = false;
    blocks.forEach(b => {
        if (!seenFirst && b.id) { b.style.display = 'block'; seenFirst = true; }
        else if (b.id) { b.style.display = 'none'; }
    });
});

// Generic GET/POST sandbox runner — uses session auth via CSRF
async function runRequest(responseId, method, path, body = null) {
    const box = document.getElementById(responseId);
    const statusEl = box.querySelector('.response-status');
    const bodyEl   = box.querySelector('.response-body');
    box.style.display = 'block';
    statusEl.textContent = i18n.running;
    statusEl.className = 'response-status';
    bodyEl.textContent = '';

    try {
        const opts = {
            method,
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
            credentials: 'same-origin',
        };
        if (body) {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body);
        }

        const res = await fetch(path, opts);
        const json = await res.json();
        statusEl.textContent = `HTTP ${res.status} ${res.statusText}`;
        statusEl.className = 'response-status ' + (res.ok ? 'ok' : 'err');
        bodyEl.textContent = JSON.stringify(json, null, 2);
    } catch (e) {
        statusEl.textContent = i18n.networkErr;
        statusEl.className = 'response-status err';
        bodyEl.textContent = e.message;
    }
}

// Retrieve sandbox — calls /web-api/retrieve (session auth) instead of /api/retrieve (token auth)
async function runRetrieve() {
    const query     = document.getElementById('retrieve-query').value.trim();
    const datasetId = parseInt(document.getElementById('retrieve-dataset').value);
    const topK      = parseInt(document.getElementById('retrieve-topk').value) || 5;
    if (!query) { alert(i18n.enterQuery); return; }
    await runRequest('retrieve-response', 'POST', '/web-api/retrieve', {
        query, package_id: datasetId, top_k: topK,
    });
}

// Chat sandbox (LLM call — may take several seconds)
// Calls /web-api/chat (session auth) instead of /api/chat (token auth)
async function runChat() {
    const message   = document.getElementById('chat-message').value.trim();
    const datasetId = parseInt(document.getElementById('chat-dataset').value);
    if (!message) { alert(i18n.enterMsg); return; }
    const btn = document.getElementById('chat-run-btn');
    btn.disabled = true;
    btn.textContent = i18n.generatingLlm;
    await runRequest('chat-response', 'POST', '/web-api/chat', {
        message, package_id: datasetId,
    });
    btn.disabled = false;
    btn.textContent = i18n.runLlm;
}
@endsection
