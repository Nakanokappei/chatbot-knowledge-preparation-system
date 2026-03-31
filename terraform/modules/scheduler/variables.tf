# ============================================================
# Scheduler module — input variables
# ============================================================

variable "name_prefix" {
  description = "Resource name prefix shared with all other modules (e.g. kps-dev)."
  type        = string
}

variable "aws_region" {
  description = "AWS region where the Lambda functions and schedules are deployed."
  type        = string
}

variable "rds_identifier" {
  description = "RDS DB instance identifier to start/stop (e.g. kps-dev-postgres)."
  type        = string
}

variable "ecs_cluster" {
  description = "ECS cluster name that hosts the app and worker services."
  type        = string
}

variable "ecs_services" {
  description = "List of ECS service names to start/stop (e.g. [kps-dev-app, kps-dev-worker])."
  type        = list(string)
}

variable "common_tags" {
  description = "Tags applied to every resource created by this module."
  type        = map(string)
  default     = {}
}
