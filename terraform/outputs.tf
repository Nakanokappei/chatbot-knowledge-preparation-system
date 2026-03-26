# ============================================================
# Root Module Outputs
# ============================================================
# Surface the most operationally relevant values so that CI/CD
# pipelines, developers, and companion Terraform configurations
# can consume them without digging into state.
# ============================================================

# ----------------------------------------------------------
# Networking
# ----------------------------------------------------------

output "vpc_id" {
  description = "ID of the project VPC"
  value       = module.vpc.vpc_id
}

# ----------------------------------------------------------
# Load Balancer
# ----------------------------------------------------------

output "alb_dns_name" {
  description = "Public DNS name of the Application Load Balancer"
  value       = module.alb.alb_dns_name
}

# ----------------------------------------------------------
# Database
# ----------------------------------------------------------

output "rds_endpoint" {
  description = "Connection endpoint for the RDS PostgreSQL instance"
  value       = module.rds.endpoint
}

# ----------------------------------------------------------
# Container Registry
# ----------------------------------------------------------

output "ecr_app_repository_url" {
  description = "ECR repository URL for the web application image"
  value       = module.ecr.app_repository_url
}

output "ecr_worker_repository_url" {
  description = "ECR repository URL for the worker image"
  value       = module.ecr.worker_repository_url
}

# ----------------------------------------------------------
# Messaging
# ----------------------------------------------------------

output "sqs_queue_url" {
  description = "URL of the SQS queue used for background job dispatch"
  value       = module.sqs.queue_url
}

# ----------------------------------------------------------
# Storage
# ----------------------------------------------------------

output "csv_bucket_name" {
  description = "S3 bucket name for CSV file uploads"
  value       = module.s3.bucket_name
}
