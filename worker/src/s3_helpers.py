"""
Shared S3 I/O helpers used across the pipeline steps.

Each step (preprocess, embedding, clustering, parameter_search) needs to
move Parquet / NumPy / JSON blobs in and out of the same `S3_BUCKET`.
Before this module those primitives were duplicated 6+ times across the
step files, each with its own boto3 client construction and the same
`s3_path.replace(f"s3://{S3_BUCKET}/", "")` key-extraction dance.

This module gives every caller a single import surface:

    from src.s3_helpers import (
        download_parquet, upload_parquet,
        download_npy, upload_npy,
        download_json, upload_json,
    )

Behaviour:
- Inputs/outputs use full `s3://bucket/key` URIs so callers don't have to
  know the bucket constant. The helper validates that the URI lives in
  the configured bucket and rejects cross-bucket paths early.
- Each call constructs a fresh boto3 client. boto3 itself caches the
  underlying connection pool so this is cheap; sharing a module-level
  client would make unit tests harder (mocking).
"""

import io
import json
import logging

import boto3
import numpy as np
import pandas as pd

from src.config import S3_BUCKET, S3_REGION

logger = logging.getLogger(__name__)


def _key_for(s3_path: str) -> str:
    """
    Convert an `s3://bucket/some/key` URI to the bucket-relative key.

    Raises ValueError if the URI references a different bucket than
    `S3_BUCKET`. We refuse silently-wrong cross-bucket access because the
    pipeline is single-bucket by design and a mismatch indicates either
    a config bug or an attempt to read from somewhere unexpected.
    """
    expected_prefix = f"s3://{S3_BUCKET}/"
    if not s3_path.startswith(expected_prefix):
        raise ValueError(
            f"S3 path {s3_path!r} is not in the configured bucket {S3_BUCKET!r}"
        )
    return s3_path[len(expected_prefix):]


def _client():
    """Build a fresh boto3 S3 client. Cheap thanks to boto3 connection pooling."""
    return boto3.client("s3", region_name=S3_REGION)


# ---------------------------------------------------------------------------
# Parquet
# ---------------------------------------------------------------------------

def download_parquet(s3_path: str) -> pd.DataFrame:
    """Read a Parquet object from S3 and return it as a pandas DataFrame."""
    response = _client().get_object(Bucket=S3_BUCKET, Key=_key_for(s3_path))
    return pd.read_parquet(io.BytesIO(response["Body"].read()))


def upload_parquet(df: pd.DataFrame, s3_path: str):
    """Serialise a DataFrame as Parquet (pyarrow) and upload to S3."""
    buffer = io.BytesIO()
    df.to_parquet(buffer, index=False, engine="pyarrow")
    buffer.seek(0)
    _client().put_object(Bucket=S3_BUCKET, Key=_key_for(s3_path), Body=buffer.getvalue())
    logger.info("Uploaded Parquet to %s (%d rows)", s3_path, len(df))


# ---------------------------------------------------------------------------
# NumPy (.npy)
# ---------------------------------------------------------------------------

def download_npy(s3_path: str) -> np.ndarray:
    """Read a .npy NumPy array from S3."""
    response = _client().get_object(Bucket=S3_BUCKET, Key=_key_for(s3_path))
    return np.load(io.BytesIO(response["Body"].read()))


def upload_npy(array: np.ndarray, s3_path: str):
    """Serialise a NumPy array as .npy and upload to S3."""
    buffer = io.BytesIO()
    np.save(buffer, array)
    buffer.seek(0)
    _client().put_object(Bucket=S3_BUCKET, Key=_key_for(s3_path), Body=buffer.getvalue())
    logger.info("Uploaded NumPy array to %s (shape: %s)", s3_path, array.shape)


# ---------------------------------------------------------------------------
# JSON
# ---------------------------------------------------------------------------

def download_json(s3_path: str):
    """Read a JSON object from S3 and return the parsed Python value."""
    response = _client().get_object(Bucket=S3_BUCKET, Key=_key_for(s3_path))
    return json.loads(response["Body"].read())


def upload_json(payload, s3_path: str, *, indent: int | None = None):
    """
    Serialise `payload` as JSON (UTF-8) and upload with the application/json
    content-type so consumers (browsers, CloudFront) detect the type cleanly.
    Pass `indent=2` for human-readable artifacts like cluster_results.json.
    """
    body = json.dumps(payload, indent=indent).encode("utf-8")
    _client().put_object(
        Bucket=S3_BUCKET,
        Key=_key_for(s3_path),
        Body=body,
        ContentType="application/json",
    )
    logger.info("Uploaded JSON to %s (%d bytes)", s3_path, len(body))
