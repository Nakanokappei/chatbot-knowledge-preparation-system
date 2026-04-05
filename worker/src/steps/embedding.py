"""
Embedding step — generate vector representations using Bedrock Titan Embed v2.

This step reads normalized text from S3, generates embeddings (with caching),
and saves the result as a NumPy array on S3 for the clustering step.

Input:  s3://{bucket}/{tenant_id}/jobs/{job_id}/preprocess/normalized_rows.parquet
Output: s3://{bucket}/{tenant_id}/jobs/{job_id}/embedding/embeddings.npy

Caching strategy:
- Each embedding is stored individually in S3 as JSON, keyed by content hash
- DB table (embedding_cache) maps hash -> S3 path for fast lookups
- Cache reads and writes are parallelized with ThreadPoolExecutor
- On cache hit, embeddings are loaded from S3 in parallel (no Bedrock call)
- On cache miss, Bedrock generates the embedding, then cache is populated
"""

import hashlib
import io
import json
import logging
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime, timezone

import boto3
import numpy as np
import pandas as pd

from src.embedding_client import EMBEDDING_DIMENSION as DEFAULT_EMBEDDING_DIMENSION
from src.embedding_client import MODEL_ID as DEFAULT_MODEL_ID
from src.embedding_client import generate_embeddings_batch
from src.config import S3_BUCKET, S3_REGION
from src.db import get_connection, update_job_status, update_job_step_outputs, global_progress
from src.step_chain import dispatch_next_step

logger = logging.getLogger(__name__)

# Cache configuration
NORMALIZATION_VERSION = "v1.0"

# S3 parallelism for cache operations (I/O-bound, safe to use more threads)
S3_CACHE_WORKERS = 10


def compute_cache_key(text: str, model_id: str = None, dimension: int = None) -> str:
    """
    Compute a SHA-256 cache key for an embedding.

    Key includes normalized_text + model + dimension to ensure cache
    invalidation when any of these change.
    """
    mid = model_id or DEFAULT_MODEL_ID
    dim = dimension or DEFAULT_EMBEDDING_DIMENSION
    payload = f"{text}|{NORMALIZATION_VERSION}|{mid}|{dim}"
    return hashlib.sha256(payload.encode("utf-8")).hexdigest()


def _load_single_embedding_from_s3(s3_client, embedding_hash: str, s3_path: str):
    """
    Load a single embedding JSON from S3.

    Returns (embedding_hash, embedding_vector) on success, or
    (embedding_hash, None) on failure.
    """
    try:
        key = s3_path.replace(f"s3://{S3_BUCKET}/", "")
        response = s3_client.get_object(Bucket=S3_BUCKET, Key=key)
        embedding = json.loads(response["Body"].read())
        return embedding_hash, embedding
    except Exception as e:
        logger.warning("Failed to load cached embedding %s: %s", embedding_hash, e)
        return embedding_hash, None


def check_cache_batch(cache_keys: list[str]) -> dict[str, list[float]]:
    """
    Look up multiple cache keys in the embedding_cache table.

    DB lookup is a single query, then S3 reads are parallelized with
    ThreadPoolExecutor for maximum throughput on cache hits.

    Returns a dict mapping cache_key -> embedding vector (as list of floats)
    for keys that are found. Missing keys are not included.
    """
    # Short-circuit when there are no keys to look up
    if not cache_keys:
        return {}

    conn = get_connection()
    try:
        with conn.cursor() as cur:
            # Single batch query for all cache keys
            placeholders = ",".join(["%s"] * len(cache_keys))
            cur.execute(
                f"SELECT embedding_hash, s3_path FROM embedding_cache WHERE embedding_hash IN ({placeholders})",
                cache_keys,
            )
            rows = cur.fetchall()

        # No cache hits in the DB; return empty without touching S3
        if not rows:
            return {}

        # Parallel S3 reads for all cached embeddings
        s3 = boto3.client("s3", region_name=S3_REGION)
        cached = {}

        logger.info("Loading %d cached embeddings from S3 (workers=%d)...", len(rows), S3_CACHE_WORKERS)

        with ThreadPoolExecutor(max_workers=S3_CACHE_WORKERS) as executor:
            futures = {
                executor.submit(_load_single_embedding_from_s3, s3, emb_hash, s3_path): emb_hash
                for emb_hash, s3_path in rows
            }

            for future in as_completed(futures):
                emb_hash, embedding = future.result()
                if embedding is not None:
                    cached[emb_hash] = embedding

        logger.info("Loaded %d/%d embeddings from S3 cache", len(cached), len(rows))
        return cached
    finally:
        conn.close()


def _upload_single_embedding_to_s3(s3_client, entry: dict):
    """
    Upload a single embedding JSON to S3.

    Returns the entry on success for DB batch insert.
    """
    key = entry["s3_path"].replace(f"s3://{S3_BUCKET}/", "")
    s3_client.put_object(
        Bucket=S3_BUCKET,
        Key=key,
        Body=json.dumps(entry["embedding"]),
        ContentType="application/json",
    )
    return entry


def save_cache_batch(entries: list[dict]):
    """
    Save multiple embeddings to cache (S3 files + DB records).

    S3 uploads run in parallel, then a single DB transaction inserts
    all cache records. This minimizes wall-clock time while keeping
    the DB operation atomic.

    Each entry: {"hash": str, "embedding": list[float], "s3_path": str}
    """
    # Nothing to save when the batch is empty
    if not entries:
        return

    # Parallel S3 uploads
    s3 = boto3.client("s3", region_name=S3_REGION)

    logger.info("Uploading %d embeddings to S3 cache (workers=%d)...", len(entries), S3_CACHE_WORKERS)

    with ThreadPoolExecutor(max_workers=S3_CACHE_WORKERS) as executor:
        futures = [
            executor.submit(_upload_single_embedding_to_s3, s3, entry)
            for entry in entries
        ]

        # Wait for all uploads to complete, propagating any errors
        for future in as_completed(futures):
            future.result()

    # Single DB transaction for all cache records
    conn = get_connection()
    try:
        with conn.cursor() as cur:
            now = datetime.now(timezone.utc)

            for entry in entries:
                cur.execute(
                    """INSERT INTO embedding_cache
                       (embedding_hash, normalization_version, model_name, dimension, s3_path, created_at, updated_at)
                       VALUES (%s, %s, %s, %s, %s, %s, %s)
                       ON CONFLICT (embedding_hash) DO NOTHING""",
                    (entry["hash"], NORMALIZATION_VERSION, entry.get("model_id", DEFAULT_MODEL_ID),
                     entry.get("dimension", DEFAULT_EMBEDDING_DIMENSION), entry["s3_path"], now, now),
                )
            conn.commit()
        logger.info("Saved %d embeddings to cache (S3 + DB)", len(entries))
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
    2. Check embedding cache for existing vectors (parallel S3 reads)
    3. Generate embeddings for uncached rows via Bedrock (2-thread parallel)
    4. Save new embeddings to cache (parallel S3 writes)
    5. Combine all embeddings and save as .npy to S3
    6. Chain to clustering step
    """
    logger.info("Embedding step started for job %d", job_id)
    update_job_status(job_id, status="embedding", progress=global_progress("embedding", 10))

    # Resolve embedding model from pipeline config (or use defaults)
    pipeline_config = kwargs.get("pipeline_config") or {}
    model_id = pipeline_config.get("embedding_model", DEFAULT_MODEL_ID)
    embedding_dimension = pipeline_config.get("embedding_dimension", DEFAULT_EMBEDDING_DIMENSION)
    logger.info("Using embedding model: %s (%dd)", model_id, embedding_dimension)

    # Step 1: Load normalized data from S3
    df = download_parquet_from_s3(input_s3_path)
    logger.info("Loaded %d rows from %s", len(df), input_s3_path)

    update_job_status(job_id, status="embedding", progress=global_progress("embedding", 15))

    # Step 2: Compute cache keys and check cache (parallel S3 reads)
    df["cache_key"] = df["normalized_text"].apply(
        lambda t: compute_cache_key(t, model_id, embedding_dimension)
    )
    all_keys = df["cache_key"].tolist()

    cached_embeddings = check_cache_batch(all_keys)
    cache_hits = len(cached_embeddings)
    cache_misses = len(all_keys) - cache_hits

    logger.info(
        "Cache check: %d hits, %d misses (%.1f%% hit rate)",
        cache_hits, cache_misses,
        (cache_hits / len(all_keys) * 100) if all_keys else 0,
    )

    update_job_status(job_id, status="embedding", progress=global_progress("embedding", 25))

    # Step 3: Generate embeddings for uncached rows (2-thread Bedrock calls)
    # Identify rows that need Bedrock API calls (not found in cache)
    uncached_indices = [i for i, key in enumerate(all_keys) if key not in cached_embeddings]
    uncached_texts = [df.iloc[i]["normalized_text"] for i in uncached_indices]

    new_embeddings = {}
    if uncached_texts:
        logger.info("Generating %d embeddings (model=%s)...", len(uncached_texts), model_id)

        def progress_cb(completed, total):
            # Map embedding progress to 25-75% of local step range
            local_pct = 25 + int((completed / total) * 50)
            update_job_status(job_id, status="embedding", progress=global_progress("embedding", local_pct))

        vectors = generate_embeddings_batch(
            uncached_texts,
            max_workers=8,
            progress_callback=progress_cb,
            model_id=model_id,
            dimension=embedding_dimension,
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
                "model_id": model_id,
                "dimension": embedding_dimension,
            })

        # Step 4: Save to cache (parallel S3 writes + single DB transaction)
        save_cache_batch(cache_entries)
        logger.info("Saved %d new embeddings to cache", len(cache_entries))

    update_job_status(job_id, status="embedding", progress=global_progress("embedding", 80))

    # Assemble all embeddings in original row order from cache and new results
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

    update_job_status(job_id, status="embedding", progress=global_progress("embedding", 90))

    # Step 6: Record step metadata
    update_job_step_outputs(job_id, "embedding", {
        "total_rows": int(len(df)),
        "cache_hits": cache_hits,
        "cache_misses": cache_misses,
        "cache_hit_rate": round(cache_hits / len(all_keys) * 100, 1) if all_keys else 0,
        "embedding_dimension": embedding_dimension,
        "model": model_id,
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
