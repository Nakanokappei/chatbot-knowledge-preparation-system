# ============================================================
# Terraform and Provider Version Constraints
# ============================================================
# Pin the minimum Terraform CLI version and lock provider
# sources to the HashiCorp registry so that every team member
# resolves identical binaries.
# ============================================================

terraform {
  required_version = ">= 1.5"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.0"
    }
    random = {
      source  = "hashicorp/random"
      version = "~> 3.0"
    }
  }
}
