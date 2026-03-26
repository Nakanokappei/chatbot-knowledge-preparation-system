# Secrets Manager Module — Input Variables

variable "name_prefix" {
  description = "Resource naming prefix, typically project-environment (e.g. ckps-prod)"
  type        = string
}

variable "db_username" {
  description = "Database master username"
  type        = string
  default     = "ckps"
}

variable "db_name" {
  description = "Database name"
  type        = string
  default     = "ckps"
}

variable "common_tags" {
  description = "Tags applied to every resource in this module"
  type        = map(string)
  default     = {}
}
