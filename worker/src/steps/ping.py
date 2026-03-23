"""
Ping step — Phase 0 integration test.

This step verifies the end-to-end communication path:
  Laravel -> SQS -> Python Worker -> RDS update

It performs no actual data processing; it simply updates the job
status to 'completed' to confirm the pipeline infrastructure works.
"""

import logging
from datetime import datetime, timezone

from src.db import update_job_status, update_job_step_outputs

logger = logging.getLogger(__name__)


def execute(job_id: int, tenant_id: int, **kwargs):
    """
    Execute the ping step.

    Proves that the Python Worker can:
    1. Receive a message from SQS
    2. Connect to RDS
    3. Update the pipeline_jobs table
    """
    logger.info("Ping step started for job %d (tenant %d)", job_id, tenant_id)

    # Update status to show we are processing
    update_job_status(job_id, status="validating", progress=50)

    # Record step output metadata
    update_job_step_outputs(job_id, "ping", {
        "message": "Ping successful. Worker is operational.",
        "timestamp": datetime.now(timezone.utc).isoformat(),
        "worker_version": "0.1.0",
    })

    # Mark the job as completed
    update_job_status(job_id, status="completed", progress=100)

    logger.info("Ping step completed for job %d", job_id)
