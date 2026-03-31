# ============================================================
# Remote State Backend — S3 + DynamoDB Locking
# ============================================================
# Terraform state is stored in an S3 bucket scoped to the AWS
# account, with DynamoDB-based locking to prevent concurrent
# applies from corrupting state.
#
# Prerequisites (create once, manually or via bootstrap script):
#   1. S3 bucket:     kps-terraform-state-891377034477
#   2. DynamoDB table: kps-terraform-lock (partition key = LockID)
# ============================================================

terraform {
  backend "s3" {
    bucket         = "kps-terraform-state-891377034477"
    key            = "kps/terraform.tfstate"
    region         = "ap-northeast-1"
    dynamodb_table = "kps-terraform-lock"
    encrypt        = true
    profile        = "kps-company"
  }
}
