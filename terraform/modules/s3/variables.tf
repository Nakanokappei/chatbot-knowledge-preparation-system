# S3 Module — Input Variables

variable "bucket_name" {
  description = "Globally unique S3 bucket name"
  type        = string
}

variable "name_prefix" {
  description = "Resource naming prefix, typically project-environment (e.g. ckps-prod)"
  type        = string
}

variable "common_tags" {
  description = "Tags applied to every resource in this module"
  type        = map(string)
  default     = {}
}

# Browser origins allowed to PUT directly to the bucket via presigned URLs.
# Empty default falls back to ["*"] for local development, but any publicly
# reachable environment MUST override this with an explicit allowlist so a
# stolen presigned URL cannot be replayed from an attacker-controlled page.
variable "cors_allowed_origins" {
  description = "CORS allowed_origins list for presigned PUT uploads. Use explicit HTTPS origins in production."
  type        = list(string)
  default     = ["*"]
}
