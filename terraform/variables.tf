# ============================================================
# Root Module Input Variables
# ============================================================
# Every tuneable knob lives here. Environment-specific values
# are supplied via envs/<env>.tfvars at plan/apply time.
# ============================================================

# ----------------------------------------------------------
# General
# ----------------------------------------------------------

variable "aws_region" {
  description = "AWS region where all resources are provisioned"
  type        = string
  default     = "ap-northeast-1"
}

variable "aws_profile" {
  description = "Named AWS CLI profile used for authentication"
  type        = string
  default     = "kps-company"
}

variable "environment" {
  description = "Deployment stage identifier (dev, staging, prod)"
  type        = string
  default     = "dev"
}

variable "project_name" {
  description = "Short project slug used in resource naming"
  type        = string
  default     = "kps"
}

# ----------------------------------------------------------
# Networking
# ----------------------------------------------------------

variable "vpc_cidr" {
  description = "CIDR block for the project VPC"
  type        = string
  default     = "10.0.0.0/16"
}

# ----------------------------------------------------------
# Database (RDS PostgreSQL)
# ----------------------------------------------------------

variable "db_instance_class" {
  description = "RDS instance size — use db.t4g.micro for dev"
  type        = string
  default     = "db.t4g.micro"
}

variable "db_name" {
  description = "Name of the initial PostgreSQL database"
  type        = string
  default     = "knowledge_prep"
}

variable "db_username" {
  description = "Master username for the RDS instance"
  type        = string
  default     = "ckps_admin"
}

# ----------------------------------------------------------
# ECS — Application Container
# ----------------------------------------------------------

variable "app_cpu" {
  description = "CPU units for the web application task (256 = 0.25 vCPU)"
  type        = number
  default     = 256
}

variable "app_memory" {
  description = "Memory in MiB for the web application task"
  type        = number
  default     = 512
}

variable "app_desired_count" {
  description = "Number of running web application task replicas"
  type        = number
  default     = 1
}

variable "app_image" {
  description = "Docker image URI for the web application (leave empty to use ECR)"
  type        = string
  default     = ""
}

# ----------------------------------------------------------
# ECS — Worker Container
# ----------------------------------------------------------

variable "worker_cpu" {
  description = "CPU units for the background worker task (1024 = 1 vCPU)"
  type        = number
  default     = 1024
}

variable "worker_memory" {
  description = "Memory in MiB for the background worker task"
  type        = number
  default     = 2048
}

variable "worker_desired_count" {
  description = "Number of running worker task replicas"
  type        = number
  default     = 1
}

variable "worker_image" {
  description = "Docker image URI for the worker (leave empty to use ECR)"
  type        = string
  default     = ""
}

# ----------------------------------------------------------
# Storage
# ----------------------------------------------------------

variable "csv_bucket_name" {
  description = "Override S3 bucket name for CSV uploads (auto-generated if empty)"
  type        = string
  default     = ""
}

# ----------------------------------------------------------
# DNS / TLS (optional)
# ----------------------------------------------------------

variable "domain_name" {
  description = "Custom domain for the ALB (leave empty to skip ACM/Route53)"
  type        = string
  default     = ""
}
