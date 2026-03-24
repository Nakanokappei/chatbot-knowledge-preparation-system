"""
Amazon Bedrock LLM client for Claude models.

Handles Claude API invocation via Bedrock with retry logic, rate limiting,
and structured JSON output. Used for cluster analysis and knowledge unit generation.
Supports model selection via pipeline_config (default: Haiku 4.5).

CTO directive: Prompt/Response logs are mandatory.
"""

import json
import logging
import time

import boto3
from botocore.exceptions import ClientError

from src.config import SQS_REGION  # Bedrock uses same region

logger = logging.getLogger(__name__)

# Default model: Haiku 4.5 (cost-effective, sufficient for classification/extraction)
# Use APAC inference profile for ap-northeast-1 region
# Users can override via pipeline_config["llm_model_id"] in the dashboard
DEFAULT_MODEL_ID = "jp.anthropic.claude-haiku-4-5-20251001-v1:0"
ANTHROPIC_VERSION = "bedrock-2023-05-31"

# Retry configuration
MAX_RETRIES = 3
BASE_DELAY = 2.0  # seconds


def get_bedrock_client():
    """Create a Bedrock Runtime client."""
    return boto3.client("bedrock-runtime", region_name=SQS_REGION)


def invoke_claude(
    prompt: str,
    max_tokens: int = 2048,
    temperature: float = 0.0,
    model_id: str = None,
    client=None,
) -> dict:
    """
    Invoke a Claude model via Bedrock and return the parsed JSON response.

    Args:
        prompt: The user message to send to Claude.
        max_tokens: Maximum tokens in the response.
        temperature: Sampling temperature (0.0 for deterministic).
        model_id: Bedrock model ID to use. Falls back to DEFAULT_MODEL_ID.
        client: Optional pre-created Bedrock client.

    Returns:
        A dict with:
          - "content": The raw text response from Claude.
          - "parsed_json": The parsed JSON object (if response is valid JSON).
          - "input_tokens": Number of input tokens used.
          - "output_tokens": Number of output tokens used.
          - "model_id": The model ID that was actually used.

    Raises:
        RuntimeError: After MAX_RETRIES failures.
    """
    if model_id is None:
        model_id = DEFAULT_MODEL_ID

    if client is None:
        client = get_bedrock_client()

    body = json.dumps({
        "anthropic_version": ANTHROPIC_VERSION,
        "max_tokens": max_tokens,
        "temperature": temperature,
        "messages": [
            {"role": "user", "content": prompt}
        ],
    })

    for attempt in range(MAX_RETRIES):
        try:
            response = client.invoke_model(
                modelId=model_id,
                body=body,
                contentType="application/json",
                accept="application/json",
            )

            result = json.loads(response["body"].read())

            # Extract response text
            content_text = ""
            for block in result.get("content", []):
                if block.get("type") == "text":
                    content_text += block["text"]

            # Extract token usage
            usage = result.get("usage", {})
            input_tokens = usage.get("input_tokens", 0)
            output_tokens = usage.get("output_tokens", 0)

            # Try to parse as JSON
            parsed_json = None
            try:
                # Handle case where response has markdown code fences
                json_text = content_text.strip()
                if json_text.startswith("```"):
                    # Remove code fences
                    lines = json_text.split("\n")
                    lines = [l for l in lines if not l.strip().startswith("```")]
                    json_text = "\n".join(lines)
                parsed_json = json.loads(json_text)
            except json.JSONDecodeError:
                logger.warning("Claude response is not valid JSON. Raw: %s", content_text[:200])

            return {
                "content": content_text,
                "parsed_json": parsed_json,
                "input_tokens": input_tokens,
                "output_tokens": output_tokens,
                "model_id": model_id,
            }

        except ClientError as e:
            error_code = e.response["Error"]["Code"]

            if error_code == "ThrottlingException":
                delay = BASE_DELAY * (2 ** attempt)
                logger.warning(
                    "Bedrock Claude throttled (attempt %d/%d). Waiting %.1fs...",
                    attempt + 1, MAX_RETRIES, delay,
                )
                time.sleep(delay)
                continue

            logger.error("Bedrock Claude API error: %s - %s", error_code, e)
            raise

        except Exception as e:
            delay = BASE_DELAY * (2 ** attempt)
            logger.warning(
                "Bedrock Claude call failed (attempt %d/%d): %s. Retrying in %.1fs...",
                attempt + 1, MAX_RETRIES, e, delay,
            )
            time.sleep(delay)

    raise RuntimeError(f"Bedrock Claude API failed after {MAX_RETRIES} retries")
