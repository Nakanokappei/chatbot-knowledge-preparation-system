{{--
    Parameter-search report — full HTML page modelled on
    `voice-classifier/data/output/<run>/parameter_search.html`.

    Sections (top to bottom):
        1. Page header  : title, dataset, run timestamp, print button
        2. Conditions   : sample/total rows, configs tested, target, filters
        3. SVG chart    : silhouette bars + cluster-count line (winner highlit)
        4. Advisory     : Bedrock-generated executive summary (Markdown → HTML)
        5. Selected     : winning algorithm + params + "why this one"
        6. Accepted     : ranked table with target-aware scores (faq/chatbot/insight)
        7. Rejected     : noise-filter rejects + degenerate trials
        8. By method    : per-algorithm digest tables

    Print: an `@media print` rule strips the toolbar and tweaks colours so
    the user can save the report as a PDF directly from their browser.

    `payload === null` means the sweep has not been run yet for this
    embedding; we render a friendly empty state instead of the report body.
--}}
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('ui.parameter_search_report') ?? 'パラメータ探索レポート' }} — {{ $embedding->dataset?->name ?? $embedding->name }}</title>
    <style>
        :root {
            --fg: #1f2328;
            --muted: #57606a;
            --accent: #0969da;
            --warn: #bf8700;
            --bad: #cf222e;
            --bg: #ffffff;
            --bg-alt: #f6f8fa;
            --border: #d0d7de;
        }
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Hiragino Sans", "Noto Sans CJK JP", "Segoe UI", sans-serif;
            color: var(--fg);
            background: var(--bg);
            max-width: 980px;
            margin: 1.5rem auto;
            padding: 0 1.25rem 4rem;
            line-height: 1.6;
        }
        h1, h2, h3 { border-bottom: 1px solid var(--border); padding-bottom: .3em; }
        h1 { font-size: 1.7rem; margin-top: 0; }
        h2 { font-size: 1.35rem; margin-top: 2rem; }
        h3 { font-size: 1.1rem; border-bottom: none; color: var(--accent); margin-top: 1.3rem; }
        h4 { font-size: 1rem; color: var(--fg); margin: 1rem 0 .3rem; }
        table {
            border-collapse: collapse;
            width: 100%;
            margin: .8rem 0;
            font-size: .92rem;
        }
        th, td {
            border: 1px solid var(--border);
            padding: .45rem .7rem;
            text-align: left;
            vertical-align: top;
        }
        th { background: var(--bg-alt); font-weight: 600; }
        tr:nth-child(even) td { background: var(--bg-alt); }
        td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; }
        td.center, th.center { text-align: center; }
        code {
            background: var(--bg-alt);
            padding: .1em .35em;
            border-radius: 3px;
            font-size: .9em;
            font-family: ui-monospace, "SFMono-Regular", Menlo, monospace;
        }
        ol li, ul li { margin: .2rem 0; }
        hr { border: none; border-top: 1px solid var(--border); margin: 1.5rem 0; }
        .footer { color: var(--muted); font-size: .85rem; margin-top: 3rem; text-align: right; }
        .meta-line { color: var(--muted); font-size: .85rem; margin: .25rem 0 1.25rem; }
        .toolbar {
            position: sticky;
            top: 0;
            background: var(--bg);
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: .5rem 0 .75rem;
            margin-bottom: 1rem;
            border-bottom: 1px solid var(--border);
            z-index: 10;
        }
        .toolbar a, .toolbar button {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border: 1px solid var(--border);
            background: #fff;
            color: var(--fg);
            border-radius: 6px;
            font-size: 13px;
            cursor: pointer;
            text-decoration: none;
        }
        .toolbar .primary {
            background: var(--accent);
            color: #fff;
            border-color: var(--accent);
        }
        .badge { display: inline-block; padding: 0 .45em; border-radius: 4px; font-size: .8rem; }
        .badge-selected { background: #f0d36b; color: #553c00; }
        .advisory {
            background: #f0f6fe;
            border: 1px solid #b6d5ff;
            border-left: 4px solid var(--accent);
            padding: 1rem 1.3rem;
            margin: 1.2rem 0 2rem;
            border-radius: 4px;
        }
        .advisory h2 {
            margin-top: 0;
            border-bottom: 1px solid #b6d5ff;
            color: var(--accent);
        }
        .advisory h3 {
            margin-top: 1.1rem;
            color: var(--fg);
            font-size: 1.02rem;
            border-bottom: none;
        }
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--muted);
            background: var(--bg-alt);
            border: 1px dashed var(--border);
            border-radius: 8px;
        }
        /* Glossary — supporting reference material, visually muted so it
           reads as background rather than primary findings. */
        .glossary-heading { color: var(--muted); border-bottom-color: #e5e7ec; font-size: 1.1rem; }
        table.glossary { font-size: .85rem; color: var(--muted); }
        table.glossary th { color: var(--muted); font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; }
        table.glossary td { line-height: 1.55; }
        table.glossary td strong { color: var(--fg); }
        ul.glossary-params { margin: .35rem 0 0; padding-left: 1.1rem; }
        ul.glossary-params li { margin: .15rem 0; }
        ul.glossary-params code { background: #fafbfc; }
        @media print {
            body { max-width: none; margin: 0; padding: 0 1cm; font-size: 11pt; }
            .toolbar, .no-print { display: none !important; }
            h1 { font-size: 18pt; }
            h2 { font-size: 14pt; page-break-after: avoid; }
            h3 { font-size: 12pt; page-break-after: avoid; }
            table { page-break-inside: avoid; }
            .advisory { background: #f8fbff; border-color: #b6d5ff; }
        }
    </style>
</head>
<body>

<div class="toolbar no-print">
    <a href="{{ route('workspace.embedding', ['embeddingId' => $embedding->id]) }}?compare=1">← {{ __('ui.back_to_workspace') ?? 'ワークスペースへ戻る' }}</a>
    @if($payload)
        <button type="button" class="primary" onclick="window.print()">🖨 {{ __('ui.print_or_save_pdf') ?? 'PDFとして保存・印刷' }}</button>
    @endif
</div>

<h1>{{ __('ui.parameter_search_report') ?? 'パラメータ探索レポート' }}</h1>
<div class="meta-line">
    {{ $embedding->dataset?->name ?? $embedding->name }}
    @if($job)
        ・{{ __('ui.run_at') ?? '実行日時' }}: <time datetime="{{ $job->created_at->toIso8601String() }}">{{ $job->created_at->format('Y/m/d H:i') }}</time>
    @endif
</div>

@if(!$payload)
    {{-- Empty state: parameter search has never been run for this embedding --}}
    <div class="empty-state">
        <p style="font-size: 1.1rem; margin: 0 0 .5rem;">{{ __('ui.no_parameter_search_results') ?? 'パラメータ探索の結果がありません' }}</p>
        <p style="margin: 0;">{{ __('ui.run_parameter_search_first') ?? 'まずワークスペースで「パラメータ探索」を実行してください。' }}</p>
    </div>
@else
    {{-- Section 1: Search Conditions --}}
    <h2>{{ __('ui.search_conditions') ?? '探索条件' }}</h2>
    <table>
        <tbody>
            <tr><th style="width: 30%;">{{ __('ui.dataset') ?? 'データセット' }}</th><td>{{ $embedding->dataset?->name ?? $embedding->name }}</td></tr>
            @if($embedding->embedding_model)
                <tr><th>{{ __('ui.embedding_model_used') ?? '埋め込みモデル' }}</th><td><code>{{ $embedding->embedding_model }}</code></td></tr>
            @endif
            <tr><th>{{ __('ui.total_rows') ?? '総行数' }}</th><td class="num">{{ number_format($totalRows) }}</td></tr>
            <tr><th>{{ __('ui.sweep_sample_size') ?? 'スイープのサンプルサイズ' }}</th><td class="num">{{ number_format($sampleSize) }}</td></tr>
            <tr><th>{{ __('ui.configs_tested') ?? '試行した構成数' }}</th><td class="num">{{ $configsTested }}</td></tr>
            <tr><th>{{ __('ui.optimisation_target') ?? '最適化ターゲット' }}</th><td><code>{{ $target }}</code> — {{ \App\Support\ParameterSearchScoring::targetLabel($target) }}</td></tr>
            <tr><th>{{ __('ui.noise_filter') ?? 'ノイズ比フィルタ' }}</th><td>{{ __('ui.drop_noise_above', ['pct' => intval($noiseThreshold * 100)]) ?? "ノイズ {$noiseThreshold} 超の候補は採用候補から除外" }}</td></tr>
        </tbody>
    </table>

    {{-- Section 2: Dual-axis SVG chart (silhouette bars + cluster count line) --}}
    <h2>{{ __('ui.silhouette_chart') ?? 'シルエットスコアチャート' }}</h2>
    @if($silhouetteRanked->isEmpty())
        <p style="color: var(--muted);">{{ __('ui.no_scoreable_trials') ?? 'スコア可能な試行がありません。' }}</p>
    @else
        @php
            // Inline-compute the chart constants in PHP. Mirrors
            // voice-classifier's `_build_parameter_search_chart_svg`
            // so the visual output matches what the user is used to.
            $width = 900;
            $height = 420;
            $marginL = 70; $marginR = 70; $marginT = 36; $marginB = 90;
            $chartW = $width - $marginL - $marginR;
            $chartH = $height - $marginT - $marginB;
            $valid = $silhouetteRanked;
            $n = $valid->count();
            $silMax = $valid->max('silhouette_score');
            $silMin = min(0.0, $valid->min('silhouette_score'));
            $silTop = max($silMax * 1.1, 0.05);
            $silBottom = $silMin;
            $clusterMax = max($valid->max('n_clusters'), 1);
            $clusterTop = max($clusterMax * 1.1, 1);
            $barSlot = $chartW / max($n, 1);
            $barW = $barSlot * 0.7;
            $winnerKey = $winner ? json_encode([$winner['method'], $winner['params']], JSON_UNESCAPED_UNICODE) : null;
            $methodShort = ['kmeans' => 'KM', 'hdbscan' => 'HD', 'leiden' => 'LD'];
            $cBar = '#4e79a7'; $cWin = '#f28e2c'; $cLine = '#e15759';
            $cGrid = '#d0d7de'; $cText = '#1f2328'; $cMuted = '#57606a';
            $ySil = function ($score) use ($silBottom, $silTop, $marginT, $chartH) {
                if ($silTop == $silBottom) return $marginT + $chartH;
                $ratio = ($score - $silBottom) / ($silTop - $silBottom);
                return $marginT + $chartH * (1 - $ratio);
            };
            $yClu = function ($cnt) use ($clusterTop, $marginT, $chartH) {
                if ($clusterTop == 0) return $marginT + $chartH;
                return $marginT + $chartH * (1 - $cnt / $clusterTop);
            };
        @endphp
        <svg width="100%" viewBox="0 0 {{ $width }} {{ $height }}" xmlns="http://www.w3.org/2000/svg" style="max-width:{{ $width }}px;display:block;margin:0 auto;font-family:inherit;">
            {{-- Grid + axis labels (left = silhouette, right = cluster count) --}}
            @for($i = 0; $i <= 4; $i++)
                @php
                    $frac = $i / 4;
                    $y = $marginT + $chartH * (1 - $frac);
                    $silVal = $silBottom + ($silTop - $silBottom) * $frac;
                    $cluVal = (int) round($clusterTop * $frac);
                @endphp
                <line x1="{{ $marginL }}" y1="{{ number_format($y, 1, '.', '') }}" x2="{{ $width - $marginR }}" y2="{{ number_format($y, 1, '.', '') }}" stroke="{{ $cGrid }}" stroke-width="1" stroke-dasharray="2,2" />
                <text x="{{ $marginL - 8 }}" y="{{ number_format($y + 4, 1, '.', '') }}" text-anchor="end" font-size="11" fill="{{ $cMuted }}">{{ number_format($silVal, 2) }}</text>
                <text x="{{ $width - $marginR + 8 }}" y="{{ number_format($y + 4, 1, '.', '') }}" text-anchor="start" font-size="11" fill="{{ $cMuted }}">{{ $cluVal }}</text>
            @endfor

            @if($silBottom < 0)
                @php $zeroY = $ySil(0.0); @endphp
                <line x1="{{ $marginL }}" y1="{{ number_format($zeroY, 1, '.', '') }}" x2="{{ $width - $marginR }}" y2="{{ number_format($zeroY, 1, '.', '') }}" stroke="{{ $cMuted }}" stroke-width="1" />
            @endif

            {{-- Axes --}}
            <line x1="{{ $marginL }}" y1="{{ $marginT }}" x2="{{ $marginL }}" y2="{{ $marginT + $chartH }}" stroke="{{ $cText }}" stroke-width="1.5" />
            <line x1="{{ $width - $marginR }}" y1="{{ $marginT }}" x2="{{ $width - $marginR }}" y2="{{ $marginT + $chartH }}" stroke="{{ $cText }}" stroke-width="1.5" />
            <line x1="{{ $marginL }}" y1="{{ $marginT + $chartH }}" x2="{{ $width - $marginR }}" y2="{{ $marginT + $chartH }}" stroke="{{ $cText }}" stroke-width="1.5" />

            {{-- Axis titles (rotated) --}}
            <text x="{{ $marginL - 50 }}" y="{{ number_format($marginT + $chartH / 2, 1, '.', '') }}" font-size="12" fill="{{ $cBar }}" transform="rotate(-90 {{ $marginL - 50 }} {{ number_format($marginT + $chartH / 2, 1, '.', '') }})" text-anchor="middle">Silhouette score</text>
            <text x="{{ $width - $marginR + 50 }}" y="{{ number_format($marginT + $chartH / 2, 1, '.', '') }}" font-size="12" fill="{{ $cLine }}" transform="rotate(90 {{ $width - $marginR + 50 }} {{ number_format($marginT + $chartH / 2, 1, '.', '') }})" text-anchor="middle">Cluster count</text>

            {{-- Bars (silhouette) --}}
            @php $zeroY = $ySil(0.0); @endphp
            @foreach($valid as $idx => $trial)
                @php
                    $rank = $idx + 1;
                    $xCenter = $marginL + $barSlot * ($rank - 0.5);
                    $xLeft = $xCenter - $barW / 2;
                    $yTop = $ySil($trial['silhouette_score']);
                    $barY = min($yTop, $zeroY);
                    $barH = abs($yTop - $zeroY);
                    $tKey = json_encode([$trial['method'], $trial['params']], JSON_UNESCAPED_UNICODE);
                    $isWin = $tKey === $winnerKey;
                    $fill = $isWin ? $cWin : $cBar;
                    $paramStr = collect($trial['params'])->map(fn($v, $k) => "$k=$v")->implode(', ');
                @endphp
                <rect x="{{ number_format($xLeft, 1, '.', '') }}" y="{{ number_format($barY, 1, '.', '') }}" width="{{ number_format($barW, 1, '.', '') }}" height="{{ number_format($barH, 1, '.', '') }}" fill="{{ $fill }}">
                    <title>rank {{ $rank }}: {{ $trial['method'] }} {{ $paramStr }} (score={{ number_format($trial['silhouette_score'], 4) }}, clusters={{ $trial['n_clusters'] }}, noise={{ $trial['n_noise'] }})</title>
                </rect>
                <text x="{{ number_format($xCenter, 1, '.', '') }}" y="{{ number_format(($barH > 0 ? $barY : $zeroY) - 4, 1, '.', '') }}" text-anchor="middle" font-size="10" fill="{{ $cText }}">{{ $rank }}</text>
            @endforeach

            {{-- Cluster-count polyline (line + circles) --}}
            @php
                $points = [];
                foreach ($valid as $idx => $trial) {
                    $rank = $idx + 1;
                    $x = $marginL + $barSlot * ($rank - 0.5);
                    $y = $yClu($trial['n_clusters']);
                    $points[] = number_format($x, 1, '.', '') . ',' . number_format($y, 1, '.', '');
                }
            @endphp
            @if(count($points) > 1)
                <path d="M {{ implode(' L ', $points) }}" fill="none" stroke="{{ $cLine }}" stroke-width="2" />
            @endif
            @foreach($valid as $idx => $trial)
                @php $rank = $idx + 1; $x = $marginL + $barSlot * ($rank - 0.5); $y = $yClu($trial['n_clusters']); @endphp
                <circle cx="{{ number_format($x, 1, '.', '') }}" cy="{{ number_format($y, 1, '.', '') }}" r="3" fill="{{ $cLine }}" />
            @endforeach

            {{-- X-axis tick labels (rotated 45°) --}}
            @foreach($valid as $idx => $trial)
                @php
                    $rank = $idx + 1;
                    $x = $marginL + $barSlot * ($rank - 0.5);
                    $algo = $methodShort[$trial['method']] ?? Str::limit($trial['method'], 2, '');
                    $params = $trial['params'];
                    if (isset($params['n_clusters'])) { $compact = 'k=' . $params['n_clusters']; }
                    elseif (isset($params['min_cluster_size'])) { $compact = 'mcs=' . $params['min_cluster_size']; }
                    elseif (isset($params['resolution'])) { $compact = 'res=' . number_format((float)$params['resolution'], 1); }
                    else { $first = array_key_first($params); $compact = $first . '=' . ($params[$first] ?? ''); }
                @endphp
                <text x="{{ number_format($x, 1, '.', '') }}" y="{{ number_format($marginT + $chartH + 12, 1, '.', '') }}" text-anchor="end" font-size="9" fill="{{ $cMuted }}" transform="rotate(-45 {{ number_format($x, 1, '.', '') }} {{ number_format($marginT + $chartH + 12, 1, '.', '') }})">{{ $algo }}:{{ $compact }}</text>
            @endforeach

            {{-- Legend --}}
            @php $legendY = $height - 20; @endphp
            <rect x="{{ $marginL + 10 }}" y="{{ $legendY }}" width="14" height="10" fill="{{ $cBar }}" />
            <text x="{{ $marginL + 30 }}" y="{{ $legendY + 9 }}" font-size="11" fill="{{ $cText }}">Silhouette (バー / 左軸)</text>
            <rect x="{{ $marginL + 220 }}" y="{{ $legendY }}" width="14" height="10" fill="{{ $cWin }}" />
            <text x="{{ $marginL + 240 }}" y="{{ $legendY + 9 }}" font-size="11" fill="{{ $cText }}">採用候補</text>
            <rect x="{{ $marginL + 310 }}" y="{{ $legendY }}" width="14" height="10" fill="{{ $cLine }}" />
            <text x="{{ $marginL + 330 }}" y="{{ $legendY + 9 }}" font-size="11" fill="{{ $cText }}">クラスタ数 (折れ線 / 右軸)</text>
        </svg>
    @endif

    {{-- Section 3: AI advisory note (Markdown rendered to HTML) --}}
    @if(trim($advisoryMd) !== '')
        <section class="advisory">
            {!! \App\Support\SimpleMarkdown::toHtml($advisoryMd) !!}
            @if(!empty($advisorMeta))
                <p style="color: var(--muted); font-size: .8rem; margin: 1rem 0 0;">
                    AI生成: {{ $advisorMeta['model_id'] ?? '' }}
                    @if(!empty($advisorMeta['input_tokens']) || !empty($advisorMeta['output_tokens']))
                        ・入力 {{ number_format($advisorMeta['input_tokens'] ?? 0) }} tokens
                        ・出力 {{ number_format($advisorMeta['output_tokens'] ?? 0) }} tokens
                    @endif
                </p>
            @endif
        </section>
    @endif

    {{-- Section 4: Selected configuration --}}
    <h2>{{ __('ui.selected_configuration') ?? '採用構成' }}</h2>
    @if(!$winner)
        <p>{{ __('ui.no_accepted_candidate') ?? 'ノイズ比フィルタを通過した候補がありません。' }}</p>
    @else
        @php
            $winnerParamStr = collect($winner['params'])->map(fn($v, $k) => "$k=$v")->implode(', ');
            $silhouette = (float) $winner['silhouette_score'];
            if ($silhouette >= 0.40)      { $silLabel = '良好'; }
            elseif ($silhouette >= 0.20)  { $silLabel = '許容範囲（注意付き）'; }
            else                          { $silLabel = '弱い'; }
        @endphp
        <ul>
            <li><strong>{{ __('ui.algorithm') ?? '手法' }}</strong>: {{ $winner['method'] }}</li>
            <li><strong>{{ __('ui.parameters') ?? 'パラメータ' }}</strong>: <code>{{ $winnerParamStr }}</code></li>
            <li><strong>{{ __('ui.silhouette_score') ?? 'シルエットスコア' }}</strong>: {{ number_format($silhouette, 4) }}（{{ $silLabel }}）</li>
            <li><strong>{{ __('ui.cluster_count') ?? 'クラスタ数' }}</strong>: {{ $winner['n_clusters'] }}</li>
            <li><strong>{{ __('ui.noise_count') ?? 'ノイズ数' }}</strong>: {{ $winner['n_noise'] }} ({{ number_format($winner['noise_ratio'] * 100, 1) }}%)</li>
            <li><strong>{{ __('ui.max_cluster_share') ?? '最大クラスタ占有率' }}</strong>: {{ number_format($winner['max_cluster_share'] * 100, 1) }}%</li>
        </ul>
        <p><strong>{{ __('ui.why_this_one') ?? '採用理由' }}</strong></p>
        <ol>
            <li>{{ __('ui.why_top_score') ?? 'ノイズ比フィルタを通過した候補のうち、対象ターゲットのスコアが最も高い。' }}</li>
            <li>{{ __('ui.why_full_data') ?? 'サンプル上の高スコアは、本構成を全データで再走させても再現される傾向がある。' }}</li>
        </ol>

        @if(!empty($topClusters))
            <h3>{{ __('ui.top_groups_by_volume') ?? '規模順 主要グループ' }}</h3>
            <table>
                <thead>
                    <tr>
                        <th class="num">#</th>
                        <th>{{ __('ui.group_name') ?? 'グループ名' }}</th>
                        <th class="num">{{ __('ui.estimated_total_rows') ?? '推定全件数' }}</th>
                        <th class="num">{{ __('ui.share_of_total') ?? '全体シェア' }}</th>
                    </tr>
                </thead>
                <tbody>
                    @php $sortedTop = collect($topClusters)->sortByDesc('estimated_total_rows')->values(); @endphp
                    @foreach($sortedTop as $i => $cluster)
                        <tr>
                            <td class="num">{{ $i + 1 }}</td>
                            <td>{{ $cluster['name'] ?? ('#' . $cluster['cluster_id']) }}</td>
                            <td class="num">{{ number_format($cluster['estimated_total_rows'] ?? 0) }}</td>
                            <td class="num">{{ number_format(($cluster['estimated_total_rows'] ?? 0) / max($totalRows, 1) * 100, 1) }}%</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endif

    @php
        // Per-trial interpretation helpers: collapse the row's numeric
        // signals into the same plain-language verdicts the workspace
        // header has used for a while (silhouette quality labels, share
        // budget, granularity vs the active target). Keeping them inside
        // the Blade template avoids spreading more interpretive logic
        // across PHP — these are display-only labels, not selection-
        // affecting decisions.
        $silLabel = function (?float $sil): array {
            if ($sil === null) return ['—', '#5f6368'];
            if ($sil >= 0.50) return ['優良', '#155724'];
            if ($sil >= 0.30) return ['良好', '#2e7d32'];
            if ($sil >= 0.10) return ['標準的（テキスト）', '#1565c0'];
            if ($sil >= 0.00) return ['弱い', '#5f6368'];
            return ['要改善', '#cf222e'];
        };
        $granularityNote = function (int $nClusters, string $tgt): string {
            $profile = \App\Support\ParameterSearchScoring::PROFILES[$tgt] ?? null;
            $range = $profile['cluster_range'] ?? null;
            if ($range === null) return '制約なし';
            [$low, $high] = $range;
            if ($nClusters >= $low && $nClusters <= $high) return "{$tgt} 範囲内 ({$low}-{$high})";
            if ($nClusters < $low) return "粗すぎ ({$tgt} は {$low} 以上)";
            return "細かすぎ ({$tgt} は {$high} 以下)";
        };
        $shareNote = function (float $maxShare, string $tgt): string {
            $profile = \App\Support\ParameterSearchScoring::PROFILES[$tgt] ?? null;
            $budget = $profile['max_cluster_share'] ?? 1.0;
            if ($maxShare <= $budget) return '健全';
            return '1グループに偏り';
        };
    @endphp

    {{-- Section 5: Accepted candidates ranked by active target --}}
    <h2>{{ __('ui.accepted_candidates') ?? '採用候補（ランキング）' }}</h2>
    <p>
        {{ __('ui.ranking_uses_target', ['target' => $target]) ?? "ランキングは選択中ターゲット（{$target}）に基づきます。右側の3列は同じ候補を別のターゲットで評価した場合のスコアで、用途による選択肢の差を比較できます。" }}
        {{ __('ui.chart_no_explainer') ?? '「Chart #」列はチャート上のバー位置に対応します（チャートはシルエットスコア順、表はターゲットスコア順なので並びは異なります）。' }}
    </p>
    @if($accepted->isEmpty())
        <p>{{ __('ui.no_accepted_candidate') ?? 'ノイズ比フィルタを通過した候補がありません。' }}</p>
    @else
        <table>
            <thead>
                <tr>
                    <th class="num">Rank</th>
                    <th class="num">Chart #</th>
                    <th>{{ __('ui.method') ?? '手法' }}</th>
                    <th>{{ __('ui.parameters') ?? 'パラメータ' }}</th>
                    <th class="num">{{ __('ui.cluster_count') ?? 'クラスタ数' }}</th>
                    <th>{{ __('ui.noise') ?? 'ノイズ' }} ({{ __('ui.ratio') ?? '比率' }})</th>
                    <th class="num">{{ __('ui.max_share') ?? '最大占有' }}</th>
                    <th class="num">{{ __('ui.silhouette') ?? 'シルエット' }}</th>
                    <th>{{ __('ui.quality') ?? '品質' }}</th>
                    <th class="num">faq</th>
                    <th class="num">chatbot</th>
                    <th class="num">insight</th>
                    <th class="center">{{ __('ui.status') ?? '状態' }}</th>
                    <th>{{ __('ui.interpretation') ?? '所見' }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($accepted as $rank => $trial)
                    @php
                        $tKey = json_encode([$trial['method'], $trial['params']], JSON_UNESCAPED_UNICODE);
                        $chartNo = $chartRanks[$tKey] ?? null;
                        $isWin = $tKey === $winnerKey;
                        $paramStr = collect($trial['params'])->map(fn($v, $k) => "$k=$v")->implode(', ');
                        [$qLabel, $qColor] = $silLabel((float) $trial['silhouette_score']);
                        $notes = [
                            $granularityNote($trial['n_clusters'], $target),
                            $shareNote($trial['max_cluster_share'], $target),
                        ];
                        if ($trial['noise_ratio'] >= 0.30) {
                            $notes[] = 'ノイズ多め';
                        }
                    @endphp
                    <tr>
                        <td class="num">{{ $rank + 1 }}</td>
                        <td class="num">{{ $chartNo ?? '—' }}</td>
                        <td>{{ $trial['method'] }}</td>
                        <td><code>{{ $paramStr }}</code></td>
                        <td class="num">{{ $trial['n_clusters'] }}</td>
                        <td>{{ $trial['n_noise'] }} ({{ number_format($trial['noise_ratio'] * 100, 1) }}%)</td>
                        <td class="num">{{ number_format($trial['max_cluster_share'] * 100, 1) }}%</td>
                        <td class="num" style="color: {{ $qColor }}; font-weight: 600;">{{ number_format($trial['silhouette_score'], 4) }}</td>
                        <td style="color: {{ $qColor }};">{{ $qLabel }}</td>
                        <td class="num">{{ number_format($trial['score_faq'], 3) }}</td>
                        <td class="num">{{ number_format($trial['score_chatbot'], 3) }}</td>
                        <td class="num">{{ number_format($trial['score_insight'], 3) }}</td>
                        <td class="center">@if($isWin)<span class="badge badge-selected">✓ 採用</span>@else—@endif</td>
                        <td style="font-size: .85rem; color: var(--muted);">{{ implode('・', $notes) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- Section 6: Rejected — noise filter --}}
    @if($rejectedNoise->isNotEmpty())
        <h2>{{ __('ui.rejected_noise_section') ?? '却下 — ノイズ比フィルタ' }}</h2>
        <p>{{ __('ui.rejected_noise_explainer', ['pct' => intval($noiseThreshold * 100)]) ?? "サンプル上のノイズ比が {$noiseThreshold} を超えた候補は除外しています（少数の小さなクラスタが大量のノイズに囲まれている状態は実運用価値が低いため）。" }}</p>
        <table>
            <thead>
                <tr>
                    <th class="num">Chart #</th>
                    <th>{{ __('ui.method') ?? '手法' }}</th>
                    <th>{{ __('ui.parameters') ?? 'パラメータ' }}</th>
                    <th class="num">{{ __('ui.cluster_count') ?? 'クラスタ数' }}</th>
                    <th>{{ __('ui.noise') ?? 'ノイズ' }} ({{ __('ui.ratio') ?? '比率' }})</th>
                    <th class="num">{{ __('ui.silhouette') ?? 'シルエット' }}</th>
                    <th>{{ __('ui.quality') ?? '品質' }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rejectedNoise as $trial)
                    @php
                        $tKey = json_encode([$trial['method'], $trial['params']], JSON_UNESCAPED_UNICODE);
                        $chartNo = $chartRanks[$tKey] ?? null;
                        $paramStr = collect($trial['params'])->map(fn($v, $k) => "$k=$v")->implode(', ');
                        [$qLabel, $qColor] = $silLabel((float) $trial['silhouette_score']);
                    @endphp
                    <tr>
                        <td class="num">{{ $chartNo ?? '—' }}</td>
                        <td>{{ $trial['method'] }}</td>
                        <td><code>{{ $paramStr }}</code></td>
                        <td class="num">{{ $trial['n_clusters'] }}</td>
                        <td>{{ $trial['n_noise'] }} ({{ number_format($trial['noise_ratio'] * 100, 1) }}%)</td>
                        <td class="num" style="color: {{ $qColor }}; font-weight: 600;">{{ number_format($trial['silhouette_score'], 4) }}</td>
                        <td style="color: {{ $qColor }};">{{ $qLabel }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- Section 7: Rejected — degenerate --}}
    @if($rejectedDegenerate->isNotEmpty())
        <h2>{{ __('ui.rejected_degenerate_section') ?? '却下 — クラスタ構造を形成できず' }}</h2>
        <p>{{ __('ui.rejected_degenerate_explainer') ?? '有効なクラスタが2つ未満となった、もしくはスコアが算出できなかった候補です。' }}</p>
        <table>
            <thead>
                <tr>
                    <th>{{ __('ui.method') ?? '手法' }}</th>
                    <th>{{ __('ui.parameters') ?? 'パラメータ' }}</th>
                    <th class="num">{{ __('ui.cluster_count') ?? 'クラスタ数' }}</th>
                    <th>{{ __('ui.noise') ?? 'ノイズ' }} ({{ __('ui.ratio') ?? '比率' }})</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rejectedDegenerate as $trial)
                    @php $paramStr = collect($trial['params'])->map(fn($v, $k) => "$k=$v")->implode(', '); @endphp
                    <tr>
                        <td>{{ $trial['method'] }}</td>
                        <td><code>{{ $paramStr }}</code></td>
                        <td class="num">{{ $trial['n_clusters'] }}</td>
                        <td>{{ $trial['n_noise'] }} ({{ number_format($trial['noise_ratio'] * 100, 1) }}%)</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- Section 8: Per-method digest tables --}}
    <h2>{{ __('ui.score_progression_by_method') ?? '手法別スコア推移' }}</h2>
    @foreach(['kmeans', 'hdbscan', 'leiden'] as $method)
        @if(isset($byMethod[$method]) && $byMethod[$method]->isNotEmpty())
            <h3>{{ $method }}</h3>
            <table>
                <thead>
                    <tr>
                        <th>{{ __('ui.parameters') ?? 'パラメータ' }}</th>
                        <th class="num">{{ __('ui.cluster_count') ?? 'クラスタ数' }}</th>
                        <th class="num">{{ __('ui.noise') ?? 'ノイズ' }}</th>
                        <th class="num">{{ __('ui.silhouette') ?? 'シルエット' }}</th>
                        <th>{{ __('ui.quality') ?? '品質' }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($byMethod[$method] as $trial)
                        @php
                            $paramStr = collect($trial['params'])->map(fn($v, $k) => "$k=$v")->implode(', ');
                            $silDisplay = $trial['silhouette_score'] === null ? '—' : number_format($trial['silhouette_score'], 4);
                            [$qLabel, $qColor] = $silLabel($trial['silhouette_score']);
                        @endphp
                        <tr>
                            <td><code>{{ $paramStr }}</code></td>
                            <td class="num">{{ $trial['n_clusters'] }}</td>
                            <td class="num">{{ $trial['n_noise'] }}</td>
                            <td class="num" style="color: {{ $qColor }}; font-weight: 600;">{{ $silDisplay }}</td>
                            <td style="color: {{ $qColor }};">{{ $qLabel }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endforeach

    {{-- Section 9: Glossary — static reference for clustering methods and
         their parameters. Ported from the previous client-side PDF report
         so readers unfamiliar with HDBSCAN / K-Means / Agglomerative /
         Leiden have the vocabulary to interpret the tables above without
         leaving the page. --}}
    <h2 class="glossary-heading">{{ __('ui.glossary_heading') ?? '用語解説 / Glossary' }}</h2>
    <table class="glossary">
        <thead>
            <tr>
                <th style="width: 18%;">{{ __('ui.method') ?? '手法' }}</th>
                <th>{{ __('ui.glossary_description') ?? '説明とパラメータ' }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong>HDBSCAN</strong></td>
                <td>
                    Hierarchical Density-Based Spatial Clustering。密度ベースで自動的にクラスタ数を決定し、ノイズ点を検出できる手法。距離指標は euclidean ですが、入力ベクトルを L2 正規化しているため cosine 順位と等価。
                    <ul class="glossary-params">
                        <li><code>min_cluster_size</code>: クラスタとして扱う最小のデータ点数。大きいほど少数の大きなクラスタに、小さいほど多数の細かいクラスタになる</li>
                        <li><code>min_samples</code>: コア点と判定する近傍点の最小数。大きいほどノイズに敏感になり、小さいほど緩く判定する</li>
                        <li><code>metric</code>: 距離尺度。euclidean on L2-normalized vectors = cosine-equivalent</li>
                    </ul>
                </td>
            </tr>
            <tr>
                <td><strong>K-Means</strong></td>
                <td>
                    指定したクラスタ数 K でデータを分割する古典的手法。全点が必ずいずれかのクラスタに属する（ノイズ無し）。距離は euclidean ですが、L2 正規化された入力に対しては cosine 相当（spherical k-means に相当）。
                    <ul class="glossary-params">
                        <li><code>n_clusters</code>: 分割するクラスタ数。事前に決める必要がある</li>
                        <li><code>n_init</code>: ランダム初期化の試行回数。多いほど安定するが遅い</li>
                        <li><code>max_iter</code>: 各試行での最大反復回数</li>
                    </ul>
                </td>
            </tr>
            <tr>
                <td><strong>Agglomerative</strong></td>
                <td>
                    階層的クラスタリング。近いもの同士をボトムアップに結合していき、指定クラスタ数で切る。ward linkage を使用、距離は euclidean（L2 正規化により cosine 相当）。
                    <ul class="glossary-params">
                        <li><code>n_clusters</code>: 最終的な切り出しクラスタ数</li>
                        <li><code>linkage</code>: 結合基準。ward は分散最小化（デフォルト）、average は平均距離、single は最小距離</li>
                    </ul>
                </td>
            </tr>
            <tr>
                <td><strong>Leiden (HNSW + Leiden)</strong></td>
                <td>
                    HNSW で近傍グラフを構築し、Leiden アルゴリズムでコミュニティを検出するグラフベース手法。高次元埋め込みで自然なクラスタを見つけやすい。HNSW インデックスで cosine 距離を直接使用。
                    <ul class="glossary-params">
                        <li><code>n_neighbors</code>: 各点の近傍として考える数。小さいと細かく、大きいと粗く分かれる</li>
                        <li><code>resolution</code>: コミュニティの粒度パラメータ。大きいほどクラスタ数が増える</li>
                        <li><code>ef_construction</code>: HNSW インデックス構築時の探索広さ。大きいほど高精度だが構築が遅い</li>
                        <li><code>M</code>: HNSW の各点の近傍接続数。グラフ品質とメモリのトレードオフ</li>
                    </ul>
                </td>
            </tr>
            <tr>
                <td><strong>Silhouette</strong></td>
                <td>
                    クラスタ分離の品質指標 (-1 〜 1)。同じクラスタ内の凝集度と他クラスタとの分離度の差から算出。テキスト埋め込みでは <strong>0.10 以上で実用的</strong>、<strong>0.30 以上で良好</strong>、<strong>0.40 以上で強い分離</strong> と読み替えるのが実務的な目安です（一般文献では 0.5 以上が「良好」とされますが、テキスト埋め込みでは到達しにくいスコア帯です）。
                </td>
            </tr>
        </tbody>
    </table>

    <div class="footer">
        Generated by KPS @ <time datetime="{{ now()->toIso8601String() }}">{{ now()->format('Y/m/d H:i') }}</time>
        @if($job)・job #{{ $job->id }}@endif
    </div>
@endif

</body>
</html>
