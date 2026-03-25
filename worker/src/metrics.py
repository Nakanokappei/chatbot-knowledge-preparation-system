"""
CloudWatch metrics for Python Worker pipeline steps.

Emits step duration, error counts, and embedding metrics.
Failures in metric publishing are logged but never block pipeline execution.
"""

import logging
import time
from functools import wraps

import boto3
from botocore.exceptions import ClientError

from src.config import SQS_REGION

logger = logging.getLogger(__name__)

NAMESPACE = "CKPS"

# Lazy-initialized CloudWatch client
_cw_client = None


def _get_client():
    """Get or create the CloudWatch client."""
    global _cw_client
    if _cw_client is None:
        _cw_client = boto3.client("cloudwatch", region_name=SQS_REGION)
    return _cw_client


def put_metric(metric_name, value, unit="None", dimensions=None):
    """Publish a single metric to CloudWatch."""
    try:
        client = _get_client()
        metric_data = {
            "MetricName": metric_name,
            "Value": value,
            "Unit": unit,
        }
        # Attach optional key-value dimensions for metric filtering
        if dimensions:
            metric_data["Dimensions"] = [
                {"Name": k, "Value": str(v)} for k, v in dimensions.items()
            ]

        client.put_metric_data(
            Namespace=NAMESPACE,
            MetricData=[metric_data],
        )
    except ClientError as e:
        logger.warning(f"CloudWatch metric publish failed: {e}")
    except Exception as e:
        logger.warning(f"Unexpected metrics error: {e}")


def record_step_duration(step_name, tenant_id, duration_seconds):
    """Record pipeline step execution duration."""
    put_metric(
        "PipelineStepDuration",
        duration_seconds * 1000,  # Convert to milliseconds
        unit="Milliseconds",
        dimensions={"step": step_name, "tenant_id": tenant_id},
    )


def record_step_error(step_name, error_type):
    """Record a pipeline step error."""
    put_metric(
        "PipelineStepErrors",
        1,
        unit="Count",
        dimensions={"step": step_name, "error_type": error_type},
    )


def record_embedding_latency(tenant_id, latency_ms):
    """Record embedding generation latency."""
    put_metric(
        "EmbeddingLatency",
        latency_ms,
        unit="Milliseconds",
        dimensions={"tenant_id": tenant_id},
    )


def timed_step(step_name):
    """Decorator to automatically time and record pipeline step execution."""
    def decorator(func):
        @wraps(func)
        def wrapper(*args, **kwargs):
            start = time.time()
            tenant_id = "unknown"

            # Try to extract tenant_id from kwargs or positional message dict
            if "message" in kwargs:
                tenant_id = str(kwargs["message"].get("tenant_id", "unknown"))
            elif args and isinstance(args[0], dict):
                tenant_id = str(args[0].get("tenant_id", "unknown"))

            try:
                result = func(*args, **kwargs)
                # Record duration on success
                duration = time.time() - start
                record_step_duration(step_name, tenant_id, duration)
                return result
            except Exception as e:
                # Record both duration and error on failure before re-raising
                duration = time.time() - start
                record_step_duration(step_name, tenant_id, duration)
                record_step_error(step_name, type(e).__name__)
                raise

        return wrapper
    return decorator
