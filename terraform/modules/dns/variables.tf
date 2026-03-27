# DNS Module — Input Variables

variable "hosted_zone_name" {
  description = "Root domain name of the Route 53 hosted zone (e.g. 'poc-pxt.com')"
  type        = string
}

variable "subdomain" {
  description = "Full subdomain to create (e.g. 'demo02.poc-pxt.com')"
  type        = string
}

variable "alb_dns_name" {
  description = "DNS name of the ALB to point the record to"
  type        = string
}

variable "alb_zone_id" {
  description = "Hosted zone ID of the ALB (for ALIAS record)"
  type        = string
}

variable "alb_arn" {
  description = "ARN of the ALB to attach the HTTPS listener to"
  type        = string
}

variable "target_group_arn" {
  description = "ARN of the target group for the HTTPS listener"
  type        = string
}

variable "http_listener_arn" {
  description = "ARN of the existing HTTP listener (for redirect rule)"
  type        = string
}

variable "common_tags" {
  description = "Tags applied to all resources"
  type        = map(string)
  default     = {}
}
