# =============================================================================
# WAF Module — Input Variables
# =============================================================================

variable "name_prefix" {
  description = "Naming prefix applied to all WAF resources."
  type        = string
}

variable "common_tags" {
  description = "Map of tags applied to all resources."
  type        = map(string)
  default     = {}
}

variable "alb_arn" {
  description = "ARN of the Application Load Balancer to protect with WAF."
  type        = string
}
