"""
Amazon Bedrock client for Titan Text Embeddings V2.

Handles API invocation with adaptive rate limiting, retry logic,
and thread-safe concurrency control.
Follows ADR-0006: Bedrock as the AI provider.
"""

import json
import logging
import threading
import time

import boto3
from botocore.exceptions import ClientError

from src.config import SQS_REGION  # Bedrock uses same region

logger = logging.getLogger(__name__)

# Bedrock model configuration (CTO decision: Titan Embed v2, 1024 dimensions)
MODEL_ID = "amazon.titan-embed-text-v2:0"
EMBEDDING_DIMENSION = 1024

# Retry configuration
MAX_RETRIES = 5
BASE_DELAY = 1.0  # seconds

# Adaptive rate limiter shared across threads
_rate_limiter_lock = threading.Lock()
_min_interval = 0.05  # seconds between requests (start conservative)
_last_request_time = 0.0
_throttle_backoff_until = 0.0  # global pause until this timestamp


def get_bedrock_client():
    """Create a Bedrock Runtime client."""
    return boto3.client("bedrock-runtime", region_name=SQS_REGION)


def _wait_for_rate_limit():
    """
    Thread-safe rate limiter that enforces minimum interval between requests.

    When throttling is detected, all threads pause until the backoff
    period expires, preventing a burst of retries from making things worse.
    """
    global _last_request_time

    with _rate_limiter_lock:
        now = time.monotonic()

        # If a global throttle backoff is active, wait for it
        if now < _throttle_backoff_until:
            wait_time = _throttle_backoff_until - now
            logger.debug("Rate limiter: global backoff, waiting %.2fs", wait_time)
            time.sleep(wait_time)
            now = time.monotonic()

        # Enforce minimum interval between requests
        elapsed = now - _last_request_time
        if elapsed < _min_interval:
            sleep_time = _min_interval - elapsed
            time.sleep(sleep_time)

        _last_request_time = time.monotonic()


def _handle_throttle(attempt: int):
    """
    Adjust rate limiter when throttling is detected.

    Increases the minimum interval between requests and sets a global
    backoff period so all threads slow down together.
    """
    global _min_interval, _throttle_backoff_until

    with _rate_limiter_lock:
        # Double the minimum interval (up to 2 seconds max)
        _min_interval = min(_min_interval * 2, 2.0)

        # Set global backoff: exponential based on attempt number
        backoff_duration = BASE_DELAY * (2 ** attempt)
        _throttle_backoff_until = time.monotonic() + backoff_duration

        logger.warning(
            "Throttle detected: min_interval=%.3fs, global_backoff=%.1fs",
            _min_interval, backoff_duration,
        )


def _relax_rate_limit():
    """
    Gradually relax the rate limit after successful requests.

    Slowly decreases the minimum interval to find the optimal throughput
    without triggering throttling.
    """
    global _min_interval

    with _rate_limiter_lock:
        # Reduce interval by 10% (down to 0.05s minimum)
        _min_interval = max(_min_interval * 0.9, 0.05)


def generate_embedding(text: str, client=None) -> list[float]:
    """
    Generate a single embedding vector using Titan Text Embeddings V2.

    Includes adaptive rate limiting: waits before each request based on
    observed throttling patterns, and backs off when throttled.

    Args:
        text: The input text to embed (should be normalized).
        client: Optional pre-created Bedrock client (for reuse in batch calls).

    Returns:
        A list of floats representing the embedding vector (1024 dimensions).

    Raises:
        RuntimeError: After MAX_RETRIES failures.
    """
    # Lazily create a client if none was provided by the caller
    if client is None:
        client = get_bedrock_client()

    body = json.dumps({
        "inputText": text,
        "dimensions": EMBEDDING_DIMENSION,
        "normalize": True,
    })

    # Retry loop with adaptive rate limiting per attempt
    for attempt in range(MAX_RETRIES):
        # Wait for rate limit clearance before making the request
        _wait_for_rate_limit()

        try:
            response = client.invoke_model(
                modelId=MODEL_ID,
                body=body,
                contentType="application/json",
                accept="application/json",
            )

            result = json.loads(response["body"].read())
            embedding = result["embedding"]

            # Success: gradually relax the rate limit
            _relax_rate_limit()

            return embedding

        except ClientError as e:
            error_code = e.response["Error"]["Code"]

            # Throttling: signal the adaptive rate limiter and retry
            if error_code == "ThrottlingException":
                _handle_throttle(attempt)
                logger.warning(
                    "Bedrock throttled (attempt %d/%d). Backing off...",
                    attempt + 1, MAX_RETRIES,
                )
                continue

            # Other client errors: log and re-raise immediately
            logger.error("Bedrock API error: %s - %s", error_code, e)
            raise

        except Exception as e:
            # Network or unexpected errors: simple exponential backoff
            delay = BASE_DELAY * (2 ** attempt)
            logger.warning(
                "Bedrock call failed (attempt %d/%d): %s. Retrying in %.1fs...",
                attempt + 1, MAX_RETRIES, e, delay,
            )
            time.sleep(delay)

    raise RuntimeError(f"Bedrock API failed after {MAX_RETRIES} retries")


def generate_embeddings_batch(
    texts: list[str],
    max_workers: int = 8,
    progress_callback=None,
) -> list[list[float]]:
    """
    Generate embeddings for multiple texts using thread pool parallelism.

    Titan Embed v2 accepts one text per API call, so we parallelize
    with a ThreadPoolExecutor. The adaptive rate limiter coordinates
    request timing across all threads to avoid throttling.

    Args:
        texts: List of normalized text strings.
        max_workers: Number of parallel threads (default: 8, Bedrock
                     rate limits are typically hundreds of req/sec).
        progress_callback: Optional callable(completed, total) for progress updates.

    Returns:
        List of embedding vectors in the same order as input texts.
    """
    from concurrent.futures import ThreadPoolExecutor, as_completed

    # Each thread creates its own client to avoid signature expiration.
    # boto3 clients cache credentials internally, so creating new clients
    # is cheap and avoids stale signatures after long-running batches.
    results = [None] * len(texts)
    completed_count = 0

    logger.info(
        "Generating embeddings for %d texts (workers=%d, model=%s, dim=%d)",
        len(texts), max_workers, MODEL_ID, EMBEDDING_DIMENSION,
    )

    start_time = time.monotonic()

    with ThreadPoolExecutor(max_workers=max_workers) as executor:
        # Submit all tasks — each call creates a fresh client
        future_to_idx = {
            executor.submit(generate_embedding, text, None): idx
            for idx, text in enumerate(texts)
        }

        # Collect results as they complete, preserving original order via index
        for future in as_completed(future_to_idx):
            idx = future_to_idx[future]
            try:
                results[idx] = future.result()
                completed_count += 1

                # Progress logging every 50 items or at completion
                if completed_count % 50 == 0 or completed_count == len(texts):
                    elapsed = time.monotonic() - start_time
                    rate = completed_count / elapsed if elapsed > 0 else 0
                    logger.info(
                        "Embedding progress: %d/%d (%.1f items/sec, interval=%.3fs)",
                        completed_count, len(texts), rate, _min_interval,
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
