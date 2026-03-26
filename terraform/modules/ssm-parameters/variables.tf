# SSM Parameter Store Module — Input Variables

variable "name_prefix" {
  description = "Resource naming prefix, typically project-environment (e.g. ckps-prod)"
  type        = string
}

variable "sqs_queue_url" {
  description = "URL of the SQS pipeline queue"
  type        = string
}

variable "s3_bucket" {
  description = "Name of the S3 data bucket"
  type        = string
}

variable "db_host" {
  description = "Database hostname or RDS endpoint"
  type        = string
}

variable "db_name" {
  description = "Database name"
  type        = string
}

variable "aws_region" {
  description = "AWS region for SDK configuration"
  type        = string
}

variable "common_tags" {
  description = "Tags applied to every resource in this module"
  type        = map(string)
  default     = {}
}
