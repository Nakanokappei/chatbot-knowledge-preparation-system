# S3 Module — Object Storage for Pipeline Data
#
# Creates a private S3 bucket for temporary pipeline artifacts such as
# uploaded CSV files and intermediate processing results. All public
# access is blocked, objects are encrypted at rest, and a lifecycle
# rule automatically deletes objects after 90 days.

resource "aws_s3_bucket" "this" {
  bucket = var.bucket_name

  tags = var.common_tags
}

# Block every form of public access at the bucket level.
# This is a defense-in-depth measure on top of the default
# private ACL to ensure no accidental public exposure.

resource "aws_s3_bucket_public_access_block" "this" {
  bucket = aws_s3_bucket.this.id

  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

# Enable default server-side encryption using AES-256 (SSE-S3).
# Every object stored in this bucket is encrypted automatically.

resource "aws_s3_bucket_server_side_encryption_configuration" "this" {
  bucket = aws_s3_bucket.this.id

  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
  }
}

# Lifecycle rules — per-prefix retention.
#
# Replaces the previous bucket-wide 90-day rule, which would have deleted
# active dataset CSVs and embedding cache entries together with worker
# scratch data. Now each prefix gets a policy aligned with its purpose.
#
# Note: when multiple lifecycle rules match an object, S3 picks the
# shortest expiration. That's why we removed the catch-all rule — having
# both a 90-day catch-all and a 365-day cache rule would still expire the
# cache at 90 days. Each prefix below is the only rule that matches it.

resource "aws_s3_bucket_lifecycle_configuration" "this" {
  bucket = aws_s3_bucket.this.id

  # Embedding cache: kept long enough to absorb routine pipeline re-runs
  # (parameter sweeps, knowledge mapping changes), but eventually purged
  # so abandoned per-text fingerprints don't accumulate forever. Cache
  # misses fall back to Bedrock automatically (see embedding.py).
  rule {
    id     = "expire-embedding-cache"
    status = "Enabled"

    filter {
      prefix = "cache/embeddings/"
    }

    expiration {
      days = 365
    }
  }

  # Uploaded CSVs (csv-uploads/) are intentionally NOT expired by S3.
  # Their lifecycle is owned by the app:
  #   - DatasetWizardController::destroy deletes stored_path + raw_path
  #     when a dataset is removed.
  #   - kps:cleanup-orphan-csv artisan command sweeps anything that
  #     escapes that path (legacy uploads, abandoned configures).
  # If a CSV stays here, it's tied to a live dataset that the user is
  # still working with — auto-deleting would break re-encoding flows.

  # Worker scratch artifacts (`{workspace_id}/jobs/{job_id}/...`) are
  # not yet covered by a lifecycle rule. They include embeddings.npy
  # (needed for re-clustering on the same embedding) so a blanket
  # expiration would silently break the "Use these params" workflow.
  # Tracked as future work — likely needs a per-object tag added by the
  # worker so we can target only safely-deletable artifacts.
}

# CORS configuration: allow PUT from any origin so that browser-based
# direct uploads via presigned URLs work without a proxy.

resource "aws_s3_bucket_cors_configuration" "this" {
  bucket = aws_s3_bucket.this.id

  cors_rule {
    allowed_headers = ["*"]
    allowed_methods = ["PUT"]
    allowed_origins = ["*"]
    max_age_seconds = 3600
  }
}
