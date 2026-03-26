# ============================================================
# AWS Provider Configuration
# ============================================================
# A single provider block targets the Tokyo region using a
# named CLI profile. Default tags are injected into every
# resource so cost allocation and ownership are always visible.
# ============================================================

provider "aws" {
  region  = var.aws_region
  profile = var.aws_profile

  default_tags {
    tags = local.common_tags
  }
}
