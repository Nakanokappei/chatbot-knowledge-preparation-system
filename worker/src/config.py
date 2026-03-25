"""
Configuration module for the Python Worker.

Reads connection parameters from environment variables.
In production these are set via ECS task definition; locally via .env file.
"""

import os
from pathlib import Path

from dotenv import load_dotenv

# Load .env file from the worker directory (local development only)
env_path = Path(__file__).resolve().parent.parent / ".env"
load_dotenv(env_path)


# Database connection settings (RDS PostgreSQL in production, localhost for dev)
DB_HOST = os.environ.get("DB_HOST", "127.0.0.1")
DB_PORT = int(os.environ.get("DB_PORT", "5432"))
DB_NAME = os.environ.get("DB_NAME", "knowledge_prep")
DB_USER = os.environ.get("DB_USER", "nakanokappei")
DB_PASSWORD = os.environ.get("DB_PASSWORD", "")

# SQS settings
SQS_QUEUE_URL = os.environ.get("SQS_QUEUE_URL", "")
SQS_REGION = os.environ.get("SQS_REGION", "ap-northeast-1")
SQS_POLL_INTERVAL = int(os.environ.get("SQS_POLL_INTERVAL", "10"))

# S3 settings
S3_BUCKET = os.environ.get("S3_BUCKET", "knowledge-prep-data-dev")
S3_REGION = os.environ.get("S3_REGION", "ap-northeast-1")

# Worker settings
LOG_LEVEL = os.environ.get("LOG_LEVEL", "INFO")
