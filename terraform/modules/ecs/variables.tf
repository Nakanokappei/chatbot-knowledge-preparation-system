# =============================================================================
# ECS Cluster Module — Input Variables
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
