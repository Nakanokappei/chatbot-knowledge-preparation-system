# CDN Module — Input Variables

variable "name_prefix" {
  description = "Resource naming prefix (e.g. kps-dev)"
  type        = string
}

variable "s3_bucket_id" {
  description = "S3 bucket ID (name)"
  type        = string
}

variable "s3_bucket_arn" {
  description = "S3 bucket ARN"
  type        = string
}

variable "s3_bucket_regional_domain" {
  description = "S3 bucket regional domain name (e.g. bucket.s3.ap-northeast-1.amazonaws.com)"
  type        = string
}

variable "common_tags" {
  description = "Tags applied to every resource"
  type        = map(string)
  default     = {}
}
