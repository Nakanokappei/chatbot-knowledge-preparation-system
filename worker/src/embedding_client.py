"""
Embedding client — multi-provider vector generation.

Routes embedding requests to the appropriate provider:
- AWS Bedrock: Titan Embed v2, Cohere Embed, etc.
- OpenAI: text-embedding-3-small, text-embedding-3-large

Handles adaptive rate limiting, retry logic, and thread-safe concurrency
for all providers.
"""

import json
import logging
import re
import threading
import time

import boto3
from botocore.exceptions import ClientError

from src.config import SQS_REGION

logger = logging.getLogger(__name__)

# Default model configuration (Bedrock Titan Embed v2)
MODEL_ID = "amazon.titan-embed-text-v2:0"
EMBEDDING_DIMENSION = 1024

# Retry configuration
MAX_RETRIES = 5
BASE_DELAY = 1.0  # seconds

# OpenAI batch configuration
OPENAI_BATCH_SIZE = 50   # texts per API request (token budget safe)
OPENAI_MAX_WORKERS = 4   # concurrent batch requests

# Adaptive rate limiter shared across threads (Bedrock)
_rate_limiter_lock = threading.Lock()
_min_interval = 0.05
_last_request_time = 0.0
_throttle_backoff_until = 0.0

# OpenAI rate limiter — driven by response headers
_openai_lock = threading.Lock()
_openai_remaining_requests = 500
_openai_remaining_tokens = 1_000_000
_openai_reset_at = 0.0


def _is_openai_model(model_id: str) -> bool:
    """Check if a model ID belongs to OpenAI (not available via Bedrock)."""
    return model_id.startswith("text-embedding-")


def get_bedrock_client():
    """Create a Bedrock Runtime client."""
    return boto3.client("bedrock-runtime", region_name=SQS_REGION)


def _get_openai_api_key() -> str:
    """
    Retrieve the OpenAI API key from the OPENAI_API_KEY environment variable.

    The key must be set in .env or the ECS task definition.
    """
    import os

    key = os.environ.get("OPENAI_API_KEY")
    if key and key.startswith("sk-"):
        return key

    raise RuntimeError(
        "OpenAI API key not found. Set the OPENAI_API_KEY environment variable."
    )


# ── Rate limiter (shared by Bedrock; OpenAI has its own limits) ───────

def _wait_for_rate_limit():
    """Thread-safe rate limiter for Bedrock requests."""
    global _last_request_time
    with _rate_limiter_lock:
        now = time.monotonic()
        if now < _throttle_backoff_until:
            wait_time = _throttle_backoff_until - now
            time.sleep(wait_time)
            now = time.monotonic()
        elapsed = now - _last_request_time
        if elapsed < _min_interval:
            time.sleep(_min_interval - elapsed)
        _last_request_time = time.monotonic()


def _handle_throttle(attempt: int):
    """Adjust rate limiter when throttling is detected."""
    global _min_interval, _throttle_backoff_until
    with _rate_limiter_lock:
        _min_interval = min(_min_interval * 2, 2.0)
        backoff_duration = BASE_DELAY * (2 ** attempt)
        _throttle_backoff_until = time.monotonic() + backoff_duration
        logger.warning(
            "Throttle detected: min_interval=%.3fs, global_backoff=%.1fs",
            _min_interval, backoff_duration,
        )


def _relax_rate_limit():
    """Gradually relax the rate limit after successful requests."""
    global _min_interval
    with _rate_limiter_lock:
        _min_interval = max(_min_interval * 0.9, 0.05)


# ── Bedrock embedding ─────────────────────────────────────────────────

def _generate_bedrock_embedding(text: str, model_id: str, dimension: int,
                                 client=None) -> list[float]:
    """Generate embedding via AWS Bedrock with adaptive rate limiting."""
    if client is None:
        client = get_bedrock_client()

    body = json.dumps({
        "inputText": text,
        "dimensions": dimension,
        "normalize": True,
    })

    for attempt in range(MAX_RETRIES):
        _wait_for_rate_limit()
        try:
            response = client.invoke_model(
                modelId=model_id,
                body=body,
                contentType="application/json",
                accept="application/json",
            )
            result = json.loads(response["body"].read())
            _relax_rate_limit()
            return result["embedding"]

        except ClientError as e:
            error_code = e.response["Error"]["Code"]
            if error_code == "ThrottlingException":
                _handle_throttle(attempt)
                logger.warning("Bedrock throttled (attempt %d/%d)", attempt + 1, MAX_RETRIES)
                continue
            logger.error("Bedrock API error: %s - %s", error_code, e)
            raise

        except Exception as e:
            delay = BASE_DELAY * (2 ** attempt)
            logger.warning("Bedrock call failed (attempt %d/%d): %s. Retrying in %.1fs...",
                           attempt + 1, MAX_RETRIES, e, delay)
            time.sleep(delay)

    raise RuntimeError(f"Bedrock API failed after {MAX_RETRIES} retries")


# ── OpenAI rate limiter (header-driven) ──────────────────────────────

def _parse_reset_duration(value: str) -> float:
    """Parse OpenAI reset duration string like '6m30s', '500ms', '2s'."""
    if not value:
        return 0.0
    total = 0.0
    for amount, unit in re.findall(r"([\d.]+)(ms|s|m|h)", value):
        n = float(amount)
        if unit == "ms":
            total += n / 1000
        elif unit == "s":
            total += n
        elif unit == "m":
            total += n * 60
        elif unit == "h":
            total += n * 3600
    return total


def _update_openai_rate_limits(headers):
    """Parse x-ratelimit-* response headers and update shared state."""
    global _openai_remaining_requests, _openai_remaining_tokens, _openai_reset_at
    with _openai_lock:
        rem_req = headers.get("x-ratelimit-remaining-requests")
        if rem_req is not None:
            _openai_remaining_requests = int(rem_req)
        rem_tok = headers.get("x-ratelimit-remaining-tokens")
        if rem_tok is not None:
            _openai_remaining_tokens = int(rem_tok)
        reset_req = headers.get("x-ratelimit-reset-requests")
        if reset_req:
            _openai_reset_at = time.monotonic() + _parse_reset_duration(reset_req)
        logger.debug(
            "OpenAI rate limits: remaining_requests=%s, remaining_tokens=%s, reset=%s",
            rem_req, rem_tok, reset_req,
        )


def _openai_wait_if_needed():
    """Sleep proactively when remaining requests or tokens are low."""
    # Read shared state under lock, but sleep OUTSIDE the lock to avoid
    # blocking other threads from updating rate limit counters.
    wait = 0.0
    with _openai_lock:
        now = time.monotonic()
        if _openai_remaining_requests < 5 and _openai_reset_at > now:
            wait = _openai_reset_at - now
    if wait > 0:
        logger.info("OpenAI proactive throttle: remaining_requests=%d, sleeping %.1fs",
                    _openai_remaining_requests, wait)
        time.sleep(wait)


# ── OpenAI embedding ──────────────────────────────────────────────────

def _generate_openai_embedding(text: str, model_id: str, dimension: int) -> list[float]:
    """Generate a single embedding via OpenAI API (used by single-item callers)."""
    result = _generate_openai_embedding_batch([text], model_id, dimension)
    return result[0]


def _generate_openai_embedding_batch(
    texts: list[str], model_id: str, dimension: int,
    http_client=None,
) -> list[list[float]]:
    """
    Generate embeddings for a batch of texts in one OpenAI API call.

    The API supports list input (up to 2048 items). Returns embeddings
    sorted by the original input order.
    """
    import httpx

    api_key = _get_openai_api_key()
    url = "https://api.openai.com/v1/embeddings"
    headers = {
        "Authorization": f"Bearer {api_key}",
        "Content-Type": "application/json",
    }
    payload = {
        "model": model_id,
        "input": texts,
        "dimensions": dimension,
    }

    client = http_client or httpx.Client(timeout=60.0)
    close_client = http_client is None

    try:
        for attempt in range(MAX_RETRIES):
            _openai_wait_if_needed()
            try:
                response = client.post(url, headers=headers, json=payload)

                # Always parse rate limit headers, even on 429
                _update_openai_rate_limits(response.headers)

                if response.status_code == 429:
                    # Use reset header if available, otherwise exponential backoff
                    reset_str = response.headers.get("x-ratelimit-reset-requests", "")
                    delay = _parse_reset_duration(reset_str) if reset_str else BASE_DELAY * (2 ** attempt)
                    delay = max(delay, 0.5)
                    logger.warning(
                        "OpenAI rate limited (attempt %d/%d, batch=%d). Waiting %.1fs...",
                        attempt + 1, MAX_RETRIES, len(texts), delay,
                    )
                    time.sleep(delay)
                    continue

                response.raise_for_status()
                data = response.json()

                # Sort by index to match input order
                sorted_data = sorted(data["data"], key=lambda d: d["index"])
                return [item["embedding"] for item in sorted_data]

            except httpx.HTTPStatusError:
                raise
            except Exception as e:
                if attempt == MAX_RETRIES - 1:
                    raise RuntimeError(f"OpenAI API failed after {MAX_RETRIES} retries: {e}")
                delay = BASE_DELAY * (2 ** attempt)
                logger.warning(
                    "OpenAI call failed (attempt %d/%d, batch=%d): %s. Retrying in %.1fs...",
                    attempt + 1, MAX_RETRIES, len(texts), e, delay,
                )
                time.sleep(delay)

        raise RuntimeError(f"OpenAI API failed after {MAX_RETRIES} retries")
    finally:
        if close_client:
            client.close()


# ── Public API (provider-agnostic) ────────────────────────────────────

def generate_embedding(text: str, client=None,
                       model_id: str = None, dimension: int = None) -> list[float]:
    """
    Generate a single embedding vector using the appropriate provider.

    Automatically routes to Bedrock or OpenAI based on the model ID.
    Defaults to Titan Embed v2 (1024d) when not specified.
    """
    mid = model_id or MODEL_ID
    dim = dimension or EMBEDDING_DIMENSION

    if _is_openai_model(mid):
        return _generate_openai_embedding(text, mid, dim)
    else:
        return _generate_bedrock_embedding(text, mid, dim, client)


def generate_embeddings_batch(
    texts: list[str],
    max_workers: int = 8,
    progress_callback=None,
    model_id: str = None,
    dimension: int = None,
) -> list[list[float]]:
    """
    Generate embeddings for multiple texts using thread pool parallelism.

    Routes to the appropriate provider based on model_id:
    - Bedrock: 1-text-per-request parallelism (adaptive rate limiter)
    - OpenAI: batch API (N-texts-per-request) with header-driven rate limiting
    """
    from concurrent.futures import ThreadPoolExecutor, as_completed

    mid = model_id or MODEL_ID
    dim = dimension or EMBEDDING_DIMENSION

    logger.info(
        "Generating embeddings for %d texts (model=%s, dim=%d)",
        len(texts), mid, dim,
    )

    start_time = time.monotonic()

    if _is_openai_model(mid):
        results = _generate_openai_embeddings_parallel(
            texts, mid, dim, max_workers=OPENAI_MAX_WORKERS,
            batch_size=OPENAI_BATCH_SIZE, progress_callback=progress_callback,
        )
    else:
        # Bedrock: 1-text-per-request with thread pool
        results = [None] * len(texts)
        completed_count = 0

        with ThreadPoolExecutor(max_workers=max_workers) as executor:
            future_to_idx = {
                executor.submit(generate_embedding, text, None, mid, dim): idx
                for idx, text in enumerate(texts)
            }

            for future in as_completed(future_to_idx):
                idx = future_to_idx[future]
                try:
                    results[idx] = future.result()
                    completed_count += 1

                    if completed_count % 50 == 0 or completed_count == len(texts):
                        elapsed = time.monotonic() - start_time
                        rate = completed_count / elapsed if elapsed > 0 else 0
                        logger.info(
                            "Embedding progress: %d/%d (%.1f items/sec)",
                            completed_count, len(texts), rate,
                        )

                    if progress_callback:
                        progress_callback(completed_count, len(texts))

                except Exception as e:
                    logger.error("Failed to embed text at index %d: %s", idx, e)
                    raise

    elapsed = time.monotonic() - start_time
    logger.info(
        "Embedding batch complete: %d texts in %.1fs (%.1f items/sec)",
        len(texts), elapsed, len(texts) / elapsed if elapsed > 0 else 0,
    )

    return results


def _generate_openai_embeddings_parallel(
    texts: list[str], model_id: str, dimension: int,
    max_workers: int = 4, batch_size: int = 50,
    progress_callback=None,
) -> list[list[float]]:
    """
    Generate OpenAI embeddings using batch API with parallel chunk execution.

    Splits texts into chunks of batch_size, sends each chunk as one API call,
    and runs up to max_workers chunks concurrently. Rate limit headers from
    each response drive proactive throttling across all threads.
    """
    import httpx
    from concurrent.futures import ThreadPoolExecutor, as_completed

    # Split texts into chunks, preserving original indices
    chunks = []
    for i in range(0, len(texts), batch_size):
        chunk_texts = texts[i:i + batch_size]
        chunks.append((i, chunk_texts))

    logger.info(
        "OpenAI batch: %d texts → %d chunks of ≤%d (workers=%d)",
        len(texts), len(chunks), batch_size, max_workers,
    )

    results = [None] * len(texts)
    completed_texts = 0

    # Share one httpx client across threads for connection pooling
    with httpx.Client(timeout=60.0) as client:
        with ThreadPoolExecutor(max_workers=max_workers) as executor:
            future_to_chunk = {
                executor.submit(
                    _generate_openai_embedding_batch,
                    chunk_texts, model_id, dimension, client,
                ): (start_idx, len(chunk_texts))
                for start_idx, chunk_texts in chunks
            }

            for future in as_completed(future_to_chunk):
                start_idx, chunk_len = future_to_chunk[future]
                try:
                    embeddings = future.result()
                    for j, emb in enumerate(embeddings):
                        results[start_idx + j] = emb
                    completed_texts += chunk_len

                    logger.info(
                        "OpenAI embedding progress: %d/%d texts",
                        completed_texts, len(texts),
                    )

                    if progress_callback:
                        progress_callback(completed_texts, len(texts))

                except Exception as e:
                    logger.error(
                        "Failed to embed chunk at index %d (size=%d): %s",
                        start_idx, chunk_len, e,
                    )
                    raise

    return results
