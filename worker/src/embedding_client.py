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

# Adaptive rate limiter shared across threads
_rate_limiter_lock = threading.Lock()
_min_interval = 0.05
_last_request_time = 0.0
_throttle_backoff_until = 0.0


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


# ── OpenAI embedding ──────────────────────────────────────────────────

def _generate_openai_embedding(text: str, model_id: str, dimension: int) -> list[float]:
    """Generate embedding via OpenAI API with retry logic."""
    import httpx

    api_key = _get_openai_api_key()
    url = "https://api.openai.com/v1/embeddings"
    headers = {
        "Authorization": f"Bearer {api_key}",
        "Content-Type": "application/json",
    }
    payload = {
        "model": model_id,
        "input": text,
        "dimensions": dimension,
    }

    for attempt in range(MAX_RETRIES):
        try:
            with httpx.Client(timeout=30.0) as client:
                response = client.post(url, headers=headers, json=payload)

            if response.status_code == 429:
                # Rate limited — exponential backoff
                delay = BASE_DELAY * (2 ** attempt)
                logger.warning("OpenAI rate limited (attempt %d/%d). Retrying in %.1fs...",
                               attempt + 1, MAX_RETRIES, delay)
                time.sleep(delay)
                continue

            response.raise_for_status()
            data = response.json()
            return data["data"][0]["embedding"]

        except Exception as e:
            if attempt == MAX_RETRIES - 1:
                raise RuntimeError(f"OpenAI API failed after {MAX_RETRIES} retries: {e}")
            delay = BASE_DELAY * (2 ** attempt)
            logger.warning("OpenAI call failed (attempt %d/%d): %s. Retrying in %.1fs...",
                           attempt + 1, MAX_RETRIES, e, delay)
            time.sleep(delay)

    raise RuntimeError(f"OpenAI API failed after {MAX_RETRIES} retries")


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

    Routes to the appropriate provider based on model_id.
    """
    from concurrent.futures import ThreadPoolExecutor, as_completed

    mid = model_id or MODEL_ID
    dim = dimension or EMBEDDING_DIMENSION

    results = [None] * len(texts)
    completed_count = 0

    logger.info(
        "Generating embeddings for %d texts (workers=%d, model=%s, dim=%d)",
        len(texts), max_workers, mid, dim,
    )

    start_time = time.monotonic()

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
