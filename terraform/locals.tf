# ============================================================
# Computed Local Values
# ============================================================
# Derived values that keep the rest of the configuration DRY.
# The name_prefix convention ensures every resource carries
# both the project slug and the environment label.
# ============================================================

locals {
  # Standard prefix used across all resource Name tags and
  # identifiers to guarantee uniqueness per environment.
  name_prefix = "${var.project_name}-${var.environment}"

  # Tags applied to every resource via the provider default_tags
  # block, ensuring consistent metadata for cost tracking and
  # operational visibility.
  common_tags = {
    Project     = var.project_name
    Environment = var.environment
    ManagedBy   = "terraform"
  }

  # The two availability zones used for high-availability
  # placement of subnets, RDS, and ECS tasks.
  availability_zones = ["${var.aws_region}a", "${var.aws_region}c"]

  # Allow callers to override the S3 bucket name; otherwise
  # derive it from the standard naming convention.
  csv_bucket_name = var.csv_bucket_name != "" ? var.csv_bucket_name : "${local.name_prefix}-csv-uploads"
}
