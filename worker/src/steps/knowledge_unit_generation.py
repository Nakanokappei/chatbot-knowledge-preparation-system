"""
Knowledge Unit generation step — converts analyzed clusters into Knowledge Units.

For each cluster with completed analysis (topic_name, intent, summary),
this step creates a Knowledge Unit record and its initial version snapshot.

CTO directive: "This cluster will become a Knowledge Unit."
CTO directive: review_status lifecycle: draft -> reviewed -> approved -> rejected

Input:  Completed cluster_analysis results in RDS (clusters with topic_name, intent, summary)
Output: knowledge_units table populated, knowledge_unit_versions v1 snapshots created
        Job status set to 'completed'
"""

import json
import logging
from datetime import datetime, timezone

from src.db import (get_connection, update_job_status, update_job_step_outputs,
                    link_knowledge_units_to_embedding, update_embedding_status)

logger = logging.getLogger(__name__)


def load_analyzed_clusters(job_id: int) -> list[dict]:
    """
    Load clusters that have completed LLM analysis, along with their
    representative texts and centroid vectors.
    """
    conn = get_connection()
    try:
        with conn.cursor() as cur:
            # Fetch clusters with analysis results
            cur.execute(
                """SELECT c.id, c.cluster_label, c.topic_name, c.intent,
                          c.summary, c.row_count, c.tenant_id
                   FROM clusters c
                   WHERE c.pipeline_job_id = %s
                     AND c.topic_name IS NOT NULL
                   ORDER BY c.cluster_label""",
                (job_id,),
            )
            clusters = []
            for row in cur.fetchall():
                clusters.append({
                    "id": row[0],
                    "cluster_label": row[1],
                    "topic_name": row[2],
                    "intent": row[3],
                    "summary": row[4],
                    "row_count": row[5],
                    "tenant_id": row[6],
                })

            # Load representative texts for each cluster
            for cluster in clusters:
                cur.execute(
                    """SELECT cr.dataset_row_id, dr.raw_text
                       FROM cluster_representatives cr
                       JOIN dataset_rows dr ON cr.dataset_row_id = dr.id
                       WHERE cr.cluster_id = %s
                       ORDER BY cr.rank
                       LIMIT 10""",
                    (cluster["id"],),
                )
                cluster["representative_rows"] = [
                    {"row_id": r[0], "text": r[1]} for r in cur.fetchall()
                ]

            # Load keywords from cluster_analysis_logs for each cluster
            for cluster in clusters:
                cur.execute(
                    """SELECT response_json
                       FROM cluster_analysis_logs
                       WHERE cluster_id = %s AND pipeline_job_id = %s
                       ORDER BY created_at DESC LIMIT 1""",
                    (cluster["id"], job_id),
                )
                log_row = cur.fetchone()
                if log_row and log_row[0]:
                    analysis = json.loads(log_row[0]) if isinstance(log_row[0], str) else log_row[0]
                    cluster["keywords"] = analysis.get("keywords", [])
                    cluster["language"] = analysis.get("language", "en")
                else:
                    cluster["keywords"] = []
                    cluster["language"] = "en"

            # Load centroid vectors for each cluster
            for cluster in clusters:
                cur.execute(
                    """SELECT centroid_vector
                       FROM cluster_centroids
                       WHERE cluster_id = %s""",
                    (cluster["id"],),
                )
                centroid_row = cur.fetchone()
                cluster["centroid_vector"] = centroid_row[0] if centroid_row else None

            return clusters
    finally:
        conn.close()


def create_knowledge_unit(
    cluster: dict,
    job_id: int,
    dataset_id: int,
    pipeline_config: dict,
) -> int:
    """
    Insert a Knowledge Unit record and its initial version snapshot.

    Returns the new knowledge_unit id.
    """
    now = datetime.now(timezone.utc)
    conn = get_connection()
    try:
        with conn.cursor() as cur:
            # Build source references for traceability
            source_refs = {
                "cluster_label": cluster["cluster_label"],
                "representative_row_ids": [
                    r["row_id"] for r in cluster["representative_rows"]
                ],
            }

            # Build the typical cases list from representative texts
            typical_cases = [
                r["text"][:300] for r in cluster["representative_rows"][:5]
            ]

            # Insert the Knowledge Unit
            cur.execute(
                """INSERT INTO knowledge_units
                   (tenant_id, dataset_id, pipeline_job_id, cluster_id,
                    topic, intent, summary, typical_cases_json,
                    cause_summary, resolution_summary,
                    representative_rows_json, keywords_json,
                    row_count, confidence, review_status,
                    source_refs_json, pipeline_config_version, prompt_version,
                    version, embedding, created_at, updated_at)
                   VALUES (%s, %s, %s, %s,
                           %s, %s, %s, %s,
                           %s, %s,
                           %s, %s,
                           %s, %s, %s,
                           %s, %s, %s,
                           %s, %s, %s, %s)
                   RETURNING id""",
                (
                    cluster["tenant_id"], dataset_id, job_id, cluster["id"],
                    cluster["topic_name"], cluster["intent"], cluster["summary"],
                    json.dumps(typical_cases),
                    "",  # cause_summary — to be filled during review
                    "",  # resolution_summary — to be filled during review
                    json.dumps(cluster["representative_rows"]),
                    json.dumps(cluster["keywords"]),
                    cluster["row_count"],
                    0.0,  # confidence — to be scored later
                    "draft",
                    json.dumps(source_refs),
                    pipeline_config.get("phase", "2"),
                    "cluster_analysis_v1",
                    1,  # version
                    cluster["centroid_vector"],
                    now, now,
                ),
            )
            ku_id = cur.fetchone()[0]

            # Create the initial version snapshot (v1)
            snapshot = {
                "topic": cluster["topic_name"],
                "intent": cluster["intent"],
                "summary": cluster["summary"],
                "keywords": cluster["keywords"],
                "row_count": cluster["row_count"],
                "representative_rows": cluster["representative_rows"],
                "review_status": "draft",
            }

            cur.execute(
                """INSERT INTO knowledge_unit_versions
                   (knowledge_unit_id, version, snapshot_json, created_at)
                   VALUES (%s, %s, %s, %s)""",
                (ku_id, 1, json.dumps(snapshot), now),
            )

            conn.commit()
            return ku_id

    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()


def execute(job_id: int, tenant_id: int, dataset_id: int = None, **kwargs):
    """
    Execute the knowledge unit generation step.

    For each analyzed cluster:
    1. Load cluster analysis results (topic, intent, summary, keywords)
    2. Load representative rows and centroid vector
    3. Create knowledge_unit record with review_status='draft'
    4. Create knowledge_unit_versions v1 snapshot
    5. Set job status to 'completed'
    """
    logger.info("Knowledge unit generation started for job %d", job_id)
    update_job_status(job_id, status="knowledge_unit_generation", progress=10)

    pipeline_config = kwargs.get("pipeline_config") or {}

    # Step 1: Load analyzed clusters
    clusters = load_analyzed_clusters(job_id)
    logger.info("Loaded %d analyzed clusters for KU generation", len(clusters))

    if not clusters:
        update_job_status(
            job_id, status="failed",
            error_detail="No analyzed clusters found for KU generation",
        )
        return

    # Step 2: Create Knowledge Units
    ku_ids = []
    for i, cluster in enumerate(clusters):
        progress = 10 + int((i / len(clusters)) * 80)
        update_job_status(job_id, status="knowledge_unit_generation", progress=progress)

        ku_id = create_knowledge_unit(cluster, job_id, dataset_id, pipeline_config)
        ku_ids.append(ku_id)

        logger.info(
            "Created KU #%d from cluster %d (topic='%s', rows=%d)",
            ku_id, cluster["cluster_label"],
            cluster["topic_name"], cluster["row_count"],
        )

    # Step 3: Link KUs to embedding and mark embedding as ready
    pipeline_config = kwargs.get("pipeline_config") or {}
    embedding_id = pipeline_config.get("embedding_id")
    if embedding_id:
        link_knowledge_units_to_embedding(job_id, embedding_id)
        update_embedding_status(embedding_id, "ready", row_count=len(ku_ids))

    # Step 4: Record step metadata
    update_job_step_outputs(job_id, "knowledge_unit_generation", {
        "knowledge_units_created": len(ku_ids),
        "knowledge_unit_ids": ku_ids,
    })

    # Step 5: Mark job as completed (this is the final step)
    update_job_status(job_id, status="completed", progress=100)

    logger.info(
        "Knowledge unit generation completed for job %d: %d KUs created",
        job_id, len(ku_ids),
    )
