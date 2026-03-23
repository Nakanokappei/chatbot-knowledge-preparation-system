"""
Embedding step — generate vector representations using Bedrock Titan Embed v2.

This step reads normalized text from S3, generates embeddings (with caching),
and saves the result as a NumPy array on S3 for the clustering step.

Input:  s3://{bucket}/{tenant_id}/jobs/{job_id}/preprocess/normalized_rows.parquet
Output: s3://{bucket}/{tenant_id}/jobs/{job_id}/embedding/embeddings.npy

Design: "This cluster will become a Knowledge Unit later" — every row's
embedding must be cached for reproducible re-runs and future classification.
"""

import hashlib
import io
import json
import logging

import boto3
import numpy as np
import pandas as pd

from src.bedrock_client import EMBEDDING_DIMENSION, MODEL_ID, generate_embeddings_batch
from src.config import S3_BUCKET, S3_REGION
from src.db import get_connection, update_job_status, update_job_step_outputs
from src.step_chain import dispatch_next_step

logger = logging.getLogger(__name__)

# Cache configuration
NORMALIZATION_VERSION = "v1.0"


def compute_cache_key(text: str) -> str:
    """
    Compute a SHA-256 cache key for an embedding.

    Key includes normalized_text + model + dimension to ensure cache
    invalidation when any of these change.
    """
    payload = f"{text}|{NORMALIZATION_VERSION}|{MODEL_ID}|{EMBEDDING_DIMENSION}"
    return hashlib.sha256(payload.encode("utf-8")).hexdigest()


def check_cache_batch(cache_keys: list[str]) -> dict[str, list[float]]:
    """
    Look up multiple cache keys in the embedding_cache table.

    Returns a dict mapping cache_key -> embedding vector (as list of floats)
    for keys that are found. Missing keys are not included.
    """
    if not cache_keys:
        return {}

    conn = get_connection()
    try:
        with conn.cursor() as cur:
            # Fetch S3 paths for cached embeddings
            placeholders = ",".join(["%s"] * len(cache_keys))
            cur.execute(
                f"SELECT embedding_hash, s3_path FROM embedding_cache WHERE embedding_hash IN ({placeholders})",
                cache_keys,
            )
            rows = cur.fetchall()

        if not rows:
            return {}

        # Load embeddings from S3
        s3 = boto3.client("s3", region_name=S3_REGION)
        cached = {}

        for embedding_hash, s3_path in rows:
            try:
                key = s3_path.replace(f"s3://{S3_BUCKET}/", "")
                response = s3.get_object(Bucket=S3_BUCKET, Key=key)
                embedding = json.loads(response["Body"].read())
                cached[embedding_hash] = embedding
            except Exception as e:
                logger.warning("Failed to load cached embedding %s: %s", embedding_hash, e)

        return cached
    finally:
        conn.close()


def save_cache_batch(entries: list[dict]):
    """
    Save multiple embeddings to cache (DB record + S3 file).

    Each entry: {"hash": str, "embedding": list[float], "s3_path": str}
    """
    if not entries:
        return

    # Upload individual embeddings to S3
    s3 = boto3.client("s3", region_name=S3_REGION)
    for entry in entries:
        key = entry["s3_path"].replace(f"s3://{S3_BUCKET}/", "")
        s3.put_object(
            Bucket=S3_BUCKET,
            Key=key,
            Body=json.dumps(entry["embedding"]),
            ContentType="application/json",
        )

    # Insert cache records into DB
    conn = get_connection()
    try:
        with conn.cursor() as cur:
            from datetime import datetime, timezone
            now = datetime.now(timezone.utc)

            for entry in entries:
                cur.execute(
                    """INSERT INTO embedding_cache
                       (embedding_hash, normalization_version, model_name, dimension, s3_path, created_at, updated_at)
                       VALUES (%s, %s, %s, %s, %s, %s, %s)
                       ON CONFLICT (embedding_hash) DO NOTHING""",
                    (entry["hash"], NORMALIZATION_VERSION, MODEL_ID,
                     EMBEDDING_DIMENSION, entry["s3_path"], now, now),
                )
            conn.commit()
        logger.info("Saved %d embeddings to cache", len(entries))
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()


def download_parquet_from_s3(s3_path: str) -> pd.DataFrame:
    """Download a Parquet file from S3 and return as DataFrame."""
    s3 = boto3.client("s3", region_name=S3_REGION)
    key = s3_path.replace(f"s3://{S3_BUCKET}/", "")

    response = s3.get_object(Bucket=S3_BUCKET, Key=key)
    buffer = io.BytesIO(response["Body"].read())

    return pd.read_parquet(buffer)


def upload_npy_to_s3(array: np.ndarray, s3_path: str):
    """Serialize a NumPy array and upload to S3."""
    buffer = io.BytesIO()
    np.save(buffer, array)
    buffer.seek(0)

    s3 = boto3.client("s3", region_name=S3_REGION)
    key = s3_path.replace(f"s3://{S3_BUCKET}/", "")
    s3.put_object(Bucket=S3_BUCKET, Key=key, Body=buffer.getvalue())

    logger.info("Uploaded embeddings to %s (shape: %s)", s3_path, array.shape)


def execute(job_id: int, tenant_id: int, dataset_id: int = None,
            input_s3_path: str = None, **kwargs):
    """
    Execute the embedding step.

    1. Load normalized rows from S3 (Parquet)
    2. Check embedding cache for existing vectors
    3. Generate embeddings for uncached rows via Bedrock
    4. Save new embeddings to cache
    5. Combine all embeddings and save as .npy to S3
    6. Chain to clustering step
    """
    logger.info("Embedding step started for job %d", job_id)
    update_job_status(job_id, status="embedding", progress=10)

    # Step 1: Load normalized data from S3
    df = download_parquet_from_s3(input_s3_path)
    logger.info("Loaded %d rows from %s", len(df), input_s3_path)

    update_job_status(job_id, status="embedding", progress=15)

    # Step 2: Compute cache keys and check cache
    df["cache_key"] = df["normalized_text"].apply(compute_cache_key)
    all_keys = df["cache_key"].tolist()

    cached_embeddings = check_cache_batch(all_keys)
    cache_hits = len(cached_embeddings)
    cache_misses = len(all_keys) - cache_hits

    logger.info(
        "Cache check: %d hits, %d misses (%.1f%% hit rate)",
        cache_hits, cache_misses,
        (cache_hits / len(all_keys) * 100) if all_keys else 0,
    )

    update_job_status(job_id, status="embedding", progress=25)

    # Step 3: Generate embeddings for uncached rows
    uncached_indices = [i for i, key in enumerate(all_keys) if key not in cached_embeddings]
    uncached_texts = [df.iloc[i]["normalized_text"] for i in uncached_indices]

    new_embeddings = {}
    if uncached_texts:
        logger.info("Generating %d embeddings via Bedrock...", len(uncached_texts))

        def progress_cb(completed, total):
            # Map embedding progress to 25-75% of overall step
            pct = 25 + int((completed / total) * 50)
            update_job_status(job_id, status="embedding", progress=pct)

        vectors = generate_embeddings_batch(
            uncached_texts,
            max_workers=10,
            progress_callback=progress_cb,
        )

        # Build cache entries for new embeddings
        cache_entries = []
        for idx_in_uncached, orig_idx in enumerate(uncached_indices):
            key = all_keys[orig_idx]
            vector = vectors[idx_in_uncached]
            s3_cache_path = f"s3://{S3_BUCKET}/cache/embeddings/{key[:2]}/{key}.json"

            new_embeddings[key] = vector
            cache_entries.append({
                "hash": key,
                "embedding": vector,
                "s3_path": s3_cache_path,
            })

        # Step 4: Save to cache
        save_cache_batch(cache_entries)
        logger.info("Saved %d new embeddings to cache", len(cache_entries))

    update_job_status(job_id, status="embedding", progress=80)

    # Step 5: Assemble all embeddings in original row order
    all_embeddings = []
    for key in all_keys:
        if key in cached_embeddings:
            all_embeddings.append(cached_embeddings[key])
        elif key in new_embeddings:
            all_embeddings.append(new_embeddings[key])
        else:
            raise RuntimeError(f"Embedding missing for cache key {key}")

    embedding_matrix = np.array(all_embeddings, dtype=np.float32)

    # Save row_id mapping alongside embeddings (needed by clustering step)
    output_s3_path = f"s3://{S3_BUCKET}/{tenant_id}/jobs/{job_id}/embedding/embeddings.npy"
    upload_npy_to_s3(embedding_matrix, output_s3_path)

    # Also save the row_id -> index mapping
    row_ids_path = f"s3://{S3_BUCKET}/{tenant_id}/jobs/{job_id}/embedding/row_ids.json"
    s3 = boto3.client("s3", region_name=S3_REGION)
    key = row_ids_path.replace(f"s3://{S3_BUCKET}/", "")
    s3.put_object(
        Bucket=S3_BUCKET,
        Key=key,
        Body=json.dumps(df["row_id"].tolist()),
        ContentType="application/json",
    )

    update_job_status(job_id, status="embedding", progress=90)

    # Step 6: Record step metadata
    update_job_step_outputs(job_id, "embedding", {
        "total_rows": int(len(df)),
        "cache_hits": cache_hits,
        "cache_misses": cache_misses,
        "cache_hit_rate": round(cache_hits / len(all_keys) * 100, 1) if all_keys else 0,
        "embedding_dimension": EMBEDDING_DIMENSION,
        "model": MODEL_ID,
        "output_s3_path": output_s3_path,
        "row_ids_s3_path": row_ids_path,
    })

    logger.info("Embedding step completed for job %d", job_id)

    # Step 7: Chain to clustering
    next_step = dispatch_next_step(
        current_step="embedding",
        job_id=job_id,
        tenant_id=tenant_id,
        dataset_id=dataset_id,
        output_s3_path=output_s3_path,
        pipeline_config=kwargs.get("pipeline_config", {}),
    )

    if next_step is None:
        update_job_status(job_id, status="completed", progress=100)
