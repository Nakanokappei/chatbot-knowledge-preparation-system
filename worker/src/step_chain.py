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
