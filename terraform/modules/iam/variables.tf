# =============================================================================
# IAM Module — Input Variables
# =============================================================================

variable "name_prefix" {
  description = "Naming prefix applied to all IAM resources for namespacing."
  type        = string
}

variable "common_tags" {
  description = "Map of tags applied to all resources created by this module."
  type        = map(string)
  default     = {}
}

variable "secret_arns" {
  description = "List of Secrets Manager secret ARNs that the ECS task execution role is allowed to read. These are injected into containers at launch time."
  type        = list(string)
}

variable "parameter_arns" {
  description = "List of SSM Parameter Store parameter ARNs that the ECS task execution role is allowed to read."
  type        = list(string)
}

variable "sqs_queue_arn" {
  description = "ARN of the SQS queue used for pipeline jobs. The App role sends messages; the Worker role consumes them."
  type        = string
}

variable "s3_bucket_arn" {
  description = "ARN of the S3 bucket used for exports and intermediate pipeline data. Object-level permissions are granted on this bucket."
  type        = string
}

variable "github_repo" {
  description = "GitHub repository in 'owner/repo' format for OIDC deploy role. Empty string disables GitHub Actions integration."
  type        = string
  default     = ""
}

variable "ecr_repo_arns" {
  description = "List of ECR repository ARNs that the GitHub Actions deploy role can push to."
  type        = list(string)
  default     = []
}

variable "ecs_cluster_arn" {
  description = "ARN of the ECS cluster that the GitHub Actions deploy role can update services in."
  type        = string
  default     = ""
}
