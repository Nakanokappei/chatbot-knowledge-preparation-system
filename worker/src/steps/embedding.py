"""
Embedding step — generate vector representations using Bedrock Titan Embed v2.

This step reads normalized text from S3, generates embeddings (with caching),
and saves the result as a NumPy array on S3 for the clustering step.

Input:  s3://{bucket}/{tenant_id}/jobs/{job_id}/preprocess/normalized_rows.parquet
Output: s3://{bucket}/{tenant_id}/jobs/{job_id}/embedding/embeddings.npy

Caching strategy:
- Embedding vectors live in `embedding_cache.embedding_vector` (pgvector).
- Lookups and writes are single SQL operations — no per-vector S3 round-trip.
- Legacy entries created before the pgvector migration may have NULL
  embedding_vector and a populated s3_path; on read we transparently
  fall back to S3 and lazily backfill the row. New writes never touch S3.
- This replaces the earlier design where every vector was an individual
  S3 JSON object (~30 KB each, ~34k objects) which was expensive to
  manage operationally.
"""

import hashlib
import json
import logging
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime, timezone

import boto3
import numpy as np

from src.embedding_client import EMBEDDING_DIMENSION as DEFAULT_EMBEDDING_DIMENSION
from src.embedding_client import MODEL_ID as DEFAULT_MODEL_ID
from src.embedding_client import generate_embeddings_batch
from src.config import S3_BUCKET, S3_REGION
from src.db import get_connection, update_job_status, update_job_step_outputs, global_progress, update_job_action
from src.s3_helpers import download_parquet, upload_json, upload_npy
from src.step_chain import dispatch_next_step

logger = logging.getLogger(__name__)

# Cache configuration
NORMALIZATION_VERSION = "v1.0"

# Parallelism for legacy S3 fallback reads only. Once the backfill is
# complete and embedding_cache.s3_path is dropped, this can go away too.
S3_LEGACY_FALLBACK_WORKERS = 10


def _vector_to_pg_literal(vector) -> str:
    """
    Convert a Python list/np.array of floats to the pgvector text literal form
    that psycopg2 can pass through as a string parameter, e.g.
    `[0.123,-0.456,...]`. Mirrors the pattern used in steps/clustering.py for
    cluster_centroids.
    """
    return "[" + ",".join(str(float(v)) for v in vector) + "]"


def _pg_literal_to_vector(literal: str) -> list[float]:
    """
    Parse pgvector's text representation back into a Python list of floats.
    psycopg2 returns vector columns as a `[f1,f2,...]` string by default.
    """
    if literal is None:
        return None
    # Strip surrounding brackets and split. Robust to whitespace.
    stripped = literal.strip()
    if stripped.startswith("[") and stripped.endswith("]"):
        stripped = stripped[1:-1]
    if not stripped:
        return []
    return [float(x) for x in stripped.split(",")]


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
    Legacy fallback: load an embedding JSON from S3 for rows that pre-date
    the pgvector backfill. Removed once embedding_cache.s3_path is dropped.

    Returns (embedding_hash, embedding_vector) on success, or
    (embedding_hash, None) on failure.
    """
    try:
        key = s3_path.replace(f"s3://{S3_BUCKET}/", "")
        response = s3_client.get_object(Bucket=S3_BUCKET, Key=key)
        embedding = json.loads(response["Body"].read())
        return embedding_hash, embedding
    except Exception as e:
        logger.warning("Failed to load legacy cached embedding %s: %s", embedding_hash, e)
        return embedding_hash, None


def _lazy_backfill_vector(embedding_hash: str, vector: list[float]):
    """
    Write a freshly-recovered legacy vector back into the pgvector column
    so subsequent cache hits skip the S3 round-trip. Best-effort: failures
    are logged but do not interrupt the pipeline.
    """
    try:
        conn = get_connection()
        try:
            with conn.cursor() as cur:
                cur.execute(
                    "UPDATE embedding_cache SET embedding_vector = %s::vector, updated_at = %s "
                    "WHERE embedding_hash = %s AND embedding_vector IS NULL",
                    (_vector_to_pg_literal(vector), datetime.now(timezone.utc), embedding_hash),
                )
                conn.commit()
        finally:
            conn.close()
    except Exception as e:
        logger.warning("Lazy backfill failed for %s: %s", embedding_hash, e)


def check_cache_batch(cache_keys: list[str]) -> dict[str, list[float]]:
    """
    Look up multiple cache keys in the embedding_cache table.

    Primary path: read embedding_vector directly from the DB (single query,
    no S3 round-trip).

    Legacy fallback: rows whose embedding_vector is NULL but s3_path is
    populated were created before the pgvector migration. We fetch them
    from S3 in parallel and lazily backfill the row so subsequent hits
    use the fast path. This branch will go away once the backfill CLI
    has run to completion and the s3_path column is dropped.

    Returns a dict mapping cache_key -> embedding vector (as list of floats)
    for keys that are found. Missing keys are not included.
    """
    # Short-circuit when there are no keys to look up
    if not cache_keys:
        return {}

    conn = get_connection()
    try:
        with conn.cursor() as cur:
            # Single batch query — get either the inline vector or the legacy s3 pointer
            placeholders = ",".join(["%s"] * len(cache_keys))
            cur.execute(
                f"SELECT embedding_hash, embedding_vector::text, s3_path "
                f"FROM embedding_cache WHERE embedding_hash IN ({placeholders})",
                cache_keys,
            )
            rows = cur.fetchall()
    finally:
        conn.close()

    if not rows:
        return {}

    cached: dict[str, list[float]] = {}
    legacy_rows: list[tuple[str, str]] = []  # (hash, s3_path) for fallback

    # Partition rows: inline vectors are returned immediately; rows with
    # NULL vector but a valid s3_path go into the legacy fallback bucket.
    for emb_hash, vector_text, s3_path in rows:
        if vector_text is not None:
            cached[emb_hash] = _pg_literal_to_vector(vector_text)
        elif s3_path:
            legacy_rows.append((emb_hash, s3_path))

    if legacy_rows:
        logger.info(
            "Loading %d legacy embeddings from S3 (workers=%d). These will be lazily backfilled.",
            len(legacy_rows), S3_LEGACY_FALLBACK_WORKERS,
        )
        s3 = boto3.client("s3", region_name=S3_REGION)
        with ThreadPoolExecutor(max_workers=S3_LEGACY_FALLBACK_WORKERS) as executor:
            futures = {
                executor.submit(_load_single_embedding_from_s3, s3, emb_hash, s3_path): emb_hash
                for emb_hash, s3_path in legacy_rows
            }
            for future in as_completed(futures):
                emb_hash, embedding = future.result()
                if embedding is not None:
                    cached[emb_hash] = embedding
                    # Migrate the row inline so future hits skip S3 entirely.
                    _lazy_backfill_vector(emb_hash, embedding)

    logger.info(
        "Cache: %d hits (%d inline / %d legacy-S3) out of %d DB rows",
        len(cached), len(cached) - len(legacy_rows), len(legacy_rows), len(rows),
    )
    return cached


def save_cache_batch(entries: list[dict]):
    """
    Persist new embeddings into embedding_cache.embedding_vector. The S3
    write path used by the legacy design is intentionally omitted — vectors
    now live exclusively in the DB.

    Each entry: {"hash": str, "embedding": list[float], "model_id": str,
                 "dimension": int}
    """
    # Nothing to save when the batch is empty
    if not entries:
        return

    conn = get_connection()
    try:
        with conn.cursor() as cur:
            now = datetime.now(timezone.utc)

            for entry in entries:
                cur.execute(
                    """INSERT INTO embedding_cache
                       (embedding_hash, normalization_version, model_name,
                        dimension, s3_path, embedding_vector, created_at, updated_at)
                       VALUES (%s, %s, %s, %s, %s, %s::vector, %s, %s)
                       ON CONFLICT (embedding_hash) DO UPDATE
                       SET embedding_vector = EXCLUDED.embedding_vector,
                           updated_at = EXCLUDED.updated_at
                       WHERE embedding_cache.embedding_vector IS NULL""",
                    (
                        entry["hash"],
                        NORMALIZATION_VERSION,
                        entry.get("model_id", DEFAULT_MODEL_ID),
                        entry.get("dimension", DEFAULT_EMBEDDING_DIMENSION),
                        # s3_path is retained as empty string for new rows so
                        # the NOT NULL constraint on the legacy column still
                        # holds. The follow-up cleanup migration drops this
                        # column once all rows have an inline vector.
                        "",
                        _vector_to_pg_literal(entry["embedding"]),
                        now,
                        now,
                    ),
                )
            conn.commit()
        logger.info("Saved %d embeddings to embedding_cache (pgvector)", len(entries))
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()


# download_parquet_from_s3() / upload_npy_to_s3() lived here; superseded by
# src.s3_helpers.download_parquet / upload_npy which centralise the boto3
# client + S3 key parsing for the whole pipeline.


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
    update_job_action(job_id, "埋め込みステップを開始しています")

    # Resolve embedding model from pipeline config (or use defaults)
    pipeline_config = kwargs.get("pipeline_config") or {}
    model_id = pipeline_config.get("embedding_model", DEFAULT_MODEL_ID)
    embedding_dimension = pipeline_config.get("embedding_dimension", DEFAULT_EMBEDDING_DIMENSION)
    logger.info("Using embedding model: %s (%dd)", model_id, embedding_dimension)

    # Step 1: Load normalized data from S3
    update_job_action(job_id, "S3から前処理済みデータを読み込み中")
    df = download_parquet(input_s3_path)
    logger.info("Loaded %d rows from %s", len(df), input_s3_path)

    update_job_status(job_id, status="embedding", progress=global_progress("embedding", 15))
    update_job_action(job_id, f"埋め込みキャッシュを確認中 ({len(df)}件)")

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
    update_job_action(
        job_id,
        f"キャッシュヒット {cache_hits}件 / 新規生成 {cache_misses}件",
    )

    # Step 3: Generate embeddings for uncached rows (2-thread Bedrock calls)
    # Identify rows that need Bedrock API calls (not found in cache)
    uncached_indices = [i for i, key in enumerate(all_keys) if key not in cached_embeddings]
    uncached_texts = [df.iloc[i]["normalized_text"] for i in uncached_indices]

    new_embeddings = {}
    if uncached_texts:
        logger.info("Generating %d embeddings (model=%s)...", len(uncached_texts), model_id)

        _last_reported_pct = [0]
        _uncached_total = len(uncached_texts)

        def progress_cb(completed, total):
            # Map embedding progress to 25-75% of local step range.
            # Only update DB when percentage actually changes to avoid
            # excessive connections (50k items / 50-item chunks = 1000 calls).
            local_pct = 25 + int((completed / total) * 50)
            if local_pct > _last_reported_pct[0]:
                _last_reported_pct[0] = local_pct
                update_job_status(job_id, status="embedding", progress=global_progress("embedding", local_pct))
                # Refresh current_action in lockstep so the UI shows live counts
                # while Bedrock is being hammered. Without this the whole
                # embedding phase looks frozen to the user.
                update_job_action(
                    job_id,
                    f"Bedrockで埋め込み生成中 ({completed}/{_uncached_total})",
                )

        vectors = generate_embeddings_batch(
            uncached_texts,
            max_workers=8,
            progress_callback=progress_cb,
            model_id=model_id,
            dimension=embedding_dimension,
        )

        # Build cache entries for new embeddings. Vectors are persisted
        # directly into embedding_cache.embedding_vector (pgvector) — no
        # per-vector S3 PUT happens here anymore.
        cache_entries = []
        for idx_in_uncached, orig_idx in enumerate(uncached_indices):
            key = all_keys[orig_idx]
            vector = vectors[idx_in_uncached]

            new_embeddings[key] = vector
            cache_entries.append({
                "hash": key,
                "embedding": vector,
                "model_id": model_id,
                "dimension": embedding_dimension,
            })

        # Step 4: Save to embedding_cache (single DB transaction)
        update_job_action(job_id, f"埋め込みキャッシュ保存中 ({len(cache_entries)}件)")
        save_cache_batch(cache_entries)
        logger.info("Saved %d new embeddings to cache", len(cache_entries))

    update_job_status(job_id, status="embedding", progress=global_progress("embedding", 80))
    update_job_action(job_id, f"埋め込み行列を組み立て中 ({len(all_keys)}件)")

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
    update_job_action(job_id, "埋め込み結果をS3にアップロード中")
    output_s3_path = f"s3://{S3_BUCKET}/{tenant_id}/jobs/{job_id}/embedding/embeddings.npy"
    upload_npy(embedding_matrix, output_s3_path)

    # Also save the row_id -> index mapping (used by clustering / parameter
    # search to map back from sample indices to original row IDs).
    row_ids_path = f"s3://{S3_BUCKET}/{tenant_id}/jobs/{job_id}/embedding/row_ids.json"
    upload_json(df["row_id"].tolist(), row_ids_path)

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

    # Chain to clustering. (The wizard-stage parameter_search divert that
    # used to live here was removed when the configure-screen button was
    # taken out — parameter sweeps now run only from the workspace side
    # via EmbeddingController::parameterSearch, which dispatches a
    # standalone start_step='parameter_search' job.)
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
