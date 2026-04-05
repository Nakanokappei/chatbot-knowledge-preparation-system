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

variable "multi_az" {
  description = "Enable Multi-AZ for RDS. Use true for production."
  type        = bool
  default     = false
}

variable "force_ssl" {
  description = "Require SSL for all RDS connections. Use true for production."
  type        = bool
  default     = false
}

variable "skip_final_snapshot" {
  description = "Skip final DB snapshot on deletion. Use false for production."
  type        = bool
  default     = true
}

# ----------------------------------------------------------
# Networking — NAT Gateway
# ----------------------------------------------------------

variable "nat_gateway_count" {
  description = "Number of NAT Gateways (1 for dev, 2 for prod HA)"
  type        = number
  default     = 1
}

# ----------------------------------------------------------
# WAF
# ----------------------------------------------------------

variable "enable_waf" {
  description = "Enable AWS WAF on the ALB. Use true for production."
  type        = bool
  default     = false
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
# Network Access Control
# ----------------------------------------------------------

variable "allowed_cidr_blocks" {
  description = "CIDR blocks allowed to access the ALB. Empty list means open to all."
  type        = list(string)
  default     = []
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
  description = "Custom domain for the ALB, e.g. 'demo02.poc-pxt.com' (leave empty to skip)"
  type        = string
  default     = ""
}

variable "hosted_zone_name" {
  description = "Root domain of the Route 53 hosted zone, e.g. 'poc-pxt.com'"
  type        = string
  default     = ""
}

# ----------------------------------------------------------
# CI/CD
# ----------------------------------------------------------

variable "github_repo" {
  description = "GitHub repository in 'owner/repo' format for OIDC deploy role (e.g. 'Nakanokappei/chatbot-knowledge-preparation-system')"
  type        = string
  default     = ""
}

# ----------------------------------------------------------
# OpenAI (optional)
# ----------------------------------------------------------

variable "openai_api_key_secret_arn" {
  description = "ARN of Secrets Manager secret for the OpenAI API key. Leave empty to skip."
  type        = string
  default     = ""
}
