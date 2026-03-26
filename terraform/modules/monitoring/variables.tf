# =============================================================================
# Monitoring Module — Input Variables
# =============================================================================

variable "name_prefix" {
  description = "Naming prefix applied to all monitoring resources for namespacing."
  type        = string
}

variable "common_tags" {
  description = "Map of tags applied to all resources created by this module."
  type        = map(string)
  default     = {}
}

variable "rds_instance_id" {
  description = "RDS instance identifier used in CloudWatch alarm dimensions (the 'identifier' attribute, not the ARN)."
  type        = string
}

variable "dlq_queue_name" {
  description = "Name of the SQS dead-letter queue. Used in the DLQ alarm dimension."
  type        = string
}

variable "app_service_name" {
  description = "Name of the ECS App service. Used in the running task count alarm dimension."
  type        = string
}

variable "ecs_cluster_name" {
  description = "Name of the ECS cluster hosting the services. Used in alarm dimensions."
  type        = string
}
