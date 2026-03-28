"""
Main entry point for the Python Worker.

Polls SQS for pipeline step messages and dispatches to the appropriate
step handler. Follows ADR-0005: SQS + DB Polling pattern.

In production, this runs inside an ECS Fargate container.
For local development, run directly: python -m src.main
"""

import json
import logging
import signal
import sys
import time

import boto3

from src.config import LOG_LEVEL, SQS_POLL_INTERVAL, SQS_QUEUE_URL, SQS_REGION
from src.db import clear_tenant_context, set_tenant_context, update_job_status
from src.steps import ping
from src.steps import preprocess
from src.steps import embedding
from src.steps import clustering
from src.steps import cluster_analysis
from src.steps import knowledge_unit_generation

# Configure logging
logging.basicConfig(
    level=getattr(logging, LOG_LEVEL),
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
)
logger = logging.getLogger("worker")

# Step registry: maps step names to handler modules
STEP_HANDLERS = {
    "ping": ping,
    "preprocess": preprocess,
    "embedding": embedding,
    "clustering": clustering,
    "cluster_analysis": cluster_analysis,
    "knowledge_unit_generation": knowledge_unit_generation,
}

# Graceful shutdown flag
shutdown_requested = False


def signal_handler(signum, frame):
    """Handle SIGTERM/SIGINT for graceful shutdown in Fargate."""
    global shutdown_requested
    logger.info("Shutdown signal received (signal %d). Finishing current work...", signum)
    shutdown_requested = True


def process_message(message_body: dict):
    """
    Dispatch a single SQS message to the appropriate step handler.

    Expected message format:
    {
        "job_id": 1,
        "workspace_id": 1,
        "dataset_id": 1,
        "step": "ping",
        "input_s3_path": null,
        "pipeline_config": {...}
    }
    """
    job_id = message_body.get("job_id")
    # Accept both workspace_id (current) and tenant_id (legacy) for compatibility
    tenant_id = message_body.get("workspace_id") or message_body.get("tenant_id")
    step = message_body.get("step")

    # Validate that all required message fields are present
    if not all([job_id, tenant_id, step]):
        logger.error("Invalid message: missing required fields. Body: %s", message_body)
        return

    # Look up the step handler module from the registry
    handler = STEP_HANDLERS.get(step)
    if handler is None:
        logger.error("Unknown step '%s' for job %d", step, job_id)
        update_job_status(job_id, status="failed", error_detail=f"Unknown step: {step}")
        return

    # Set tenant context for RLS enforcement on all DB connections
    set_tenant_context(tenant_id)

    try:
        logger.info("Processing step '%s' for job %d", step, job_id)
        handler.execute(
            job_id=job_id,
            tenant_id=tenant_id,
            dataset_id=message_body.get("dataset_id"),
            input_s3_path=message_body.get("input_s3_path"),
            pipeline_config=message_body.get("pipeline_config"),
        )
    except Exception as exc:
        logger.exception("Step '%s' failed for job %d: %s", step, job_id, exc)
        update_job_status(job_id, status="failed", error_detail=str(exc))
    finally:
        clear_tenant_context()


def poll_sqs():
    """
    Main polling loop. Receives messages from SQS and processes them.

    Long polling (WaitTimeSeconds=20) reduces empty responses and cost.
    """
    sqs = boto3.client("sqs", region_name=SQS_REGION)

    logger.info("Worker started. Polling SQS queue: %s", SQS_QUEUE_URL)

    # Main loop: continuously poll SQS until shutdown is requested
    while not shutdown_requested:
        try:
            response = sqs.receive_message(
                QueueUrl=SQS_QUEUE_URL,
                MaxNumberOfMessages=1,
                WaitTimeSeconds=20,  # long polling
                VisibilityTimeout=300,  # 5 minutes to process
            )

            messages = response.get("Messages", [])
            # Skip empty poll results (normal with long polling)
            if not messages:
                continue

            # Process each received message and delete from queue on success
            for message in messages:
                body = json.loads(message["Body"])
                process_message(body)

                # Delete the message after successful processing
                sqs.delete_message(
                    QueueUrl=SQS_QUEUE_URL,
                    ReceiptHandle=message["ReceiptHandle"],
                )

        except KeyboardInterrupt:
            logger.info("KeyboardInterrupt received. Shutting down.")
            break
        except Exception as exc:
            logger.exception("Error in polling loop: %s", exc)
            time.sleep(SQS_POLL_INTERVAL)

    logger.info("Worker shut down gracefully.")


def run_local(message_body: dict):
    """
    Run a single step locally without SQS, for development and testing.

    Usage: python -m src.main --local '{"job_id":1,"workspace_id":1,"step":"ping"}'
    """
    logger.info("Running in local mode (no SQS)")
    process_message(message_body)


if __name__ == "__main__":
    # Register signal handlers for graceful shutdown
    signal.signal(signal.SIGTERM, signal_handler)
    signal.signal(signal.SIGINT, signal_handler)

    # Choose between local single-run mode and production SQS polling
    if len(sys.argv) > 1 and sys.argv[1] == "--local":
        # Local mode: process a single message from command line argument
        if len(sys.argv) < 3:
            print("Usage: python -m src.main --local '{\"job_id\":1,\"tenant_id\":1,\"step\":\"ping\"}'")
            sys.exit(1)
        message = json.loads(sys.argv[2])
        run_local(message)
    else:
        # Production mode: poll SQS
        if not SQS_QUEUE_URL:
            logger.error("SQS_QUEUE_URL is not set. Cannot start polling.")
            sys.exit(1)
        poll_sqs()
