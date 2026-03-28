{{-- Retrieval quality evaluation: runs test queries against a dataset and shows hit rate, MRR, similarity, and latency. --}}
@extends('layouts.app')
@section('title', __('ui.evaluation') . ' — ' . $package->name)

@section('extra-styles')
    .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 20px; }
    .stat-card { background: #fff; border-radius: 12px; padding: 16px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
    .stat-value { font-size: 26px; font-weight: 700; }
    .stat-label { font-size: 11px; color: #5f6368; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 4px; }
    .eval-textarea { width: 100%; padding: 10px 12px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 13px; min-height: 120px; font-family: monospace; resize: vertical; }
    .eval-textarea:focus { outline: none; border-color: #0071e3; }
    .similarity-bar { height: 6px; border-radius: 3px; background: #e5e5e7; display: inline-block; width: 80px; vertical-align: middle; }
    .similarity-fill { height: 100%; border-radius: 3px; }
    .sim-high { background: #34c759; }
    .sim-medium { background: #ff9500; }
    .sim-low { background: #ff3b30; }
    .result-row { padding: 12px 16px; background: #f5f5f7; border-radius: 8px; margin-bottom: 10px; }
    .result-query { font-weight: 500; margin-bottom: 8px; font-size: 14px; }
    .result-matches { font-size: 13px; }
    .badge-hit { background: #d4edda; color: #155724; padding: 1px 8px; border-radius: 10px; font-size: 11px; }
    .badge-miss { background: #f8d7da; color: #721c24; padding: 1px 8px; border-radius: 10px; font-size: 11px; }
@endsection

@section('body')
<div class="page-content">
    <div class="page-container">

        <div style="margin-bottom: 4px; font-size: 13px; color: #5f6368;">
            <a href="{{ route('kp.show', $package) }}" style="color: #0071e3; text-decoration: none;">{{ $package->name }}</a> / {{ __('ui.evaluation') }}
        </div>
        <h1 style="font-size: 20px; font-weight: 600; margin-bottom: 20px;">{{ __('ui.retrieval_quality_evaluation') }}</h1>

        {{-- Summary stats (populated by JS after evaluation) --}}
        <div class="stats-grid">
            <div class="stat-card"><div class="stat-value" id="hit-rate">—</div><div class="stat-label">{{ __('ui.hit_rate') }}</div></div>
            <div class="stat-card"><div class="stat-value" id="mrr">—</div><div class="stat-label">{{ __('ui.mrr') }}</div></div>
            <div class="stat-card"><div class="stat-value" id="avg-similarity">—</div><div class="stat-label">{{ __('ui.avg_top1_similarity') }}</div></div>
            <div class="stat-card"><div class="stat-value" id="avg-latency">—</div><div class="stat-label">{{ __('ui.avg_latency_ms') }}</div></div>
        </div>

        <div class="card">
            <h2>{{ __('ui.test_queries') }}</h2>
            <p style="margin-bottom: 12px; color: #5f6368; font-size: 13px;">{{ __('ui.test_queries_hint') }}</p>
            <textarea class="eval-textarea" id="test-queries" placeholder='[
  {"query": "How do I reset my password?", "expected_ku_ids": [1]},
  {"query": "I want to cancel my subscription"},
  {"query": "Shipping is delayed"}
]'></textarea>
            <div style="margin-top: 12px;">
                <button class="btn btn-primary" onclick="runEvaluation()" id="run-btn">{{ __('ui.run_evaluation') }}</button>
            </div>
        </div>

        <div class="card" id="results-card" style="display: none;">
            <h2>{{ __('ui.results') }}</h2>
            <div id="results-container"></div>
        </div>

    </div>
</div>
@endsection

@section('scripts')
const datasetId = {{ $package->id }};
const csrfToken = '{{ csrf_token() }}';

async function runEvaluation() {
    const textarea = document.getElementById('test-queries');
    const btn = document.getElementById('run-btn');
    let queries;
    try {
        queries = JSON.parse(textarea.value);
        if (!Array.isArray(queries)) throw new Error('Must be an array');
    } catch (e) { alert('Invalid JSON: ' + e.message); return; }

    btn.disabled = true;
    btn.textContent = '{{ __('ui.running') }}';

    const results = [];
    let totalLatency = 0, hits = 0, reciprocalRankSum = 0, topSimilaritySum = 0, queriesWithExpected = 0;

    for (const testCase of queries) {
        try {
            const response = await fetch('/web-api/retrieve', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
                body: JSON.stringify({ query: testCase.query, dataset_id: datasetId, top_k: 5 }),
            });
            const data = await response.json();
            totalLatency += data.latency_ms || 0;

            let hit = null, rank = null;
            if (testCase.expected_ku_ids && testCase.expected_ku_ids.length > 0) {
                queriesWithExpected++;
                const returnedIds = data.results.map(r => r.knowledge_unit_id);
                hit = testCase.expected_ku_ids.some(id => returnedIds.includes(id));
                if (hit) {
                    hits++;
                    for (let i = 0; i < returnedIds.length; i++) {
                        if (testCase.expected_ku_ids.includes(returnedIds[i])) {
                            rank = i + 1; reciprocalRankSum += 1 / rank; break;
                        }
                    }
                }
            }
            if (data.results.length > 0) topSimilaritySum += data.results[0].similarity;
            results.push({ query: testCase.query, data, hit, rank, expected: testCase.expected_ku_ids });
        } catch (err) { results.push({ query: testCase.query, error: err.message }); }
    }

    const n = queries.length;
    if (queriesWithExpected > 0) {
        document.getElementById('hit-rate').textContent = ((hits / queriesWithExpected) * 100).toFixed(0) + '%';
        document.getElementById('mrr').textContent = (reciprocalRankSum / queriesWithExpected).toFixed(3);
    }
    document.getElementById('avg-similarity').textContent = n > 0 ? (topSimilaritySum / n).toFixed(3) : '—';
    document.getElementById('avg-latency').textContent = n > 0 ? Math.round(totalLatency / n) : '—';

    const container = document.getElementById('results-container');
    container.innerHTML = '';
    document.getElementById('results-card').style.display = 'block';

    results.forEach((r, i) => {
        const div = document.createElement('div');
        div.className = 'result-row';
        let hitBadge = r.hit === true ? ' <span class="badge-hit">HIT</span>' : r.hit === false ? ' <span class="badge-miss">MISS</span>' : '';
        let matchesHtml = r.error
            ? `<div style="color: #ff3b30;">Error: ${r.error}</div>`
            : (r.data?.results || []).map(m => {
                const simPct = (m.similarity * 100).toFixed(1);
                const cls = m.similarity >= 0.7 ? 'sim-high' : m.similarity >= 0.4 ? 'sim-medium' : 'sim-low';
                return `<div style="margin: 4px 0; display: flex; align-items: center; gap: 8px;">
                    <span style="min-width: 44px; font-size: 12px; color: #5f6368;">${simPct}%</span>
                    <span class="similarity-bar"><span class="similarity-fill ${cls}" style="width:${simPct}%;display:block;"></span></span>
                    <span style="font-size: 13px;">${escapeHtml(m.topic)} — ${escapeHtml(m.intent)}</span>
                </div>`;
            }).join('');
        div.innerHTML = `<div class="result-query">${i+1}. ${escapeHtml(r.query)}${hitBadge}${r.data ? `<span style="font-weight:400;color:#5f6368;font-size:12px;"> (${r.data.latency_ms}ms)</span>` : ''}</div><div class="result-matches">${matchesHtml}</div>`;
        container.appendChild(div);
    });

    btn.disabled = false;
    btn.textContent = '{{ __('ui.run_evaluation') }}';
}

function escapeHtml(text) { const d = document.createElement('div'); d.textContent = text; return d.innerHTML; }
@endsection
