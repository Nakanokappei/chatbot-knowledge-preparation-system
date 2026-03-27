# ============================================================
# Development Environment Overrides
# ============================================================
# Usage:
#   terraform plan  -var-file=envs/dev.tfvars
#   terraform apply -var-file=envs/dev.tfvars
# ============================================================

environment          = "dev"

# Use the smallest Graviton-based instance for cost savings
db_instance_class    = "db.t4g.micro"

# Minimal compute for the web application container
app_cpu              = 256
app_memory           = 512

# The worker needs more headroom for embedding / clustering
worker_cpu           = 1024
worker_memory        = 2048

# Single replica is sufficient in development
app_desired_count    = 1
worker_desired_count = 1

# Restrict ALB access to office networks only
allowed_cidr_blocks = [
  "162.120.184.20/30",
  "203.114.29.108/30",
  "124.35.118.208/29",
  "124.35.235.160/29",
  "220.216.68.148/30",
  "24.239.132.20/31",
  "24.239.141.22/31",
]

# Custom domain + HTTPS
domain_name      = "demo02.poc-pxt.com"
hosted_zone_name = "poc-pxt.com"

# GitHub Actions CI/CD
github_repo = "Nakanokappei/chatbot-knowledge-preparation-system"
