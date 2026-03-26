# =============================================================================
# RDS Module — PostgreSQL 17 with pgvector support
# =============================================================================
# Provisions a single-instance RDS PostgreSQL database for the CKPS
# application. The instance is placed in private subnets with no public
# access, encrypted at rest, and configured with autoscaling storage.
#
# NOTE on pgvector: The pgvector extension is available on RDS PostgreSQL 17
# but must be explicitly enabled after the instance is created. After the
# first `terraform apply`, connect to the database and run:
#
#   CREATE EXTENSION IF NOT EXISTS vector;
#
# This is intentionally left as a manual step rather than a provisioner
# because it requires network access to the private RDS instance (e.g. via
# a bastion host or VPN) and is a one-time operation.
# =============================================================================

# ---------------------------------------------------------------------------
# DB Subnet Group — places the RDS instance in private subnets
# ---------------------------------------------------------------------------
# The subnet group ensures the database is only reachable from within the
# VPC. It spans multiple availability zones for the underlying multi-AZ
# capability even though we use a single instance in dev.

resource "aws_db_subnet_group" "this" {
  name       = "${var.name_prefix}-db-subnet-group"
  subnet_ids = var.private_subnet_ids

  tags = merge(var.common_tags, {
    Name = "${var.name_prefix}-db-subnet-group"
  })
}

# ---------------------------------------------------------------------------
# Custom Parameter Group — dev-friendly SSL configuration
# ---------------------------------------------------------------------------
# For development simplicity, SSL enforcement is disabled. In production
# this parameter should be set to 1 to require encrypted connections.

resource "aws_db_parameter_group" "this" {
  name   = "${var.name_prefix}-pg17-params"
  family = "postgres17"

  # Disable forced SSL for development environments. Connections from ECS
  # tasks within the same VPC do not traverse the public internet, so the
  # risk is acceptable for a dev/staging setup.
  parameter {
    name  = "rds.force_ssl"
    value = "0"
  }

  tags = merge(var.common_tags, {
    Name = "${var.name_prefix}-pg17-params"
  })
}

# ---------------------------------------------------------------------------
# RDS Instance — PostgreSQL 17
# ---------------------------------------------------------------------------
# A single-instance deployment suitable for development and staging. Key
# design decisions:
#   - Storage auto-scaling from 20 GB up to 100 GB prevents outages from
#     unexpected data growth.
#   - 7-day backup retention provides a reasonable recovery window.
#   - skip_final_snapshot is true for dev teardown convenience; set to false
#     and provide a final_snapshot_identifier for production.

resource "aws_db_instance" "this" {
  identifier = "${var.name_prefix}-postgres"

  # Engine configuration
  engine         = "postgres"
  engine_version = "17"

  # Instance sizing — parameterized so environments can use different tiers
  instance_class = var.db_instance_class

  # Storage configuration with auto-scaling
  allocated_storage     = 20
  max_allocated_storage = 100
  storage_type          = "gp3"
  storage_encrypted     = true

  # Database credentials and initial database name
  db_name  = var.db_name
  username = var.db_username
  password = var.db_password

  # Network placement — private subnets only, no public endpoint
  db_subnet_group_name   = aws_db_subnet_group.this.name
  vpc_security_group_ids = [var.rds_sg_id]
  publicly_accessible    = false

  # Use the custom parameter group with relaxed SSL for dev
  parameter_group_name = aws_db_parameter_group.this.name

  # Backup and maintenance
  backup_retention_period = 7

  # Lifecycle — skip final snapshot for easy teardown in dev. Override this
  # in production by setting skip_final_snapshot = false and providing
  # final_snapshot_identifier.
  skip_final_snapshot = true

  tags = merge(var.common_tags, {
    Name = "${var.name_prefix}-postgres"
  })
}
