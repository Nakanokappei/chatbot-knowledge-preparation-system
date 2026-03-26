# ============================================================
# VPC Module — Input Variables
# ============================================================

variable "vpc_cidr" {
  description = "CIDR block for the VPC"
  type        = string
}

variable "name_prefix" {
  description = "Naming prefix applied to all resources (e.g. kps-dev)"
  type        = string
}

variable "availability_zones" {
  description = "List of two availability zones for subnet placement"
  type        = list(string)
}

variable "common_tags" {
  description = "Map of tags inherited from the root module"
  type        = map(string)
  default     = {}
}
