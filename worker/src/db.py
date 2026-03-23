"""
Database connection module.

Provides a connection pool to RDS PostgreSQL.
The worker reads from and writes to the same database as Laravel,
updating job status and storing pipeline results directly.
This follows ADR-0005: SQS + DB Polling communication pattern.
"""

import json
import logging
from datetime import datetime, timezone

import psycopg2
import psycopg2.extras

from src.config import DB_HOST, DB_NAME, DB_PASSWORD, DB_PORT, DB_USER

logger = logging.getLogger(__name__)


def get_connection():
    """Create a new database connection."""
    return psycopg2.connect(
        host=DB_HOST,
        port=DB_PORT,
        dbname=DB_NAME,
        user=DB_USER,
        password=DB_PASSWORD,
    )


def update_job_status(job_id: int, status: str, progress: int = 0, error_detail: str = None):
    """
    Update the pipeline_jobs table with new status and progress.

    This is the primary mechanism by which the Python Worker communicates
    completion back to the Laravel Control Plane (Design Principle: DB Polling).
    """
    conn = get_connection()
    try:
        with conn.cursor() as cur:
            now = datetime.now(timezone.utc)
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
            conn.commit()
            logger.info("Job %d status updated to '%s' (progress: %d%%)", job_id, status, progress)
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()


def update_job_step_outputs(job_id: int, step_name: str, step_data: dict):
    """
    Merge step output metadata into the job's step_outputs_json column.

    This stores per-step results (S3 paths, counts, metrics) for debugging
    and audit purposes.
    """
    conn = get_connection()
    try:
        with conn.cursor() as cur:
            # Read current step_outputs_json
            cur.execute(
                "SELECT step_outputs_json FROM pipeline_jobs WHERE id = %s", (job_id,)
            )
            row = cur.fetchone()
            raw = row[0] if row else None
            if raw is None:
                current_outputs = {}
            elif isinstance(raw, dict):
                current_outputs = raw  # Already parsed by psycopg2 (jsonb column)
            elif isinstance(raw, str):
                current_outputs = json.loads(raw)
            else:
                current_outputs = {}

            # Merge the new step data
            current_outputs[step_name] = step_data

            # Write back
            now = datetime.now(timezone.utc)
            cur.execute(
                """UPDATE pipeline_jobs
                   SET step_outputs_json = %s, updated_at = %s
                   WHERE id = %s""",
                (json.dumps(current_outputs), now, job_id),
            )
            conn.commit()
            logger.info("Job %d step_outputs updated for step '%s'", job_id, step_name)
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()
