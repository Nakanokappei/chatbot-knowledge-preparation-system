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

# Lifecycle rule: delete objects after 90 days. CSV data uploaded
# for processing is temporary and should not persist indefinitely.

resource "aws_s3_bucket_lifecycle_configuration" "this" {
  bucket = aws_s3_bucket.this.id

  rule {
    id     = "expire-temporary-data"
    status = "Enabled"

    filter {}

    expiration {
      days = 90
    }
  }
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
