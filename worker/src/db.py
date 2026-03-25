"""
Database connection module.

Provides a connection pool to RDS PostgreSQL.
The worker reads from and writes to the same database as Laravel,
updating job status and storing pipeline results directly.
This follows ADR-0005: SQS + DB Polling communication pattern.
"""

import json
import logging
import threading
from contextlib import contextmanager
from datetime import datetime, timezone

import psycopg2
import psycopg2.extras

from src.config import DB_HOST, DB_NAME, DB_PASSWORD, DB_PORT, DB_USER

logger = logging.getLogger(__name__)

# Thread-local storage for the current tenant context.
# Set via set_tenant_context() before processing each job.
# All get_connection() calls within that thread will inherit this tenant_id.
_thread_local = threading.local()


def set_tenant_context(tenant_id: int):
    """Set the tenant_id for the current thread. Called once per job."""
    _thread_local.tenant_id = tenant_id


def clear_tenant_context():
    """Clear the tenant context after job processing."""
    _thread_local.tenant_id = None


def get_connection(tenant_id: int = None):
    """
    Create a new database connection with RLS tenant context.

    Tenant ID resolution order:
    1. Explicit tenant_id parameter
    2. Thread-local tenant context (set via set_tenant_context)
    3. None (no RLS context — only safe for superuser/owner connections)
    """
    conn = psycopg2.connect(
        host=DB_HOST,
        port=DB_PORT,
        dbname=DB_NAME,
        user=DB_USER,
        password=DB_PASSWORD,
    )

    # Resolve tenant_id from parameter or thread-local context
    resolved_tenant = tenant_id or getattr(_thread_local, "tenant_id", None)
    if resolved_tenant is not None:
        with conn.cursor() as cur:
            cur.execute("SET app.tenant_id = %s", (str(resolved_tenant),))
        conn.commit()

    return conn


@contextmanager
def db_cursor(tenant_id: int = None, autocommit: bool = True):
    """
    Context manager for database operations. Eliminates repeated
    try/except/finally patterns across the codebase.

    Usage:
        with db_cursor() as cur:
            cur.execute("SELECT ...")

    Commits on success, rolls back on exception, always closes connection.
    """
    conn = get_connection(tenant_id)
    try:
        with conn.cursor() as cur:
            yield cur
        if autocommit:
            conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()


def update_job_status(job_id: int, status: str, progress: int = 0, error_detail: str = None):
    """
    Update the pipeline_jobs table with new status and progress.

    This is the primary mechanism by which the Python Worker communicates
    completion back to the Laravel Control Plane (Design Principle: DB Polling).
    """
    now = datetime.now(timezone.utc)
    with db_cursor() as cur:
        # Branch on status to set the appropriate timestamp columns
        if status == "completed":
            cur.execute(
                """UPDATE pipeline_jobs
                   SET status = %s, progress = %s, error_detail = %s,
                       completed_at = %s, updated_at = %s
                   WHERE id = %s""",
                (status, progress, error_detail, now, now, job_id),
            )
        elif status == "failed":
            cur.execute(
                """UPDATE pipeline_jobs
                   SET status = %s, progress = %s, error_detail = %s,
                       updated_at = %s
                   WHERE id = %s""",
                (status, progress, error_detail, now, job_id),
            )
        else:
            cur.execute(
                """UPDATE pipeline_jobs
                   SET status = %s, progress = %s, started_at = COALESCE(started_at, %s),
                       updated_at = %s
                   WHERE id = %s""",
                (status, progress, now, now, job_id),
            )
    logger.info("Job %d status updated to '%s' (progress: %d%%)", job_id, status, progress)


def create_or_get_embedding(job_id: int, tenant_id: int, dataset_id: int,
                            name: str, column_config: list = None,
                            embedding_model: str = "amazon.titan-embed-text-v2:0") -> int:
    """
    Create an embedding record for a pipeline job, or return existing one.

    Called at the start of the preprocess step. The embedding record links
    the dataset, column configuration, and resulting KUs together.
    Returns the embedding_id.
    """
    with db_cursor(tenant_id=tenant_id) as cur:
        # Check if job already has an embedding_id
        cur.execute("SELECT embedding_id FROM pipeline_jobs WHERE id = %s", (job_id,))
        row = cur.fetchone()
        if row and row[0]:
            logger.info("Job %d already has embedding_id=%d", job_id, row[0])
            return row[0]

        # Create new embedding record
        now = datetime.now(timezone.utc)
        cur.execute(
            """INSERT INTO embeddings
               (tenant_id, dataset_id, name, column_config_json,
                embedding_model, status, row_count, created_at, updated_at)
               VALUES (%s, %s, %s, %s, %s, 'processing', 0, %s, %s)
               RETURNING id""",
            (tenant_id, dataset_id, name,
             json.dumps(column_config) if column_config else None,
             embedding_model, now, now),
        )
        embedding_id = cur.fetchone()[0]

        # Link job to embedding
        cur.execute(
            "UPDATE pipeline_jobs SET embedding_id = %s WHERE id = %s",
            (embedding_id, job_id),
        )
        logger.info("Created embedding %d for job %d", embedding_id, job_id)
        return embedding_id


def update_embedding_status(embedding_id: int, status: str, row_count: int = None):
    """Update an embedding record's status and optionally row_count."""
    now = datetime.now(timezone.utc)
    with db_cursor() as cur:
        if row_count is not None:
            cur.execute(
                "UPDATE embeddings SET status = %s, row_count = %s, updated_at = %s WHERE id = %s",
                (status, row_count, now, embedding_id),
            )
        else:
            cur.execute(
                "UPDATE embeddings SET status = %s, updated_at = %s WHERE id = %s",
                (status, now, embedding_id),
            )


def record_token_usage(
    tenant_id: int,
    endpoint: str,
    model_id: str,
    input_tokens: int,
    output_tokens: int,
):
    """
    Record token usage for cost tracking.

    Writes to token_usage (per-request) and upserts daily_cost_summary
    (daily aggregate). Mirrors the PHP CostTrackingService logic so both
    worker and web pipelines feed the same cost dashboard.
    """
    try:
        with db_cursor() as cur:
            # Look up per-token pricing from the llm_models reference table

            cur.execute(
                "SELECT input_price_per_1m, output_price_per_1m FROM llm_models WHERE model_id = %s LIMIT 1",
                (model_id,),
            )
            row = cur.fetchone()
            input_price = float(row[0]) if row and row[0] else 1.0
            output_price = float(row[1]) if row and row[1] else 5.0

            cost = (input_tokens * input_price / 1_000_000) + (output_tokens * output_price / 1_000_000)
            now = datetime.now(timezone.utc)
            today = now.strftime("%Y-%m-%d")

            # Insert per-request record
            cur.execute(
                """INSERT INTO token_usage
                   (tenant_id, user_id, endpoint, model_id, input_tokens, output_tokens, estimated_cost, created_at)
                   VALUES (%s, NULL, %s, %s, %s, %s, %s, %s)""",
                (tenant_id, endpoint, model_id, input_tokens, output_tokens, cost, now),
            )

            # Determine cost column and upsert daily summary
            cost_col = "chat_cost" if "chat" in endpoint else (
                "embedding_cost" if ("embed" in endpoint or "retrieve" in endpoint) else "pipeline_cost"
            )
            cur.execute(
                f"""INSERT INTO daily_cost_summary
                    (tenant_id, date, {cost_col}, total_cost, total_tokens, request_count, created_at, updated_at)
                    VALUES (%s, %s, %s, %s, %s, 1, %s, %s)
                    ON CONFLICT (tenant_id, date)
                    DO UPDATE SET
                        {cost_col} = daily_cost_summary.{cost_col} + EXCLUDED.{cost_col},
                        total_cost = daily_cost_summary.total_cost + EXCLUDED.total_cost,
                        total_tokens = daily_cost_summary.total_tokens + EXCLUDED.total_tokens,
                        request_count = daily_cost_summary.request_count + 1,
                        updated_at = EXCLUDED.updated_at""",
                (tenant_id, today, cost, cost, input_tokens + output_tokens, now, now),
            )
        logger.info(
            "Recorded token usage: endpoint=%s model=%s in=%d out=%d cost=$%.6f",
            endpoint, model_id, input_tokens, output_tokens, cost,
        )
    except Exception as e:
        logger.warning("Failed to record token usage: %s", e)


def link_clusters_to_embedding(job_id: int, embedding_id: int):
    """Set embedding_id on all clusters belonging to a job."""
    with db_cursor() as cur:
        cur.execute(
            "UPDATE clusters SET embedding_id = %s WHERE pipeline_job_id = %s",
            (embedding_id, job_id),
        )


def link_knowledge_units_to_embedding(job_id: int, embedding_id: int):
    """Set embedding_id on all KUs belonging to a job."""
    with db_cursor() as cur:
        cur.execute(
            "UPDATE knowledge_units SET embedding_id = %s WHERE pipeline_job_id = %s",
            (embedding_id, job_id),
        )


def update_job_step_outputs(job_id: int, step_name: str, step_data: dict):
    """
    Merge step output metadata into the job's step_outputs_json column.

    This stores per-step results (S3 paths, counts, metrics) for debugging
    and audit purposes.
    """
    with db_cursor() as cur:
        # Read current step_outputs_json
        cur.execute(
            "SELECT step_outputs_json FROM pipeline_jobs WHERE id = %s", (job_id,)
        )
        row = cur.fetchone()
        # Handle both dict (psycopg2 json) and str (raw text) column types
        raw = row[0] if row else None
        current_outputs = (
            raw if isinstance(raw, dict)
            else json.loads(raw) if isinstance(raw, str)
            else {}
        ) if raw is not None else {}

        # Merge and write back
        current_outputs[step_name] = step_data
        now = datetime.now(timezone.utc)
        cur.execute(
            "UPDATE pipeline_jobs SET step_outputs_json = %s, updated_at = %s WHERE id = %s",
            (json.dumps(current_outputs), now, job_id),
        )
    logger.info("Job %d step_outputs updated for step '%s'", job_id, step_name)
