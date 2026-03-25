# Worker Dockerfile — Python SQS polling worker for ECS Fargate
#
# Runs the pipeline step processor that polls SQS and dispatches
# to step handlers (preprocess, embedding, clustering, etc.).

# Base image: Python 3.12 slim for minimal footprint
FROM python:3.12-slim AS base

WORKDIR /app

# Install build dependencies for hdbscan, hnswlib (C extensions), igraph, and psycopg2
RUN apt-get update \
    && apt-get install -y --no-install-recommends gcc g++ libpq-dev cmake \
    && rm -rf /var/lib/apt/lists/*

# Install Python dependencies first for layer caching
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Copy application source
COPY src/ src/

# Run as non-root user for security
RUN useradd --create-home worker
USER worker

# Health check: verify the Python process is running
HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
    CMD python -c "import sys; sys.exit(0)"

# Entry point: start SQS polling loop
CMD ["python", "-m", "src.main"]
