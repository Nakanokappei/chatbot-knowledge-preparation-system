# ============================================================
# Security Groups Module — Outputs
# ============================================================
# Expose security group IDs so that downstream modules (ALB,
# ECS, RDS) can reference them for network binding.
# ============================================================

output "alb_sg_id" {
  description = "Security group ID for the Application Load Balancer"
  value       = aws_security_group.alb.id
}

output "app_sg_id" {
  description = "Security group ID for web application ECS tasks"
  value       = aws_security_group.app.id
}

output "worker_sg_id" {
  description = "Security group ID for background worker ECS tasks"
  value       = aws_security_group.worker.id
}

output "rds_sg_id" {
  description = "Security group ID for the RDS PostgreSQL instance"
  value       = aws_security_group.rds.id
}
