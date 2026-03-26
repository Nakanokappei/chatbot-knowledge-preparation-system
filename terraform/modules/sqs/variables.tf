# SQS Module — Input Variables

variable "name_prefix" {
  description = "Resource naming prefix, typically project-environment (e.g. ckps-prod)"
  type        = string
}

variable "common_tags" {
  description = "Tags applied to every resource in this module"
  type        = map(string)
  default     = {}
}
