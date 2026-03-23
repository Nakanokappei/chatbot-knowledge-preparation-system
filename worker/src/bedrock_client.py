"""
Amazon Bedrock client for Titan Text Embeddings V2.

Handles API invocation with retry logic and rate limiting.
Follows ADR-0006: Bedrock as the AI provider.
"""

import json
import logging
import time

import boto3
from botocore.exceptions import ClientError

from src.config import SQS_REGION  # Bedrock uses same region

logger = logging.getLogger(__name__)

# Bedrock model configuration (CTO decision: Titan Embed v2, 1024 dimensions)
MODEL_ID = "amazon.titan-embed-text-v2:0"
EMBEDDING_DIMENSION = 1024

# Retry configuration
MAX_RETRIES = 3
BASE_DELAY = 1.0  # seconds


def get_bedrock_client():
    """Create a Bedrock Runtime client."""
    return boto3.client("bedrock-runtime", region_name=SQS_REGION)


def generate_embedding(text: str, client=None) -> list[float]:
    """
    Generate a single embedding vector using Titan Text Embeddings V2.

    Args:
        text: The input text to embed (should be normalized).
        client: Optional pre-created Bedrock client (for reuse in batch calls).

    Returns:
        A list of floats representing the embedding vector (1024 dimensions).

    Raises:
        Exception: After MAX_RETRIES failures.
    """
    if client is None:
        client = get_bedrock_client()

    body = json.dumps({
        "inputText": text,
        "dimensions": EMBEDDING_DIMENSION,
        "normalize": True,
    })

    for attempt in range(MAX_RETRIES):
        try:
            response = client.invoke_model(
                modelId=MODEL_ID,
                body=body,
                contentType="application/json",
                accept="application/json",
            )

            result = json.loads(response["body"].read())
            embedding = result["embedding"]

            return embedding

        except ClientError as e:
            error_code = e.response["Error"]["Code"]

            # Throttling: wait and retry with exponential backoff
            if error_code == "ThrottlingException":
                delay = BASE_DELAY * (2 ** attempt)
                logger.warning(
                    "Bedrock throttled (attempt %d/%d). Waiting %.1fs...",
                    attempt + 1, MAX_RETRIES, delay,
                )
                time.sleep(delay)
                continue

            # Other errors: log and re-raise
            logger.error("Bedrock API error: %s - %s", error_code, e)
            raise

        except Exception as e:
            delay = BASE_DELAY * (2 ** attempt)
            logger.warning(
                "Bedrock call failed (attempt %d/%d): %s. Retrying in %.1fs...",
                attempt + 1, MAX_RETRIES, e, delay,
            )
            time.sleep(delay)

    raise RuntimeError(f"Bedrock API failed after {MAX_RETRIES} retries")


def generate_embeddings_batch(
    texts: list[str],
    max_workers: int = 10,
    progress_callback=None,
) -> list[list[float]]:
    """
    Generate embeddings for multiple texts using thread pool parallelism.

    Titan Embed v2 accepts one text per API call, so we parallelize
    with a ThreadPoolExecutor (I/O-bound task).

    Args:
        texts: List of normalized text strings.
        max_workers: Number of parallel threads (default: 10).
        progress_callback: Optional callable(completed, total) for progress updates.

    Returns:
        List of embedding vectors in the same order as input texts.
    """
    from concurrent.futures import ThreadPoolExecutor, as_completed

    client = get_bedrock_client()
    results = [None] * len(texts)
    completed = 0

    logger.info(
        "Generating embeddings for %d texts (workers=%d, model=%s, dim=%d)",
        len(texts), max_workers, MODEL_ID, EMBEDDING_DIMENSION,
    )

    with ThreadPoolExecutor(max_workers=max_workers) as executor:
        # Submit all tasks with their original index
        future_to_idx = {
            executor.submit(generate_embedding, text, client): idx
            for idx, text in enumerate(texts)
        }

        for future in as_completed(future_to_idx):
            idx = future_to_idx[future]
            try:
                results[idx] = future.result()
                completed += 1

                # Progress logging every 50 items
                if completed % 50 == 0 or completed == len(texts):
                    logger.info("Embedding progress: %d/%d", completed, len(texts))

                if progress_callback:
                    progress_callback(completed, len(texts))

            except Exception as e:
                logger.error("Failed to embed text at index %d: %s", idx, e)
                raise

    return results
