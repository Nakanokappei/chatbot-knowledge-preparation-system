# =============================================================================
# ECS Cluster Module — Outputs
# =============================================================================

output "cluster_id" {
  description = "ID of the ECS cluster, passed to service modules."
  value       = aws_ecs_cluster.this.id
}

output "cluster_name" {
  description = "Name of the ECS cluster, useful for CLI and monitoring references."
  value       = aws_ecs_cluster.this.name
}

output "cluster_arn" {
  description = "ARN of the ECS cluster, used for IAM policy conditions."
  value       = aws_ecs_cluster.this.arn
}
