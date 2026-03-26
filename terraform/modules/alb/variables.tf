# =============================================================================
# ALB Module — Input Variables
# =============================================================================

variable "name_prefix" {
  description = "Naming prefix applied to all resources for environment isolation."
  type        = string
}

variable "common_tags" {
  description = "Map of tags applied to every resource in this module."
  type        = map(string)
  default     = {}
}

variable "vpc_id" {
  description = "VPC ID where the target group is created."
  type        = string
}

variable "public_subnet_ids" {
  description = "List of public subnet IDs for the ALB placement."
  type        = list(string)
}

variable "alb_sg_id" {
  description = "Security group ID attached to the ALB."
  type        = string
}
