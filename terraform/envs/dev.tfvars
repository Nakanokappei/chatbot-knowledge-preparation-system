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
