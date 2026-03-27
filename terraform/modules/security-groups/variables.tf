# ============================================================
# Security Groups Module — Input Variables
# ============================================================

variable "vpc_id" {
  description = "ID of the VPC where security groups are created"
  type        = string
}

variable "name_prefix" {
  description = "Naming prefix applied to all resources (e.g. kps-dev)"
  type        = string
}

variable "common_tags" {
  description = "Map of tags inherited from the root module"
  type        = map(string)
  default     = {}
}

variable "allowed_cidr_blocks" {
  description = "CIDR blocks allowed to access the ALB. Empty list means open to all (0.0.0.0/0)."
  type        = list(string)
  default     = []
}
