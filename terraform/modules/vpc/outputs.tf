# ============================================================
# VPC Module — Outputs
# ============================================================
# Expose the identifiers that downstream modules (security
# groups, ALB, ECS, RDS) need for resource placement.
# ============================================================

output "vpc_id" {
  description = "ID of the created VPC"
  value       = aws_vpc.this.id
}

output "public_subnet_ids" {
  description = "List of public subnet IDs (for ALB, NAT Gateway)"
  value       = aws_subnet.public[*].id
}

output "private_subnet_ids" {
  description = "List of private subnet IDs (for ECS tasks, RDS)"
  value       = aws_subnet.private[*].id
}
