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

from src.bedrock_llm_client import MODEL_ID, invoke_claude
from src.db import get_connection, update_job_status, update_job_step_outputs
from src.step_chain import dispatch_next_step

logger = logging.getLogger(__name__)

# Prompt versioning (CTO directive)
PROMPT_VERSION = "cluster_analysis_v1"


def build_analysis_prompt(representative_texts: list[str], cluster_size: int) -> str:
    """
    Build the LLM prompt for cluster analysis.

    The prompt instructs Claude to analyze representative tickets and produce
    a structured JSON response with topic, intent, summary, keywords, and language.
    """
    # Format representative texts with numbering
    formatted_tickets = ""
    for i, text in enumerate(representative_texts, 1):
        # Truncate very long texts to keep prompt reasonable
        truncated = text[:500] + "..." if len(text) > 500 else text
        formatted_tickets += f"\n--- Ticket {i} ---\n{truncated}\n"

    return f"""You are a customer support analyst. Analyze the following support tickets
that belong to the same cluster and extract structured information.

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


def load_clusters_with_representatives(job_id: int) -> list[dict]:
    """
    Load all clusters for a job with their representative row texts.

    Returns a list of dicts with cluster info and representative texts.
    """
    conn = get_connection()
    try:
        with conn.cursor() as cur:
            # Get clusters for this job
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

            # Get representative texts for each cluster
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
            from datetime import datetime, timezone
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
):
    """
    Save LLM prompt/response log to cluster_analysis_logs.
    CTO directive: mandatory for all LLM invocations.
    """
    conn = get_connection()
    try:
        with conn.cursor() as cur:
            from datetime import datetime, timezone
            now = datetime.now(timezone.utc)

            cur.execute(
                """INSERT INTO cluster_analysis_logs
                   (cluster_id, pipeline_job_id, prompt, response_json, model, prompt_version,
                    input_tokens, output_tokens, created_at, updated_at)
                   VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)""",
                (
                    cluster_id, job_id, prompt, json.dumps(response_json),
                    MODEL_ID, PROMPT_VERSION, input_tokens, output_tokens, now, now,
                ),
            )
            conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()


def execute(job_id: int, tenant_id: int, dataset_id: int = None, **kwargs):
    """
    Execute the cluster analysis step.

    For each cluster:
    1. Load representative rows
    2. Build LLM prompt
    3. Call Claude Sonnet
    4. Parse JSON response
    5. Save results to clusters table
    6. Save prompt/response log
    7. Chain to knowledge_unit_generation step
    """
    logger.info("Cluster analysis step started for job %d", job_id)
    update_job_status(job_id, status="cluster_analysis", progress=10)

    # Step 1: Load clusters with representatives
    clusters = load_clusters_with_representatives(job_id)
    logger.info("Loaded %d clusters for analysis", len(clusters))

    if not clusters:
        update_job_status(job_id, status="failed", error_detail="No clusters found for analysis")
        return

    total_input_tokens = 0
    total_output_tokens = 0

    # Step 2: Analyze each cluster
    for i, cluster in enumerate(clusters):
        progress = 10 + int((i / len(clusters)) * 75)
        update_job_status(job_id, status="cluster_analysis", progress=progress)

        logger.info(
            "Analyzing cluster %d (label=%d, size=%d, representatives=%d)",
            cluster["id"], cluster["cluster_label"],
            cluster["row_count"], len(cluster["representative_texts"]),
        )

        # Build prompt
        prompt = build_analysis_prompt(
            cluster["representative_texts"],
            cluster["row_count"],
        )

        # Call Claude
        result = invoke_claude(prompt)

        total_input_tokens += result["input_tokens"]
        total_output_tokens += result["output_tokens"]

        # Save log (CTO directive: mandatory)
        save_analysis_log(
            cluster_id=cluster["id"],
            job_id=job_id,
            prompt=prompt,
            response_json=result["parsed_json"] or {"raw": result["content"]},
            input_tokens=result["input_tokens"],
            output_tokens=result["output_tokens"],
        )

        # Parse and validate response
        analysis = result["parsed_json"]
        if analysis is None:
            logger.warning(
                "Cluster %d: LLM response is not valid JSON, retrying...",
                cluster["id"],
            )
            # Retry once with explicit JSON instruction
            retry_result = invoke_claude(
                prompt + "\n\nIMPORTANT: Respond with ONLY valid JSON, nothing else."
            )
            analysis = retry_result["parsed_json"]
            total_input_tokens += retry_result["input_tokens"]
            total_output_tokens += retry_result["output_tokens"]

        if analysis is None:
            logger.error("Cluster %d: Failed to get valid JSON after retry", cluster["id"])
            analysis = {
                "topic_name": f"Cluster {cluster['cluster_label']}",
                "intent": "unknown",
                "summary": "Analysis failed — LLM did not return valid JSON.",
                "keywords": [],
                "language": "en",
            }

        # Save results to clusters table
        save_analysis_results(cluster["id"], analysis)

        # Store keywords and language in cluster dict for later use
        cluster["analysis"] = analysis

        logger.info(
            "Cluster %d analyzed: topic='%s', intent='%s'",
            cluster["id"],
            analysis.get("topic_name", "?"),
            analysis.get("intent", "?"),
        )

    update_job_status(job_id, status="cluster_analysis", progress=90)

    # Step 3: Record step metadata
    cluster_summaries = []
    for cluster in clusters:
        a = cluster.get("analysis", {})
        cluster_summaries.append({
            "cluster_label": cluster["cluster_label"],
            "topic_name": a.get("topic_name", ""),
            "intent": a.get("intent", ""),
            "keywords": a.get("keywords", []),
            "language": a.get("language", ""),
        })

    update_job_step_outputs(job_id, "cluster_analysis", {
        "clusters_analyzed": len(clusters),
        "prompt_version": PROMPT_VERSION,
        "model": MODEL_ID,
        "total_input_tokens": total_input_tokens,
        "total_output_tokens": total_output_tokens,
        "cluster_summaries": cluster_summaries,
    })

    logger.info(
        "Cluster analysis completed for job %d: %d clusters, %d input tokens, %d output tokens",
        job_id, len(clusters), total_input_tokens, total_output_tokens,
    )

    # Step 4: Chain to knowledge_unit_generation
    next_step = dispatch_next_step(
        current_step="cluster_analysis",
        job_id=job_id,
        tenant_id=tenant_id,
        dataset_id=dataset_id,
        output_s3_path=None,  # KU generation reads from RDS
        pipeline_config=kwargs.get("pipeline_config", {}),
    )

    if next_step is None:
        update_job_status(job_id, status="completed", progress=100)
