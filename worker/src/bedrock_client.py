"""
Backward-compatibility shim — redirects all imports to embedding_client.py.

This file exists so that any code still importing from src.bedrock_client
continues to work without changes.
"""

from src.embedding_client import (  # noqa: F401
    MODEL_ID,
    EMBEDDING_DIMENSION,
    generate_embedding,
    generate_embeddings_batch,
    get_bedrock_client,
)
