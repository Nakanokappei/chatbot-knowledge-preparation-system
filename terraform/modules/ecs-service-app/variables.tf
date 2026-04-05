# =============================================================================
# ECS Service App Module — Input Variables
# =============================================================================

# -- Naming and tagging -------------------------------------------------------

variable "name_prefix" {
  description = "Naming prefix applied to all resources for environment isolation."
  type        = string
}

variable "common_tags" {
  description = "Map of tags applied to every resource in this module."
  type        = map(string)
  default     = {}
}

variable "environment" {
  description = "Application environment name (e.g. dev, staging, production)."
  type        = string
}

# -- Cluster reference --------------------------------------------------------

variable "cluster_id" {
  description = "ID of the ECS cluster where the service is deployed."
  type        = string
}

# -- IAM roles ----------------------------------------------------------------

variable "execution_role_arn" {
  description = "ARN of the ECS task execution role (pulls images, fetches secrets)."
  type        = string
}

variable "app_task_role_arn" {
  description = "ARN of the IAM role assumed by the running app container."
  type        = string
}

# -- Container image and sizing -----------------------------------------------

variable "app_image" {
  description = "Full ECR image URI including tag for the app container."
  type        = string
}

variable "app_cpu" {
  description = "CPU units for the Fargate task (e.g. 256, 512, 1024)."
  type        = number
}

variable "app_memory" {
  description = "Memory in MiB for the Fargate task (e.g. 512, 1024, 2048)."
  type        = number
}

variable "desired_count" {
  description = "Number of app task replicas to run."
  type        = number
}

# -- Networking ---------------------------------------------------------------

variable "private_subnet_ids" {
  description = "List of private subnet IDs for task placement."
  type        = list(string)
}

variable "app_sg_id" {
  description = "Security group ID attached to the app tasks."
  type        = string
}

variable "target_group_arn" {
  description = "ALB target group ARN to register app tasks with."
  type        = string
}

# -- Logging ------------------------------------------------------------------

variable "log_group_name" {
  description = "CloudWatch Logs group name for container logs."
  type        = string
}

# -- Database -----------------------------------------------------------------

variable "db_host" {
  description = "RDS endpoint hostname."
  type        = string
}

variable "db_name" {
  description = "Name of the PostgreSQL database."
  type        = string
}

variable "db_secret_arn" {
  description = "ARN of the Secrets Manager secret containing DB credentials JSON."
  type        = string
}

# -- Application secrets ------------------------------------------------------

variable "app_key_secret_arn" {
  description = "ARN of the Secrets Manager secret containing the Laravel APP_KEY."
  type        = string
}

# -- AWS services -------------------------------------------------------------

variable "sqs_queue_url" {
  description = "URL of the SQS queue used by the application."
  type        = string
}

variable "s3_bucket" {
  description = "Name of the S3 bucket for file storage."
  type        = string
}

variable "aws_region" {
  description = "AWS region for log configuration and SDK default region."
  type        = string
}

variable "alb_dns_name" {
  description = "Public DNS name of the ALB, used to construct APP_URL."
  type        = string
}

variable "cdn_domain" {
  description = "CloudFront distribution domain for public asset URLs."
  type        = string
  default     = ""
}
