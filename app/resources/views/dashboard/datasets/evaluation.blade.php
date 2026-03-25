{{-- Retrieval quality evaluation page: tests vector search accuracy against a dataset.
     Users provide JSON test queries with optional expected KU IDs, then the tool calculates
     hit rate, MRR, average similarity, and latency metrics. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Evaluation — {{ $dataset->name }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f5; padding: 20px; }
        .container { max-width: 1000px; margin: 0 auto; }
        .nav { margin-bottom: 20px; }
        .nav a { color: #2563eb; text-decoration: none; }
        h1 { margin-bottom: 20px; }
        .card { background: white; border-radius: 8px; padding: 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 20px; }
        .stat { background: white; border-radius: 8px; padding: 16px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .stat-value { font-size: 28px; font-weight: 700; color: #111; }
        .stat-label { font-size: 12px; color: #6b7280; text-transform: uppercase; margin-top: 4px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 10px 12px; background: #f9fafb; font-size: 13px; color: #6b7280; border-bottom: 1px solid #e5e7eb; }
        td { padding: 10px 12px; border-bottom: 1px solid #f3f4f6; font-size: 14px; }
        .btn { display: inline-block; padding: 8px 16px; border-radius: 6px; font-size: 14px; cursor: pointer; border: none; text-decoration: none; }
        .btn-primary { background: #2563eb; color: white; }
        .similarity-bar { height: 6px; border-radius: 3px; background: #e5e7eb; }
        .similarity-fill { height: 100%; border-radius: 3px; }
        .sim-high { background: #059669; }
        .sim-medium { background: #d97706; }
        .sim-low { background: #dc2626; }
        textarea { width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; min-height: 100px; font-family: monospace; }
        .result-row { margin-bottom: 12px; padding: 12px; background: #f9fafb; border-radius: 6px; }
        .result-query { font-weight: 600; margin-bottom: 8px; }
        .result-matches { font-size: 13px; }
        .badge-hit { background: #d1fae5; color: #065f46; padding: 2px 8px; border-radius: 10px; font-size: 11px; }
        .badge-miss { background: #fee2e2; color: #991b1b; padding: 2px 8px; border-radius: 10px; font-size: 11px; }
    </style>
</head>
<body>
<div class="container">
    <div class="nav">
        <a href="{{ route('kd.show', $dataset) }}">{{ $dataset->name }}</a> / <strong>Evaluation</strong>
    </div>

    <h1>Retrieval Quality Evaluation</h1>

    {{-- Summary stats cards: hit rate, MRR, avg similarity, avg latency (populated by JS) --}}
    <div class="stats-grid">
        <div class="stat">
            <div class="stat-value" id="hit-rate">—</div>
            <div class="stat-label">Hit Rate @5</div>
        </div>
        <div class="stat">
            <div class="stat-value" id="mrr">—</div>
            <div class="stat-label">MRR</div>
        </div>
        <div class="stat">
            <div class="stat-value" id="avg-similarity">—</div>
            <div class="stat-label">Avg Top-1 Similarity</div>
        </div>
        <div class="stat">
            <div class="stat-value" id="avg-latency">—</div>
            <div class="stat-label">Avg Latency (ms)</div>
        </div>
    </div>

    {{-- Test queries input: JSON textarea for entering evaluation queries --}}
    <div class="card">
        <h2 style="margin-bottom: 12px;">Test Queries</h2>
        <p style="margin-bottom: 12px; color: #6b7280; font-size: 13px;">
            Enter test queries in JSON format. Each query can optionally specify expected KU IDs for hit rate calculation.
        </p>
        <textarea id="test-queries" placeholder='[
  {"query": "How do I reset my password?", "expected_ku_ids": [1]},
  {"query": "I want to cancel my subscription"},
  {"query": "Shipping is delayed"}
]'></textarea>
        <br><br>
        <button class="btn btn-primary" onclick="runEvaluation()" id="run-btn">Run Evaluation</button>
    </div>

    {{-- Results container: populated by JS after running evaluation --}}
    <div class="card" id="results-card" style="display: none;">
        <h2 style="margin-bottom: 12px;">Results</h2>
        <div id="results-container"></div>
    </div>
</div>

<script>
const datasetId = {{ $dataset->id }};
const csrfToken = '{{ csrf_token() }}';

// Run evaluation: iterate through test queries, call retrieve API, compute metrics
async function runEvaluation() {
    const textarea = document.getElementById('test-queries');
    const btn = document.getElementById('run-btn');

    let queries;
    try {
        queries = JSON.parse(textarea.value);
        if (!Array.isArray(queries)) throw new Error('Must be an array');
    } catch (e) {
        alert('Invalid JSON: ' + e.message);
        return;
    }

    btn.disabled = true;
    btn.textContent = 'Running...';

    const results = [];
    let totalLatency = 0;
    let hits = 0;
    let reciprocalRankSum = 0;
    let topSimilaritySum = 0;
    let queriesWithExpected = 0;

    for (const testCase of queries) {
        try {
            const response = await fetch('/web-api/retrieve', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    query: testCase.query,
                    dataset_id: datasetId,
                    top_k: 5,
                }),
            });

            const data = await response.json();
            totalLatency += data.latency_ms || 0;

            // Calculate metrics if expected KU IDs are provided
            let hit = null;
            let rank = null;
            if (testCase.expected_ku_ids && testCase.expected_ku_ids.length > 0) {
                queriesWithExpected++;
                const returnedIds = data.results.map(r => r.knowledge_unit_id);
                hit = testCase.expected_ku_ids.some(id => returnedIds.includes(id));
                if (hit) {
                    hits++;
                    // Find the rank of the first matching KU
                    for (let i = 0; i < returnedIds.length; i++) {
                        if (testCase.expected_ku_ids.includes(returnedIds[i])) {
                            rank = i + 1;
                            reciprocalRankSum += 1 / rank;
                            break;
                        }
                    }
                }
            }

            if (data.results.length > 0) {
                topSimilaritySum += data.results[0].similarity;
            }

            results.push({ query: testCase.query, data, hit, rank, expected: testCase.expected_ku_ids });
        } catch (err) {
            results.push({ query: testCase.query, error: err.message });
        }
    }

    // Update stats
    const n = queries.length;
    if (queriesWithExpected > 0) {
        document.getElementById('hit-rate').textContent = ((hits / queriesWithExpected) * 100).toFixed(0) + '%';
        document.getElementById('mrr').textContent = (reciprocalRankSum / queriesWithExpected).toFixed(3);
    }
    document.getElementById('avg-similarity').textContent = n > 0 ? (topSimilaritySum / n).toFixed(3) : '—';
    document.getElementById('avg-latency').textContent = n > 0 ? Math.round(totalLatency / n) : '—';

    // Render results
    const container = document.getElementById('results-container');
    container.innerHTML = '';
    document.getElementById('results-card').style.display = 'block';

    results.forEach((r, i) => {
        const div = document.createElement('div');
        div.className = 'result-row';

        let hitBadge = '';
        if (r.hit === true) hitBadge = ' <span class="badge-hit">HIT</span>';
        else if (r.hit === false) hitBadge = ' <span class="badge-miss">MISS</span>';

        let matchesHtml = '';
        if (r.data && r.data.results) {
            matchesHtml = r.data.results.map(m => {
                const simPct = (m.similarity * 100).toFixed(1);
                const simClass = m.similarity >= 0.7 ? 'sim-high' : m.similarity >= 0.4 ? 'sim-medium' : 'sim-low';
                return `<div style="margin: 4px 0; display: flex; align-items: center; gap: 8px;">
                    <span style="min-width: 50px; font-size: 12px;">${simPct}%</span>
                    <div class="similarity-bar" style="width: 100px;"><div class="similarity-fill ${simClass}" style="width: ${simPct}%"></div></div>
                    <span>${m.topic} — ${m.intent}</span>
                </div>`;
            }).join('');
        }

        if (r.error) {
            matchesHtml = `<div style="color: #dc2626;">Error: ${r.error}</div>`;
        }

        div.innerHTML = `
            <div class="result-query">${i + 1}. ${escapeHtml(r.query)}${hitBadge}
                ${r.data ? `<span style="font-weight: normal; color: #6b7280; font-size: 12px;"> (${r.data.latency_ms}ms)</span>` : ''}
            </div>
            <div class="result-matches">${matchesHtml}</div>
        `;
        container.appendChild(div);
    });

    btn.disabled = false;
    btn.textContent = 'Run Evaluation';
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>
</body>
</html>
