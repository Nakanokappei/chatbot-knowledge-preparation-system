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
    """Check for queued pipeline jobs in the workspace and dispatch the first one."""
    from src.db import db_cursor

    with db_cursor(tenant_id=tenant_id) as cur:
        cur.execute("""
            SELECT id, workspace_id, dataset_id
            FROM pipeline_jobs
            WHERE workspace_id = %s AND status = 'queued'
            ORDER BY created_at ASC
            LIMIT 1
        """, (tenant_id,))
        row = cur.fetchone()

    if not row:
        return

    job_id, workspace_id, dataset_id = row

    # Read pipeline config from the job
    with db_cursor(tenant_id=tenant_id) as cur:
        cur.execute("SELECT pipeline_config_snapshot_json FROM pipeline_jobs WHERE id = %s", (job_id,))
        config_row = cur.fetchone()

    if not config_row or not config_row[0]:
        return

    pipeline_config = config_row[0] if isinstance(config_row[0], dict) else json.loads(config_row[0])

    # Update status to submitted
    from src.db import update_job_status
    update_job_status(job_id, status="submitted")

    # Send first step to SQS
    message = {
        "job_id": job_id,
        "workspace_id": workspace_id,
        "dataset_id": dataset_id,
        "step": "preprocess",
        "input_s3_path": None,
        "pipeline_config": pipeline_config,
    }

    sqs = boto3.client("sqs", region_name=SQS_REGION)
    sqs.send_message(QueueUrl=SQS_QUEUE_URL, MessageBody=json.dumps(message))
    logger.info("Dispatched queued job %d for workspace %d", job_id, workspace_id)
