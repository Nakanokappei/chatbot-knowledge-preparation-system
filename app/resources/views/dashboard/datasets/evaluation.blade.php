{{-- Retrieval quality evaluation: simple test query interface for non-technical users. --}}
@extends('layouts.app')
@section('title', __('ui.evaluation') . ' — ' . $package->name)

@section('extra-styles')
    .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 20px; }
    .stat-card { background: #fff; border-radius: 12px; padding: 16px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
    .stat-value { font-size: 26px; font-weight: 700; }
    .stat-label { font-size: 11px; color: #5f6368; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 4px; }
    .similarity-bar { height: 6px; border-radius: 3px; background: #e5e5e7; display: inline-block; width: 80px; vertical-align: middle; }
    .similarity-fill { height: 100%; border-radius: 3px; display: block; }
    .sim-high { background: #34c759; }
    .sim-medium { background: #ff9500; }
    .sim-low { background: #ff3b30; }
    .query-input { width: 100%; padding: 10px 14px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 14px; font-family: inherit; }
    .query-input:focus { outline: none; border-color: #0071e3; }
    .query-list { display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px; }
    .query-row { display: flex; gap: 8px; align-items: center; }
    .query-row input { flex: 1; }
    .remove-btn { background: none; border: none; cursor: pointer; color: #ff3b30; font-size: 18px; padding: 0 6px; line-height: 1; }
    .result-card { background: #f5f5f7; border-radius: 10px; padding: 16px; margin-bottom: 12px; }
    .result-query { font-weight: 600; font-size: 15px; margin-bottom: 10px; color: #1d1d1f; }
    .result-item { display: flex; align-items: center; gap: 10px; padding: 6px 0; border-bottom: 1px solid #e5e5e7; }
    .result-item:last-child { border-bottom: none; }
    .result-rank { width: 24px; height: 24px; border-radius: 12px; background: #0071e3; color: #fff; font-size: 11px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .result-no-match { color: #86868b; font-size: 14px; padding: 8px 0; }
@endsection

@section('body')
<div class="page-content">
    <div class="page-container">

        <div style="margin-bottom: 4px; font-size: 13px; color: #5f6368;">
            <a href="{{ route('kp.show', $package) }}" style="color: #0071e3; text-decoration: none;">{{ $package->name }}</a> / {{ __('ui.evaluation') }}
        </div>
        <h1 style="font-size: 20px; font-weight: 600; margin-bottom: 4px;">{{ __('ui.eval_title') }}</h1>
        <p style="color: #5f6368; font-size: 13px; margin-bottom: 20px;">{{ __('ui.eval_description') }}</p>

        {{-- Summary stats --}}
        <div class="stats-grid">
            <div class="stat-card"><div class="stat-value" id="stat-queries">—</div><div class="stat-label">{{ __('ui.eval_queries_tested') }}</div></div>
            <div class="stat-card"><div class="stat-value" id="stat-avg-sim">—</div><div class="stat-label">{{ __('ui.eval_avg_relevance') }}</div></div>
            <div class="stat-card"><div class="stat-value" id="stat-avg-latency">—</div><div class="stat-label">{{ __('ui.eval_avg_response_time') }}</div></div>
        </div>

        {{-- Query input --}}
        <div class="card">
            <h2>{{ __('ui.eval_test_queries') }}</h2>
            <p style="color: #5f6368; font-size: 13px; margin-bottom: 12px;">{{ __('ui.eval_test_queries_hint') }}</p>

            <div class="query-list" id="query-list">
                <div class="query-row">
                    <input type="text" class="query-input test-query" placeholder="{{ __('ui.eval_query_placeholder') }}">
                    <button type="button" class="remove-btn" onclick="removeQuery(this)" title="{{ __('ui.delete') }}">&times;</button>
                </div>
            </div>

            <div style="display: flex; gap: 8px; align-items: center;">
                <button type="button" class="btn btn-outline" onclick="addQuery()" style="font-size: 13px;">+ {{ __('ui.eval_add_query') }}</button>
                <button type="button" class="btn btn-primary" onclick="runEvaluation()" id="run-btn" style="font-size: 13px;">{{ __('ui.eval_run') }}</button>
                <span id="run-status" style="font-size: 12px; color: #5f6368;"></span>
            </div>
        </div>

        {{-- Results --}}
        <div class="card" id="results-card" style="display: none;">
            <h2>{{ __('ui.results') }}</h2>
            <div id="results-container"></div>
        </div>

    </div>
</div>
@endsection

@section('scripts')
var packageId = {{ $package->id }};

function addQuery() {
    var list = document.getElementById('query-list');
    var row = document.createElement('div');
    row.className = 'query-row';
    row.innerHTML = '<input type="text" class="query-input test-query" placeholder="{{ __("ui.eval_query_placeholder") }}">'
        + '<button type="button" class="remove-btn" onclick="removeQuery(this)" title="{{ __("ui.delete") }}">&times;</button>';
    list.appendChild(row);
    row.querySelector('input').focus();
}

function removeQuery(btn) {
    var list = document.getElementById('query-list');
    if (list.children.length > 1) {
        btn.closest('.query-row').remove();
    }
}

// Shift+Enter adds a new row; plain Enter is suppressed to prevent accidental form submit
document.getElementById('query-list').addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && e.target.classList.contains('test-query')) {
        e.preventDefault();
        if (e.shiftKey) {
            addQuery();
        }
    }
});

async function runEvaluation() {
    var inputs = document.querySelectorAll('.test-query');
    var queries = [];
    inputs.forEach(function(input) {
        var q = input.value.trim();
        if (q) queries.push(q);
    });

    if (queries.length === 0) {
        alert('{{ __("ui.eval_enter_at_least_one") }}');
        return;
    }

    var btn = document.getElementById('run-btn');
    var status = document.getElementById('run-status');
    btn.disabled = true;

    var results = [];
    var totalLatency = 0;
    var topSimSum = 0;
    var matchCount = 0;

    for (var i = 0; i < queries.length; i++) {
        status.textContent = (i + 1) + ' / ' + queries.length + '...';
        try {
            var response = await fetch('/web-api/retrieve', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ query: queries[i], package_id: packageId, top_k: 5 }),
            });
            var data = await response.json();
            totalLatency += data.latency_ms || 0;
            if (data.results && data.results.length > 0) {
                topSimSum += data.results[0].similarity;
                matchCount++;
            }
            results.push({ query: queries[i], data: data });
        } catch (err) {
            results.push({ query: queries[i], error: err.message });
        }
    }

    // Update stats
    document.getElementById('stat-queries').textContent = queries.length;
    document.getElementById('stat-avg-sim').textContent = matchCount > 0
        ? (topSimSum / matchCount * 100).toFixed(0) + '%'
        : '—';
    document.getElementById('stat-avg-latency').textContent = queries.length > 0
        ? Math.round(totalLatency / queries.length) + 'ms'
        : '—';

    // Render results
    var container = document.getElementById('results-container');
    container.innerHTML = '';
    document.getElementById('results-card').style.display = 'block';

    results.forEach(function(r, i) {
        var card = document.createElement('div');
        card.className = 'result-card';

        var queryHtml = '<div class="result-query">' + (i + 1) + '. ' + escapeHtml(r.query);
        if (r.data) queryHtml += '<span style="font-weight:400;color:#86868b;font-size:12px;margin-left:8px;">' + (r.data.latency_ms || 0) + 'ms</span>';
        queryHtml += '</div>';

        var bodyHtml = '';
        if (r.error) {
            bodyHtml = '<div style="color:#ff3b30;font-size:13px;">Error: ' + escapeHtml(r.error) + '</div>';
        } else if (!r.data.results || r.data.results.length === 0) {
            bodyHtml = '<div class="result-no-match">{{ __("ui.eval_no_match") }}</div>';
        } else {
            r.data.results.forEach(function(m, j) {
                var simPct = (m.similarity * 100).toFixed(1);
                var cls = m.similarity >= 0.7 ? 'sim-high' : m.similarity >= 0.4 ? 'sim-medium' : 'sim-low';
                bodyHtml += '<div class="result-item">'
                    + '<span class="result-rank">' + (j + 1) + '</span>'
                    + '<span class="similarity-bar"><span class="similarity-fill ' + cls + '" style="width:' + simPct + '%;"></span></span>'
                    + '<span style="font-size:12px;color:#5f6368;min-width:40px;">' + simPct + '%</span>'
                    + '<span style="font-size:14px;">' + escapeHtml(m.topic) + '</span>'
                    + '<span style="font-size:12px;color:#86868b;">— ' + escapeHtml(m.intent) + '</span>'
                    + '</div>';
            });
        }

        card.innerHTML = queryHtml + bodyHtml;
        container.appendChild(card);
    });

    btn.disabled = false;
    status.textContent = '{{ __("ui.eval_complete") }}';
}

function escapeHtml(text) {
    var d = document.createElement('div');
    d.textContent = text;
    return d.innerHTML;
}
@endsection
