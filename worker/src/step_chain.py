"""
Step chain module — automatic pipeline step sequencing.

After each step completes, this module dispatches the next step in the
pipeline sequence to SQS. This creates an automatic chain:
  preprocess -> embedding -> clustering -> (complete)

Follows the 4 Plane Architecture:
  Control Plane (Laravel) dispatches the first step.
  Data Plane (Python Worker) chains subsequent steps via Queue Plane (SQS).
"""

import json
import logging

import boto3

from src.config import SQS_QUEUE_URL, SQS_REGION

logger = logging.getLogger(__name__)

# Pipeline step execution order
STEP_SEQUENCE = ["preprocess", "embedding", "clustering", "cluster_analysis", "knowledge_unit_generation"]


def dispatch_next_step(
    current_step: str,
    job_id: int,
    tenant_id: int,
    dataset_id: int,
    output_s3_path: str,
    pipeline_config: dict,
) -> str | None:
    """
    Send the next pipeline step to SQS after the current step completes.

    Returns the name of the next step, or None if the pipeline is complete.
    """
    # Locate the current step in the sequence to determine the next one
    try:
        idx = STEP_SEQUENCE.index(current_step)
    except ValueError:
        logger.warning("Step '%s' not in STEP_SEQUENCE; no chaining.", current_step)
        return None

    # Check if there is a next step
    if idx + 1 >= len(STEP_SEQUENCE):
        logger.info("Pipeline complete for job %d (last step: %s)", job_id, current_step)
        dispatch_queued_job(tenant_id)
        return None

    next_step = STEP_SEQUENCE[idx + 1]

    # Build the message for the next step
    message = {
        "job_id": job_id,
        "tenant_id": tenant_id,
        "dataset_id": dataset_id,
        "step": next_step,
        "input_s3_path": output_s3_path,
        "pipeline_config": pipeline_config,
    }

    # Dispatch to SQS
    sqs = boto3.client("sqs", region_name=SQS_REGION)
    result = sqs.send_message(
        QueueUrl=SQS_QUEUE_URL,
        MessageBody=json.dumps(message),
    )

    logger.info(
        "Chained step '%s' -> '%s' for job %d (MessageId: %s)",
        current_step,
        next_step,
        job_id,
        result["MessageId"],
    )

    return next_step


def dispatch_queued_job(tenant_id: int):
    """
    Check for queued pipeline jobs in the workspace and dispatch the first one.

    Supports two dispatch modes based on start_step:
      - 'preprocess' (default): full pipeline from the beginning
      - 'clustering': skip preprocess+embedding, reuse vectors from source_job_id
    """
    from src.db import db_cursor

    with db_cursor(tenant_id=tenant_id) as cur:
        cur.execute("""
            SELECT id, workspace_id, dataset_id, start_step, source_job_id
            FROM pipeline_jobs
            WHERE workspace_id = %s AND status = 'queued'
            ORDER BY created_at ASC
            LIMIT 1
        """, (tenant_id,))
        row = cur.fetchone()

    if not row:
        return

    job_id, workspace_id, dataset_id, start_step, source_job_id = row
    start_step = start_step or "preprocess"

    # Read pipeline config from the queued job
    with db_cursor(tenant_id=tenant_id) as cur:
        cur.execute("SELECT pipeline_config_snapshot_json FROM pipeline_jobs WHERE id = %s", (job_id,))
        config_row = cur.fetchone()

    if not config_row or not config_row[0]:
        return

    pipeline_config = config_row[0] if isinstance(config_row[0], dict) else json.loads(config_row[0])

    # Determine the starting step and input path
    step = start_step
    input_s3_path = None

    if start_step != "preprocess" and source_job_id:
        # Clustering-only job: resolve embedding output from the source job
        input_s3_path, embedding_id = _resolve_source_embedding(source_job_id, tenant_id)
        if not input_s3_path:
            # Source job has no embedding output — fail gracefully
            from src.db import update_job_status
            update_job_status(job_id, status="failed", error_detail="Source job has no embedding output")
            logger.error("Queued job %d: source job %d has no embedding output", job_id, source_job_id)
            # Try the next queued job
            dispatch_queued_job(tenant_id)
            return

        # Inject the embedding_id so the clustering step links clusters correctly
        if embedding_id:
            pipeline_config["embedding_id"] = embedding_id

            # Also update the job's own embedding_id column so the sidebar
            # displays this job as a child of the correct embedding.
            with db_cursor(tenant_id=tenant_id) as cur:
                cur.execute(
                    "UPDATE pipeline_jobs SET embedding_id = %s WHERE id = %s",
                    (embedding_id, job_id),
                )

    # Update status to submitted
    from src.db import update_job_status
    update_job_status(job_id, status="submitted")

    # Dispatch to SQS
    message = {
        "job_id": job_id,
        "workspace_id": workspace_id,
        "dataset_id": dataset_id,
        "step": step,
        "input_s3_path": input_s3_path,
        "pipeline_config": pipeline_config,
    }

    sqs = boto3.client("sqs", region_name=SQS_REGION)
    sqs.send_message(QueueUrl=SQS_QUEUE_URL, MessageBody=json.dumps(message))
    logger.info(
        "Dispatched queued job %d (start_step=%s) for workspace %d",
        job_id, step, workspace_id,
    )


def _resolve_source_embedding(source_job_id: int, tenant_id: int) -> tuple:
    """
    Look up the embedding output S3 path and embedding_id from a source job.

    If the source job is itself a clustering-only job (no embedding output),
    walks the source_job_id chain up to 5 levels to find the original
    full-pipeline job that produced the embedding vectors.

    Returns (output_s3_path, embedding_id) or (None, None) if not found.
    """
    from src.db import db_cursor

    current_id = source_job_id

    # Walk the source_job_id chain (max 5 hops to prevent infinite loops)
    for _ in range(5):
        if current_id is None:
            return None, None

        with db_cursor(tenant_id=tenant_id) as cur:
            cur.execute(
                "SELECT step_outputs_json, embedding_id, source_job_id "
                "FROM pipeline_jobs WHERE id = %s",
                (current_id,),
            )
            row = cur.fetchone()

        if not row or not row[0]:
            return None, None

        step_outputs = row[0] if isinstance(row[0], dict) else json.loads(row[0])
        embedding_id = row[1]
        parent_source_id = row[2]
        embedding_data = step_outputs.get("embedding", {})
        output_s3_path = embedding_data.get("output_s3_path")

        # Found the embedding output — return it
        if output_s3_path:
            return output_s3_path, embedding_id

        # No embedding output in this job — follow the chain upward
        logger.info(
            "Job %d has no embedding output, following source_job_id=%s",
            current_id, parent_source_id,
        )
        current_id = parent_source_id

    logger.error("Could not resolve embedding output after 5 hops from job %d", source_job_id)
    return None, None
