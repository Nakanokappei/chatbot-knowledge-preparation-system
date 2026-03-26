# SSM Parameter Store Module — Runtime Configuration
#
# Stores non-secret runtime configuration values in AWS Systems Manager
# Parameter Store. ECS task definitions and application code read these
# parameters at startup to discover infrastructure endpoints (queue URL,
# bucket name, database host) without hard-coding them.
#
# All parameters share a common prefix so IAM policies can grant access
# to the entire parameter subtree with a single wildcard.

resource "aws_ssm_parameter" "sqs_queue_url" {
  name  = "/${var.name_prefix}/sqs-queue-url"
  type  = "String"
  value = var.sqs_queue_url

  description = "URL of the SQS pipeline queue"

  tags = var.common_tags
}

resource "aws_ssm_parameter" "s3_bucket" {
  name  = "/${var.name_prefix}/s3-bucket"
  type  = "String"
  value = var.s3_bucket

  description = "Name of the S3 data bucket"

  tags = var.common_tags
}

resource "aws_ssm_parameter" "db_host" {
  name  = "/${var.name_prefix}/db-host"
  type  = "String"
  value = var.db_host

  description = "Database hostname or endpoint"

  tags = var.common_tags
}

resource "aws_ssm_parameter" "db_name" {
  name  = "/${var.name_prefix}/db-name"
  type  = "String"
  value = var.db_name

  description = "Database name"

  tags = var.common_tags
}

resource "aws_ssm_parameter" "aws_region" {
  name  = "/${var.name_prefix}/aws-region"
  type  = "String"
  value = var.aws_region

  description = "AWS region for SDK clients"

  tags = var.common_tags
}
