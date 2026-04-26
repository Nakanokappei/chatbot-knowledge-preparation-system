"""
LLM advisory note for the parameter-search report.

Ported from voice-classifier `src/advisor.py`. After the sweep picks a
winner, this module:

    1. Identifies the top-N largest sample clusters under the winner config.
    2. Pulls a handful of representative `raw_text` rows for each top
       cluster from `dataset_rows`.
    3. Asks Bedrock Claude to produce a short label per cluster (one call,
       JSON output).
    4. Builds a plain-language digest of the run (cluster count, noise %,
       coverage at rank, top group labels) and asks Bedrock Claude for a
       Markdown advisory aimed at non-technical executives.

Failures are soft — the parameter-search report is still useful without
the advisory section, so any exception is logged and an empty advisory
is returned.
"""

from __future__ import annotations

import json
import logging
from collections import Counter
from typing import Any

import numpy as np

from src.bedrock_llm_client import invoke_claude
from src.db import get_connection
from src.target_scoring import TARGET_PROFILES

logger = logging.getLogger(__name__)

# Hard cap on the number of clusters we feed the advisor. Beyond this the
# prompt grows without adding signal, and the cost scales linearly. Voice-
# classifier uses the same cap for the same reason.
TOP_CLUSTERS_IN_PROMPT: int = 15

# Representative raw_text rows per cluster shown to the namer prompt. Five
# is enough for Claude to spot the common topic without flooding the prompt.
REPS_PER_CLUSTER: int = 5

# Coverage ranks reported in the digest (cumulative %% of rows covered by
# the top-K largest groups).
COVERAGE_RANKS: tuple[int, ...] = (1, 5, 10, 20, 50, 100)


# ---------------------------------------------------------------------------
# Step 1 — assemble the input the advisor needs
# ---------------------------------------------------------------------------


def build_advisor_input(
    results: list[dict],
    sample_size: int,
    total_rows: int,
    target: str,
    winner_sample_labels: np.ndarray,
    sampled_row_ids: list | None,
    input_s3_path: str,
) -> dict:
    """Collect everything the namer + advisor need into a single dict.

    The winner trial is identified by the highest active-target score among
    candidates that pass the noise-ratio filter. Representative texts are
    looked up from the database in one batched query so we only round-trip
    once even when 15 clusters x 5 rows are requested.
    """
    # Derive cluster-size buckets directly from the cached winner labels.
    counts = Counter(
        int(label) for label in winner_sample_labels if int(label) != -1
    )
    top_n_pairs = counts.most_common(TOP_CLUSTERS_IN_PROMPT)

    # Map sample-cluster id -> list of row_ids (sample positions resolved
    # back to the dataset_rows.id values via sampled_row_ids).
    top_clusters: list[dict] = []
    if sampled_row_ids is not None:
        for cluster_id, sample_size_in_cluster in top_n_pairs:
            sample_positions = [
                i
                for i, label in enumerate(winner_sample_labels)
                if int(label) == cluster_id
            ]
            # Choose representative positions evenly spread across the
            # cluster — the first N can over-represent one part of the
            # distribution because the sweep keeps row order from the
            # original embedding file.
            picks = _evenly_spaced(sample_positions, REPS_PER_CLUSTER)
            row_ids = [sampled_row_ids[i] for i in picks if i < len(sampled_row_ids)]
            top_clusters.append({
                "cluster_id": int(cluster_id),
                "sample_size": int(sample_size_in_cluster),
                "row_ids": row_ids,
                "representatives": [],  # filled in by _load_raw_texts below
            })

        # Single batched fetch — much cheaper than per-cluster queries.
        _attach_raw_texts(top_clusters)
    else:
        # No row_ids means we can still produce stats-only digest
        for cluster_id, sample_size_in_cluster in top_n_pairs:
            top_clusters.append({
                "cluster_id": int(cluster_id),
                "sample_size": int(sample_size_in_cluster),
                "row_ids": [],
                "representatives": [],
            })

    # Identify the winner trial in `results` so we can quote its parameters
    # and silhouette in the digest. We match by `score_{target}` because the
    # worker selected the same way.
    candidates = [
        r for r in results
        if r.get(f"score_{target}", 0) > 0
    ]
    winner_trial = max(
        candidates,
        key=lambda r: r[f"score_{target}"],
        default=None,
    )

    return {
        "target": target,
        "winner_trial": winner_trial,
        "sample_size": sample_size,
        "total_rows": total_rows,
        "top_clusters": top_clusters,
        "non_noise_sample_size": sum(counts.values()),
        "noise_sample_size": int(np.sum(winner_sample_labels == -1)),
    }


def _evenly_spaced(values: list[int], k: int) -> list[int]:
    """Pick `k` items evenly spread across the input list.

    Avoids over-sampling one end of the cluster when row order is not
    randomised. Returns fewer than `k` items if the input is shorter.
    """
    if not values or k <= 0:
        return []
    if len(values) <= k:
        return values
    step = len(values) / k
    return [values[int(i * step)] for i in range(k)]


def _attach_raw_texts(top_clusters: list[dict]) -> None:
    """Fetch dataset_rows.raw_text for every row_id and attach to clusters.

    Uses a single SELECT with a list parameter so RLS context is enforced
    by the connection setup and we don't pay per-cluster latency.
    Mutates `top_clusters` in place.
    """
    all_ids = []
    for cluster in top_clusters:
        all_ids.extend(cluster["row_ids"])
    if not all_ids:
        return

    rows_by_id: dict[int, str] = {}
    try:
        conn = get_connection()
        try:
            with conn.cursor() as cur:
                cur.execute(
                    "SELECT id, raw_text FROM dataset_rows WHERE id = ANY(%s)",
                    (all_ids,),
                )
                for row in cur.fetchall():
                    rows_by_id[int(row[0])] = row[1] or ""
        finally:
            conn.close()
    except Exception as e:
        logger.warning("Could not load raw_text for advisor: %s", e)
        return

    for cluster in top_clusters:
        cluster["representatives"] = [
            rows_by_id.get(int(rid), "")
            for rid in cluster["row_ids"]
            if rows_by_id.get(int(rid))
        ]


# ---------------------------------------------------------------------------
# Step 2 — name the top clusters
# ---------------------------------------------------------------------------


def _name_top_clusters(top_clusters: list[dict], model_id: str) -> dict[int, str]:
    """Ask Bedrock for a short Japanese-or-English label per cluster.

    One Claude call covers every cluster — the prompt sends a JSON list and
    asks for a JSON list of `{cluster_id, label}` back. Falls back to a
    generic placeholder when the call fails or the response is malformed.

    `model_id` is the workspace-approved Bedrock model ID — passed in from
    pipeline_config so the worker never falls back to a default model the
    workspace hasn't explicitly approved.
    """
    namable = [c for c in top_clusters if c["representatives"]]
    if not namable:
        return {}

    payload = [
        {
            "cluster_id": cluster["cluster_id"],
            "rep_count": len(cluster["representatives"]),
            "examples": [
                _truncate_text(text, 240)
                for text in cluster["representatives"][:REPS_PER_CLUSTER]
            ],
        }
        for cluster in namable
    ]

    prompt = (
        "You are labelling clusters discovered by an automated text-clustering "
        "run over customer-voice data (support tickets, inquiries, feedback). "
        "For every cluster I give you 3-5 representative texts; return a "
        "short label that names the common topic.\n\n"
        "Rules:\n"
        "1. Each label must be 6-30 characters, a noun phrase (not a sentence).\n"
        "2. Match the language used in the source examples — if they are "
        "in Japanese, label in Japanese; if mixed, prefer the dominant language.\n"
        "3. Be specific. \"Software issues\" is too vague; "
        "\"Firmware update related\" or \"Echo software update\" is right.\n"
        "4. No quotation marks around the label, no trailing punctuation.\n"
        "5. Return STRICT JSON only — no markdown, no commentary. "
        "Schema: {\"labels\":[{\"cluster_id\":<int>,\"label\":<string>}, ...]}\n\n"
        f"Clusters:\n{json.dumps(payload, ensure_ascii=False, indent=2)}\n"
    )

    try:
        response = invoke_claude(
            prompt=prompt,
            max_tokens=1024,
            temperature=0.1,
            expect_json=True,
            model_id=model_id,
        )
    except Exception as e:
        logger.warning("Cluster naming failed: %s", e)
        return {}

    parsed = response.get("parsed_json")
    if not parsed or "labels" not in parsed:
        logger.warning("Cluster naming returned unparseable response")
        return {}

    names: dict[int, str] = {}
    for entry in parsed["labels"]:
        try:
            cid = int(entry["cluster_id"])
            label = str(entry["label"]).strip()
            if label:
                names[cid] = label
        except (KeyError, ValueError, TypeError):
            continue

    return names


def _truncate_text(text: str, max_len: int) -> str:
    """Trim long source texts so the namer prompt stays compact."""
    if not text:
        return ""
    flat = " ".join(text.split())
    if len(flat) <= max_len:
        return flat
    return flat[: max_len - 1] + "…"


# ---------------------------------------------------------------------------
# Step 3 — build digest + invoke advisor
# ---------------------------------------------------------------------------


def generate_advisory_markdown(
    input_data: dict,
    model_id: str | None = None,
) -> tuple[str, list[dict], dict]:
    """Produce the executive Markdown advisory + per-cluster metadata.

    Returns a 3-tuple:
        (advisory_markdown, top_clusters_meta, advisor_meta)

    `top_clusters_meta` carries names, sample sizes, and estimated total
    sizes for the report's "Top Groups" section. `advisor_meta` records
    model/token counts so the report can attribute the AI output.

    `model_id` MUST be the workspace-approved Bedrock model ID (forwarded
    from pipeline_config['llm_model_id']). When it is missing the advisor
    is skipped entirely — the worker must never silently fall back to a
    default Bedrock model the workspace has not explicitly approved.
    """
    target = input_data["target"]
    winner = input_data["winner_trial"]
    if winner is None:
        return "", [], {}
    if not model_id:
        logger.info(
            "Skipping advisor: pipeline_config['llm_model_id'] not set "
            "for this job. Advisor only runs against workspace-approved models."
        )
        return "", [], {}

    sample_size = max(input_data["sample_size"], 1)
    total_rows = max(input_data["total_rows"], 1)

    # Step A — name clusters (1 Bedrock call against the approved model).
    cluster_names = _name_top_clusters(input_data["top_clusters"], model_id)

    # Step B — assemble cluster metadata for both the digest and the report.
    top_clusters_meta = []
    for cluster in input_data["top_clusters"]:
        cid = cluster["cluster_id"]
        sample_count = cluster["sample_size"]
        # Scale sample-share back to total-rows scale so executives see
        # numbers they can connect to actual customer volume.
        estimated_total = int(round(sample_count / sample_size * total_rows))
        top_clusters_meta.append({
            "cluster_id": cid,
            "name": cluster_names.get(cid, f"グループ #{cid}"),
            "sample_size": sample_count,
            "estimated_total_rows": estimated_total,
        })

    # Step C — coverage table (top-K cumulative percentages).
    sorted_clusters = sorted(
        top_clusters_meta, key=lambda c: c["estimated_total_rows"], reverse=True
    )
    coverage_total = []
    coverage_ex_noise = []
    running = 0
    non_noise_total = max(input_data["non_noise_sample_size"], 1)
    non_noise_total_estimated = int(round(non_noise_total / sample_size * total_rows))
    for idx, cluster in enumerate(sorted_clusters, start=1):
        running += cluster["estimated_total_rows"]
        if idx in COVERAGE_RANKS:
            coverage_total.append((idx, running / total_rows * 100.0))
            if non_noise_total_estimated:
                coverage_ex_noise.append(
                    (idx, running / non_noise_total_estimated * 100.0)
                )

    # Step D — single-cluster max share (already on the trial as a fraction).
    max_share_pct = float(winner.get("max_cluster_share", 0.0)) * 100.0
    noise_ratio_pct = (
        input_data["noise_sample_size"] / sample_size * 100.0
    )

    digest_text = _build_digest_text(
        target=target,
        winner=winner,
        n_clusters=int(winner["n_clusters"]),
        sample_noise=input_data["noise_sample_size"],
        sample_size=sample_size,
        total_rows=total_rows,
        max_share_pct=max_share_pct,
        noise_ratio_pct=noise_ratio_pct,
        coverage_total=coverage_total,
        coverage_ex_noise=coverage_ex_noise,
        top_clusters_meta=top_clusters_meta,
    )

    # Step E — call the advisor (1 Bedrock call against the approved model).
    try:
        response = invoke_claude(
            prompt=_build_advisor_prompt(digest_text),
            max_tokens=2000,
            temperature=0.4,
            expect_json=False,
            model_id=model_id,
        )
    except Exception as e:
        logger.warning("Advisor invocation failed: %s", e)
        return "", top_clusters_meta, {}

    raw_md = response.get("content", "") or ""
    advisory_md = _sanitise_markdown(raw_md)

    advisor_meta = {
        "model_id": response.get("model_id", model_id),
        "input_tokens": int(response.get("input_tokens", 0)),
        "output_tokens": int(response.get("output_tokens", 0)),
    }
    return advisory_md, top_clusters_meta, advisor_meta


def _build_digest_text(
    *,
    target: str,
    winner: dict,
    n_clusters: int,
    sample_noise: int,
    sample_size: int,
    total_rows: int,
    max_share_pct: float,
    noise_ratio_pct: float,
    coverage_total: list[tuple[int, float]],
    coverage_ex_noise: list[tuple[int, float]],
    top_clusters_meta: list[dict],
) -> str:
    """Render the digest as plain-language bullet points for Claude."""
    target_label = {
        "faq": "FAQ page creation",
        "chatbot": "chatbot question-type design",
        "insight": "exploratory analysis",
    }.get(target, target)

    profile = TARGET_PROFILES.get(target, {})
    cluster_range = profile.get("cluster_range")
    range_text = (
        f"target cluster range: {cluster_range[0]}-{cluster_range[1]}"
        if cluster_range
        else "no target cluster range"
    )

    method = winner.get("method", "?")
    params = winner.get("params") or {}
    params_text = ", ".join(f"{k}={v}" for k, v in params.items()) or "(none)"
    silhouette = winner.get("silhouette_score", 0.0)

    lines: list[str] = []
    lines.append(f"- Purpose of this analysis: {target_label} ({range_text})")
    lines.append(
        f"- Sorting method used: {method} (settings: {params_text})"
    )
    lines.append(f"- Groups found: {n_clusters}")
    lines.append(
        f"- Uncategorised inquiries: {sample_noise} of {sample_size:,} sampled "
        f"({noise_ratio_pct:.1f}% — applied to all {total_rows:,} rows: "
        f"~{int(noise_ratio_pct / 100 * total_rows):,} customers)"
    )
    lines.append(
        f"- Largest single group covers ~{max_share_pct:.1f}% of all inquiries"
    )
    lines.append(
        f"- Separation quality score: {silhouette:.4f} "
        f"(higher is better; above 0.25 is usable, above 0.40 is strong)"
    )
    if coverage_total:
        cov = ", ".join(
            f"top {n} groups = {pct:.1f}%"
            for n, pct in coverage_total
        )
        lines.append(
            f"- Inquiries covered by the largest groups (% of ALL inquiries): {cov}"
        )
    if coverage_ex_noise:
        cov2 = ", ".join(
            f"top {n} groups = {pct:.1f}%"
            for n, pct in coverage_ex_noise
        )
        lines.append(
            f"- Inquiries covered by the largest groups "
            f"(% of CATEGORISED inquiries only): {cov2}"
        )
    if top_clusters_meta:
        labels_block = "\n".join(
            f"    - \"{cluster['name']}\" (~{cluster['estimated_total_rows']:,} inquiries)"
            for cluster in sorted(
                top_clusters_meta,
                key=lambda c: c["estimated_total_rows"],
                reverse=True,
            )
        )
        lines.append("- Largest groups by volume:")
        lines.append(labels_block)
    return "\n".join(lines)


def _build_advisor_prompt(digest_text: str) -> str:
    """System + user instruction merged into a single Claude turn.

    Bedrock Converse takes a single user message in this codebase, so the
    voice-classifier system+user split collapses into one prompt with the
    audience/style rules up top and the digest at the bottom.
    """
    return (
        "You are summarising the results of an automated customer-voice "
        "analysis for executive leadership. Write a clear advisory note in "
        "Markdown.\n\n"
        "## Audience\n\n"
        "The readers are non-technical senior executives (e.g. a VP of "
        "Customer Service, a board member) who make budget and staffing "
        "decisions based on this report. They understand business numbers "
        "(percentages, row counts) but have NO background in data science "
        "or statistics. Assume they have never heard words like "
        "'silhouette', 'clustering', 'algorithm', 'centroid', 'noise', "
        "'HDBSCAN', 'KMeans', 'Leiden', 'embedding', 'vector', or "
        "'dimensionality reduction'.\n\n"
        "## Writing rules\n\n"
        "1. Absolute ban on jargon. Replace it with plain-language equivalents:\n"
        "   - 'cluster' → 'group' or 'category'\n"
        "   - 'noise / unassigned' → 'uncategorised inquiries'\n"
        "   - 'silhouette score' → 'separation quality' or drop entirely\n"
        "   - 'algorithm' → 'automatic sorting method'\n"
        "2. Use short sentences. One idea per sentence.\n"
        "3. Use concrete examples: quote actual group names from the digest "
        "(in quotation marks) so readers can picture the content.\n"
        "4. When you cite numbers, immediately follow with a plain "
        "interpretation (e.g. '412 inquiries — about 1 in 20 of all messages').\n"
        "5. The tone should be that of a trusted advisor briefing the CEO "
        "before a board meeting: direct, confident, no hedging.\n\n"
        "## Structure\n\n"
        "- Start with a level-2 heading `## 分析サマリ`.\n"
        "- Then four level-3 subsections in this order:\n"
        "  `### 全体評価` — one paragraph (2-3 sentences). State whether the "
        "result is ready for operational use, and in one line why or why not.\n"
        "  `### この結果でできること` — bullet list (3-5 items). Each bullet is a "
        "concrete business action.\n"
        "  `### 注意すべき点` — bullet list. Each bullet names a specific risk "
        "or gap and what to do about it.\n"
        "  `### 推奨アクション` — numbered list. Each item is a specific action "
        "with a clear owner suggestion (e.g. 'カスタマーサービスチームは…').\n"
        "- Use the EXACT group counts and names from the digest.\n"
        "- Write the entire note in Japanese.\n"
        "- Return ONLY Markdown — no code fences wrapping the whole answer, "
        "no preamble, no trailing remarks.\n\n"
        "## Run digest\n\n"
        f"{digest_text}\n\n"
        "Write the advisory note now."
    )


def _sanitise_markdown(raw: str) -> str:
    """Strip accidental code fences and surrounding whitespace.

    Some models occasionally wrap their whole response in ```markdown
    fences. We want the inner content only.
    """
    text = raw.strip()
    if text.startswith("```"):
        text = text.lstrip("`")
        first_newline = text.find("\n")
        if first_newline != -1 and text[:first_newline].strip().lower() in {
            "markdown",
            "md",
            "",
        }:
            text = text[first_newline + 1 :]
        if text.endswith("```"):
            text = text[:-3]
        text = text.strip()
    return text
