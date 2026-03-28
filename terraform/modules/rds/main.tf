# =============================================================================
# RDS Module — PostgreSQL 17 with pgvector support
# =============================================================================
# Provisions an RDS PostgreSQL database for the CKPS application. The instance
# is placed in private subnets with no public access, encrypted at rest, and
# configured with autoscaling storage.
#
# For production: set multi_az=true, force_ssl=true, skip_final_snapshot=false.
#
# NOTE on pgvector: The pgvector extension is available on RDS PostgreSQL 17
# but must be explicitly enabled after the instance is created:
#   CREATE EXTENSION IF NOT EXISTS vector;
# =============================================================================

# ---------------------------------------------------------------------------
# DB Subnet Group — places the RDS instance in private subnets
# ---------------------------------------------------------------------------
# The subnet group ensures the database is only reachable from within the
# VPC. It spans multiple availability zones for Multi-AZ capability.

resource "aws_db_subnet_group" "this" {
  name       = "${var.name_prefix}-db-subnet-group"
  subnet_ids = var.private_subnet_ids

  tags = merge(var.common_tags, {
    Name = "${var.name_prefix}-db-subnet-group"
  })
}

# ---------------------------------------------------------------------------
# Custom Parameter Group — configurable SSL enforcement
# ---------------------------------------------------------------------------
# Dev: force_ssl=false for convenience (VPC-internal only).
# Prod: force_ssl=true to require encrypted connections.

resource "aws_db_parameter_group" "this" {
  name   = "${var.name_prefix}-pg17-params"
  family = "postgres17"

  parameter {
    name  = "rds.force_ssl"
    value = var.force_ssl ? "1" : "0"
  }

  tags = merge(var.common_tags, {
    Name = "${var.name_prefix}-pg17-params"
  })
}

# ---------------------------------------------------------------------------
# RDS Instance — PostgreSQL 17
# ---------------------------------------------------------------------------
# Key design decisions by environment:
#   Dev:  single-AZ, skip final snapshot, relaxed SSL
#   Prod: Multi-AZ, mandatory final snapshot, forced SSL, larger instance

resource "aws_db_instance" "this" {
  identifier = "${var.name_prefix}-postgres"

  # Engine configuration
  engine         = "postgres"
  engine_version = "17"

  # Instance sizing — parameterized so environments can use different tiers
  instance_class = var.db_instance_class

  # High availability — Multi-AZ for production failover
  multi_az = var.multi_az

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

  # Use the custom parameter group with configurable SSL
  parameter_group_name = aws_db_parameter_group.this.name

  # Backup and maintenance
  backup_retention_period = 7

  # Lifecycle — skip final snapshot for dev, require it for prod
  skip_final_snapshot       = var.skip_final_snapshot
  final_snapshot_identifier = var.skip_final_snapshot ? null : "${var.name_prefix}-final-snapshot"

  tags = merge(var.common_tags, {
    Name = "${var.name_prefix}-postgres"
  })
}
