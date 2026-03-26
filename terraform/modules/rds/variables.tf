# =============================================================================
# RDS Module — Input Variables
# =============================================================================

variable "name_prefix" {
  description = "Naming prefix applied to all RDS resources for namespacing."
  type        = string
}

variable "common_tags" {
  description = "Map of tags applied to all resources created by this module."
  type        = map(string)
  default     = {}
}

variable "private_subnet_ids" {
  description = "List of private subnet IDs where the RDS instance will be placed."
  type        = list(string)
}

variable "rds_sg_id" {
  description = "Security group ID to attach to the RDS instance. Should allow inbound PostgreSQL traffic from ECS tasks."
  type        = string
}

variable "db_instance_class" {
  description = "RDS instance class (e.g. db.t4g.micro for dev, db.r6g.large for production)."
  type        = string
  default     = "db.t4g.micro"
}

variable "db_name" {
  description = "Name of the initial PostgreSQL database to create."
  type        = string
  default     = "ckps"
}

variable "db_username" {
  description = "Master username for the RDS instance."
  type        = string
}

variable "db_password" {
  description = "Master password for the RDS instance. Should be sourced from a secret."
  type        = string
  sensitive   = true
}
