"""
Cluster analysis step — LLM-powered topic naming, intent detection, and summarization.

For each cluster, this step sends the representative rows to Claude Sonnet
and receives structured analysis: topic_name, intent, summary, keywords, language.

CTO directive: "This cluster will become a Knowledge Unit."
CTO directive: "Prompt / Response Log は必須"

Input:  Completed clustering results in RDS (clusters + cluster_representatives)
Output: clusters table updated with topic_name, intent, summary
        cluster_analysis_logs table populated with prompt/response pairs
"""

import json
import logging
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime, timezone

from src.bedrock_llm_client import DEFAULT_MODEL_ID, invoke_claude
from src.db import get_connection, update_job_status, update_job_step_outputs, record_token_usage
from src.step_chain import dispatch_next_step

logger = logging.getLogger(__name__)

# Prompt versioning (CTO directive)
PROMPT_VERSION = "cluster_analysis_v1"


def _format_tickets(representative_texts: list[str]) -> str:
    """Format representative texts with numbering and truncation for prompt inclusion."""
    formatted = ""
    # Number each ticket and cap at 500 characters to stay within token limits
    for ticket_number, text in enumerate(representative_texts, 1):
        truncated = text[:500] + "..." if len(text) > 500 else text
        formatted += f"\n--- Ticket {ticket_number} ---\n{truncated}\n"
    return formatted


def build_analysis_prompt(
    representative_texts: list[str],
    cluster_size: int,
    dataset_description: str = "",
    column_descriptions: dict = None,
) -> str:
    """
    Build the initial LLM prompt for cluster analysis.

    The prompt instructs Claude to analyze representative tickets and produce
    a structured JSON response with topic, intent, summary, keywords, and language.
    Includes optional dataset and column descriptions for better context.
    """
    formatted_tickets = _format_tickets(representative_texts)

    # Append dataset/column descriptions to help LLM understand domain context
    context_section = ""
    if dataset_description:
        context_section += f"\nDataset context: {dataset_description}\n"
    if column_descriptions:
        col_lines = [f"  - {col}: {desc}" for col, desc in column_descriptions.items() if desc]
        if col_lines:
            context_section += "Column descriptions:\n" + "\n".join(col_lines) + "\n"

    return f"""You are a customer support analyst. Analyze the following support tickets
that belong to the same cluster and extract structured information.
{context_section}
Cluster size: {cluster_size} tickets
Representative tickets (closest to cluster center):
{formatted_tickets}

Respond ONLY with a JSON object (no markdown, no explanation):
{{
  "topic_name": "A concise name for this issue group (5 words max)",
  "intent": "The primary customer intent (e.g., troubleshooting, billing inquiry, refund request, product inquiry)",
  "summary": "A 2-3 sentence summary of the common issue pattern in these tickets",
  "keywords": ["keyword1", "keyword2", "keyword3", "keyword4", "keyword5"],
  "language": "The primary language code of the tickets (e.g., en, ja, de)"
}}

IMPORTANT:
- Base your analysis ONLY on the provided ticket texts
- Do not hallucinate or invent information not present in the tickets
- Respond in the same language as the input tickets
- The topic_name should be concise and descriptive (max 5 words)
- Provide exactly 3-5 keywords"""


def build_rename_prompt(
    representative_texts: list[str],
    cluster_size: int,
    taken_names: list[str],
) -> str:
    """
    Build a rename prompt for a cluster whose topic_name collides with another.

    Provides the list of already-taken names so the LLM can choose a distinct one
    that still accurately describes this cluster's content.
    """
    formatted_tickets = _format_tickets(representative_texts)
    taken_list = "\n".join(f"  - {name}" for name in taken_names)

    return f"""You are a customer support analyst. Analyze the following support tickets
that belong to the same cluster and extract structured information.

Cluster size: {cluster_size} tickets
Representative tickets (closest to cluster center):
{formatted_tickets}

The following topic names are ALREADY TAKEN by other clusters.
You MUST choose a DIFFERENT topic_name that does not duplicate any of these:
{taken_list}

Respond ONLY with a JSON object (no markdown, no explanation):
{{
  "topic_name": "A concise AND UNIQUE name for this issue group (5 words max)",
  "intent": "The primary customer intent (e.g., troubleshooting, billing inquiry, refund request, product inquiry)",
  "summary": "A 2-3 sentence summary of the common issue pattern in these tickets",
  "keywords": ["keyword1", "keyword2", "keyword3", "keyword4", "keyword5"],
  "language": "The primary language code of the tickets (e.g., en, ja, de)"
}}

IMPORTANT:
- The topic_name MUST be different from all names listed above
- Base your analysis ONLY on the provided ticket texts
- Focus on what makes THIS cluster distinct from the others
- The topic_name should be concise and descriptive (max 5 words)
- Provide exactly 3-5 keywords"""


def load_clusters_with_representatives(job_id: int) -> list[dict]:
    """
    Load all clusters for a job with their representative row texts.

    Returns a list of dicts with cluster info and representative texts.
    """
    conn = get_connection()
    try:
        with conn.cursor() as cur:
            # Fetch all clusters belonging to this pipeline job
            cur.execute(
                """SELECT c.id, c.cluster_label, c.row_count
                   FROM clusters c
                   WHERE c.pipeline_job_id = %s
                   ORDER BY c.cluster_label""",
                (job_id,),
            )
            clusters = []
            for row in cur.fetchall():
                clusters.append({
                    "id": row[0],
                    "cluster_label": row[1],
                    "row_count": row[2],
                    "representative_texts": [],
                })

            # For each cluster, load its representative ticket texts for LLM analysis
            for cluster in clusters:
                cur.execute(
                    """SELECT dr.raw_text
                       FROM cluster_representatives cr
                       JOIN dataset_rows dr ON cr.dataset_row_id = dr.id
                       WHERE cr.cluster_id = %s
                       ORDER BY cr.rank
                       LIMIT 10""",
                    (cluster["id"],),
                )
                cluster["representative_texts"] = [r[0] for r in cur.fetchall()]

            return clusters
    finally:
        conn.close()


def save_analysis_results(cluster_id: int, analysis: dict):
    """
    Update the clusters table with LLM analysis results.
    """
    conn = get_connection()
    try:
        with conn.cursor() as cur:
            now = datetime.now(timezone.utc)

            cur.execute(
                """UPDATE clusters
                   SET topic_name = %s, intent = %s, summary = %s, updated_at = %s
                   WHERE id = %s""",
                (
                    analysis.get("topic_name", ""),
                    analysis.get("intent", ""),
                    analysis.get("summary", ""),
                    now,
                    cluster_id,
                ),
            )
            conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()


def save_analysis_log(
    cluster_id: int,
    job_id: int,
    prompt: str,
    response_json: dict,
    input_tokens: int,
    output_tokens: int,
    model_id: str = None,
):
    """
    Save LLM prompt/response log to cluster_analysis_logs.
    CTO directive: mandatory for all LLM invocations.
    """
    if model_id is None:
        model_id = DEFAULT_MODEL_ID

    conn = get_connection()
    try:
        with conn.cursor() as cur:
            now = datetime.now(timezone.utc)

            cur.execute(
                """INSERT INTO cluster_analysis_logs
                   (cluster_id, pipeline_job_id, prompt, response_json, model, prompt_version,
                    input_tokens, output_tokens, created_at, updated_at)
                   VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)""",
                (
                    cluster_id, job_id, prompt, json.dumps(response_json),
                    model_id, PROMPT_VERSION, input_tokens, output_tokens, now, now,
                ),
            )
            conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()


MAX_DEDUP_ROUNDS = 5  # Safety limit for deduplication iterations


def _call_llm_for_cluster(cluster: dict, prompt: str, llm_model_id: str, job_id: int):
    """
    Call the LLM for a single cluster, handle JSON parse failures with one retry.
    Returns (analysis_dict, total_input_tokens, total_output_tokens).
    """
    result = invoke_claude(prompt, model_id=llm_model_id)
    input_tokens = result["input_tokens"]
    output_tokens = result["output_tokens"]

    # Save log (CTO directive: mandatory for every LLM call)
    save_analysis_log(
        cluster_id=cluster["id"],
        job_id=job_id,
        prompt=prompt,
        response_json=result["parsed_json"] or {"raw": result["content"]},
        input_tokens=result["input_tokens"],
        output_tokens=result["output_tokens"],
        model_id=result["model_id"],
    )

    analysis = result["parsed_json"]
    # If initial parse failed, retry with an explicit JSON-only instruction
    if analysis is None:
        logger.warning("Cluster %d: LLM response not valid JSON, retrying...", cluster["id"])
        retry_result = invoke_claude(
            prompt + "\n\nIMPORTANT: Respond with ONLY valid JSON, nothing else.",
            model_id=llm_model_id,
        )
        analysis = retry_result["parsed_json"]
        input_tokens += retry_result["input_tokens"]
        output_tokens += retry_result["output_tokens"]

    # Fall back to a generic placeholder when JSON parsing fails entirely
    if analysis is None:
        logger.error("Cluster %d: Failed to get valid JSON after retry", cluster["id"])
        analysis = {
            "topic_name": f"Cluster {cluster['cluster_label']}",
            "intent": "unknown",
            "summary": "Analysis failed — LLM did not return valid JSON.",
            "keywords": [],
            "language": "en",
        }

    return analysis, input_tokens, output_tokens


def _find_duplicates(clusters: list[dict]) -> dict[str, list[dict]]:
    """
    Find clusters with duplicate topic_names.
    Returns a dict mapping topic_name -> list of clusters sharing that name.
    Only includes names with 2+ clusters.
    """
    name_map: dict[str, list[dict]] = {}
    for c in clusters:
        name = c["analysis"].get("topic_name", "").strip().lower()
        name_map.setdefault(name, []).append(c)
    return {name: duplicate_clusters for name, duplicate_clusters in name_map.items() if len(duplicate_clusters) > 1}


def _resolve_duplicates(
    clusters: list[dict],
    llm_model_id: str,
    job_id: int,
) -> tuple[int, int]:
    """
    Iteratively resolve duplicate topic_names.

    Strategy:
    1. Find all duplicate names
    2. For each duplicate group, keep the name on the largest cluster
    3. Re-prompt smaller clusters with the list of taken names
    4. Repeat until no duplicates remain (up to MAX_DEDUP_ROUNDS)

    Returns (total_input_tokens, total_output_tokens) consumed by renaming.
    """
    total_input_tokens = 0
    total_output_tokens = 0

    # Iteratively rename clusters until all topic_names are unique
    for round_num in range(1, MAX_DEDUP_ROUNDS + 1):
        duplicates = _find_duplicates(clusters)
        if not duplicates:
            logger.info("Dedup round %d: no duplicates — done.", round_num)
            break

        clusters_to_rename = sum(len(cs) - 1 for cs in duplicates.values())
        logger.info(
            "Dedup round %d: %d duplicate names affecting %d clusters",
            round_num, len(duplicates), clusters_to_rename,
        )

        # Collect all taken names (names that will NOT be renamed)
        taken_names = [
            c["analysis"]["topic_name"]
            for c in clusters
        ]

        for dup_name, dup_clusters in duplicates.items():
            # Sort by row_count descending so the largest cluster keeps its name
            dup_clusters.sort(key=lambda cluster: cluster["row_count"], reverse=True)

            # Re-prompt all but the largest cluster with a unique-name constraint
            for cluster in dup_clusters[1:]:
                # Build taken-names list excluding this cluster's current name once
                other_names = [n for n in taken_names if n != cluster["analysis"]["topic_name"]]
                # Add back the winner's name to ensure it's in the list
                winner_name = dup_clusters[0]["analysis"]["topic_name"]
                if winner_name not in other_names:
                    other_names.append(winner_name)

                rename_prompt = build_rename_prompt(
                    cluster["representative_texts"],
                    cluster["row_count"],
                    other_names,
                )

                analysis, in_tok, out_tok = _call_llm_for_cluster(
                    cluster, rename_prompt, llm_model_id, job_id,
                )
                total_input_tokens += in_tok
                total_output_tokens += out_tok

                old_name = cluster["analysis"]["topic_name"]
                # Preserve intent/summary/keywords from rename if provided,
                # but always update topic_name
                cluster["analysis"]["topic_name"] = analysis.get("topic_name", old_name)

                logger.info(
                    "Cluster %d renamed: '%s' -> '%s'",
                    cluster["id"], old_name, cluster["analysis"]["topic_name"],
                )

                # Update taken_names for subsequent renames in this round
                taken_names = [c["analysis"]["topic_name"] for c in clusters]
    else:
        # for/else: ran all rounds without early break — append numeric suffixes as last resort
        remaining = _find_duplicates(clusters)
        if remaining:
            logger.warning(
                "Dedup exhausted %d rounds, %d duplicates remain. Appending suffixes.",
                MAX_DEDUP_ROUNDS, len(remaining),
            )
            # Final fallback: append numeric suffix
            for dup_name, dup_clusters in remaining.items():
                dup_clusters.sort(key=lambda cluster: cluster["row_count"], reverse=True)
                for idx, cluster in enumerate(dup_clusters[1:], 2):
                    cluster["analysis"]["topic_name"] = (
                        f"{cluster['analysis']['topic_name']} ({idx})"
                    )

    return total_input_tokens, total_output_tokens


def execute(job_id: int, tenant_id: int, dataset_id: int = None, **kwargs):
    """
    Execute the cluster analysis step.

    Two-pass approach with deduplication:
    Pass 1: Name all clusters independently via LLM (no DB writes yet)
    Pass 2: Detect duplicate topic_names, rename smaller clusters via LLM
    Final:  Save all results to DB once names are unique
    """
    logger.info("Cluster analysis step started for job %d", job_id)
    update_job_status(job_id, status="cluster_analysis", progress=10)

    # Resolve LLM model from pipeline_config (user-selectable, default: Haiku 4.5)
    pipeline_config = kwargs.get("pipeline_config") or {}
    llm_model_id = pipeline_config.get("llm_model_id") or DEFAULT_MODEL_ID
    dataset_description = pipeline_config.get("dataset_description", "")
    column_descriptions = pipeline_config.get("column_descriptions", {})
    logger.info("Using LLM model: %s", llm_model_id)

    # Load clusters with representatives
    clusters = load_clusters_with_representatives(job_id)
    logger.info("Loaded %d clusters for analysis", len(clusters))

    # Guard: no clusters means a prior step failed or produced no output
    if not clusters:
        update_job_status(job_id, status="failed", error_detail="No clusters found for analysis")
        return

    total_input_tokens = 0
    total_output_tokens = 0

    # ── Pass 1: Name all clusters in parallel (no DB writes yet) ─────────
    # LLM calls are I/O-bound; 3 parallel threads cuts wall-clock time by ~3x
    LLM_WORKERS = min(3, len(clusters))

    def _analyze_one(cluster):
        """Analyze a single cluster via LLM. Thread-safe."""
        prompt = build_analysis_prompt(
            cluster["representative_texts"],
            cluster["row_count"],
            dataset_description=dataset_description,
            column_descriptions=column_descriptions,
        )
        analysis, input_tokens, output_tokens = _call_llm_for_cluster(
            cluster, prompt, llm_model_id, job_id,
        )
        return cluster["id"], analysis, input_tokens, output_tokens

    logger.info("Pass 1 — Analyzing %d clusters in parallel (workers=%d)", len(clusters), LLM_WORKERS)

    with ThreadPoolExecutor(max_workers=LLM_WORKERS) as executor:
        futures = {
            executor.submit(_analyze_one, cluster): cluster
            for cluster in clusters
        }
        completed = 0
        for future in as_completed(futures):
            cluster = futures[future]
            cluster_id, analysis, input_tokens, output_tokens = future.result()
            cluster["analysis"] = analysis
            total_input_tokens += input_tokens
            total_output_tokens += output_tokens
            completed += 1

            progress = 10 + int((completed / len(clusters)) * 60)
            update_job_status(job_id, status="cluster_analysis", progress=progress)

            logger.info(
                "Pass 1 — Cluster %d named: topic='%s', intent='%s' (%d/%d)",
                cluster_id,
                analysis.get("topic_name", "?"),
                analysis.get("intent", "?"),
                completed, len(clusters),
            )

    update_job_status(job_id, status="cluster_analysis", progress=75)

    # ── Pass 2: Resolve duplicate topic_names ────────────────────────────
    dedup_in, dedup_out = _resolve_duplicates(clusters, llm_model_id, job_id)
    total_input_tokens += dedup_in
    total_output_tokens += dedup_out

    update_job_status(job_id, status="cluster_analysis", progress=85)

    # ── Final: Save all results to DB ────────────────────────────────────
    for cluster in clusters:
        save_analysis_results(cluster["id"], cluster["analysis"])

    update_job_status(job_id, status="cluster_analysis", progress=90)

    # Record step metadata
    cluster_summaries = []
    for cluster in clusters:
        analysis = cluster.get("analysis", {})
        cluster_summaries.append({
            "cluster_label": cluster["cluster_label"],
            "topic_name": analysis.get("topic_name", ""),
            "intent": analysis.get("intent", ""),
            "keywords": analysis.get("keywords", []),
            "language": analysis.get("language", ""),
        })

    update_job_step_outputs(job_id, "cluster_analysis", {
        "clusters_analyzed": len(clusters),
        "prompt_version": PROMPT_VERSION,
        "model": llm_model_id,
        "total_input_tokens": total_input_tokens,
        "total_output_tokens": total_output_tokens,
        "cluster_summaries": cluster_summaries,
    })

    # Record aggregated token usage only when LLM was actually invoked
    if total_input_tokens > 0 or total_output_tokens > 0:
        record_token_usage(
            tenant_id, "cluster_analysis", llm_model_id,
            total_input_tokens, total_output_tokens,
        )

    logger.info(
        "Cluster analysis completed for job %d: %d clusters, %d in-tokens, %d out-tokens",
        job_id, len(clusters), total_input_tokens, total_output_tokens,
    )

    # Chain to knowledge_unit_generation
    next_step = dispatch_next_step(
        current_step="cluster_analysis",
        job_id=job_id,
        tenant_id=tenant_id,
        dataset_id=dataset_id,
        output_s3_path=None,
        pipeline_config=kwargs.get("pipeline_config", {}),
    )

    # If no next step exists, this is the final step in the pipeline
    if next_step is None:
        update_job_status(job_id, status="completed", progress=100)
