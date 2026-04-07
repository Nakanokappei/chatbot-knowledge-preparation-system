"""
Preprocess step — CSV parsing and text normalization.

This step reads raw dataset rows from RDS, normalizes the text content,
and saves the result as a Parquet file on S3 for the embedding step.

Input:  dataset_id (rows already in dataset_rows from CSV upload)
Output: s3://{bucket}/{tenant_id}/jobs/{job_id}/preprocess/normalized_rows.parquet
"""

import io
import logging
import re
import unicodedata

import boto3
import pandas as pd

from src.config import S3_BUCKET, S3_REGION
from src.db import get_connection, update_job_status, update_job_step_outputs, create_or_get_embedding, global_progress
from src.step_chain import dispatch_next_step

logger = logging.getLogger(__name__)


def normalize_text(text: str) -> str:
    """
    Apply text normalization rules for consistent embedding input.

    Steps:
    1. Unicode NFKC normalization (fullwidth -> halfwidth, etc.)
    2. Strip control characters (except newlines)
    3. Collapse multiple whitespace into single spaces
    4. Strip leading/trailing whitespace
    5. Remove placeholder tokens like {product_purchased}
    """
    # Guard against null or non-string input
    if not text or not isinstance(text, str):
        return ""

    # Unicode normalization: fullwidth -> halfwidth, compatibility decomposition
    text = unicodedata.normalize("NFKC", text)

    # Remove control characters except newlines and tabs
    text = re.sub(r"[\x00-\x08\x0b\x0c\x0e-\x1f\x7f-\x9f]", "", text)

    # Remove placeholder tokens like {product_purchased}
    text = re.sub(r"\{[^}]+\}", "", text)

    # Collapse multiple whitespace (including newlines) into single space
    text = re.sub(r"\s+", " ", text)

    # Strip leading and trailing whitespace
    text = text.strip()

    return text


def load_dataset_rows(dataset_id: int) -> pd.DataFrame:
    """
    Read all rows for a dataset from the dataset_rows table.

    Returns a DataFrame with columns: id, raw_text.
    """
    conn = get_connection()
    try:
        query = """
            SELECT id, raw_text
            FROM dataset_rows
            WHERE dataset_id = %s
            ORDER BY id
        """
        df = pd.read_sql(query, conn, params=(dataset_id,))
        logger.info("Loaded %d rows for dataset %s", len(df), dataset_id)
        return df
    finally:
        conn.close()


def save_normalized_text_to_db(rows: list[dict]):
    """
    Batch update normalized_text in the dataset_rows table.

    Each dict in rows must have 'id' and 'normalized_text' keys.
    """
    conn = get_connection()
    try:
        with conn.cursor() as cur:
            for row in rows:
                cur.execute(
                    "UPDATE dataset_rows SET normalized_text = %s WHERE id = %s",
                    (row["normalized_text"], row["id"]),
                )
            conn.commit()
        logger.info("Updated normalized_text for %d rows", len(rows))
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()


def upload_parquet_to_s3(df: pd.DataFrame, s3_path: str):
    """
    Serialize a DataFrame to Parquet format and upload to S3.
    """
    buffer = io.BytesIO()
    df.to_parquet(buffer, index=False, engine="pyarrow")
    buffer.seek(0)

    s3 = boto3.client("s3", region_name=S3_REGION)
    # Parse s3_path: remove "s3://bucket/" prefix to get the key
    key = s3_path.replace(f"s3://{S3_BUCKET}/", "")

    s3.put_object(Bucket=S3_BUCKET, Key=key, Body=buffer.getvalue())
    logger.info("Uploaded Parquet to %s (%d rows)", s3_path, len(df))


def execute(job_id: int, tenant_id: int, dataset_id: int = None, **kwargs):
    """
    Execute the preprocess step.

    1. Load dataset_rows from RDS
    2. Normalize text
    3. Update normalized_text in RDS
    4. Save normalized data as Parquet to S3
    5. Chain to the next step (embedding)
    """
    logger.info("Preprocess step started for job %d (dataset %d)", job_id, dataset_id)
    update_job_status(job_id, status="preprocess", progress=global_progress("preprocess", 10))

    # Step 0: Create or get embedding record for this job
    pipeline_config = kwargs.get("pipeline_config") or {}
    column_config = pipeline_config.get("column_config")
    # Use the dataset's actual name from DB, falling back to pipeline_config or a generic label.
    # This ensures embedding name matches the dataset name (UI treats them as one entity).
    dataset_name = None
    conn_name = get_connection()
    try:
        with conn_name.cursor() as cur:
            cur.execute("SELECT name FROM datasets WHERE id = %s", (dataset_id,))
            row_name = cur.fetchone()
            if row_name:
                dataset_name = row_name[0]
    finally:
        conn_name.close()
    if not dataset_name:
        dataset_name = pipeline_config.get("dataset_name", f"Embedding (job {job_id})")
    embedding_model = pipeline_config.get("embedding_model", "amazon.titan-embed-text-v2:0")
    embedding_id = create_or_get_embedding(
        job_id=job_id,
        tenant_id=tenant_id,
        dataset_id=dataset_id,
        name=dataset_name,
        column_config=column_config,
        embedding_model=embedding_model,
    )
    # Pass embedding_id through pipeline_config for downstream steps
    pipeline_config["embedding_id"] = embedding_id
    kwargs["pipeline_config"] = pipeline_config

    # Step 1: Load raw rows from database
    df = load_dataset_rows(dataset_id)

    # Guard: abort early if the dataset has no rows to process
    if df.empty:
        update_job_status(job_id, status="failed", error_detail="No rows found for dataset")
        return

    update_job_status(job_id, status="preprocess", progress=global_progress("preprocess", 30))

    # Step 2: Normalize text
    df["normalized_text"] = df["raw_text"].apply(normalize_text)

    # Discard rows that became empty after normalization (e.g., placeholder-only text)
    valid_mask = df["normalized_text"].str.len() > 0
    dropped_count = (~valid_mask).sum()
    df = df[valid_mask].reset_index(drop=True)

    logger.info(
        "Normalization complete: %d valid rows, %d dropped (empty after normalization)",
        len(df),
        dropped_count,
    )

    update_job_status(job_id, status="preprocess", progress=global_progress("preprocess", 50))

    # Step 3: Update normalized_text in database
    updates = [
        {"id": int(row["id"]), "normalized_text": row["normalized_text"]}
        for _, row in df.iterrows()
    ]
    save_normalized_text_to_db(updates)

    update_job_status(job_id, status="preprocess", progress=global_progress("preprocess", 70))

    # Step 4: Save to S3 as Parquet
    output_s3_path = f"s3://{S3_BUCKET}/{tenant_id}/jobs/{job_id}/preprocess/normalized_rows.parquet"

    # Prepare export DataFrame with row_id and normalized_text
    export_df = df[["id", "normalized_text"]].rename(columns={"id": "row_id"})
    upload_parquet_to_s3(export_df, output_s3_path)

    update_job_status(job_id, status="preprocess", progress=global_progress("preprocess", 90))

    # Step 5: Record step output metadata
    update_job_step_outputs(job_id, "preprocess", {
        "total_rows": int(len(df)),
        "dropped_rows": int(dropped_count),
        "output_s3_path": output_s3_path,
    })

    logger.info("Preprocess step completed for job %d", job_id)

    # Step 6: Chain to next step (embedding)
    next_step = dispatch_next_step(
        current_step="preprocess",
        job_id=job_id,
        tenant_id=tenant_id,
        dataset_id=dataset_id,
        output_s3_path=output_s3_path,
        pipeline_config=kwargs.get("pipeline_config", {}),
    )

    # If no next step exists, the pipeline ends here
    if next_step is None:
        update_job_status(job_id, status="completed", progress=100)
