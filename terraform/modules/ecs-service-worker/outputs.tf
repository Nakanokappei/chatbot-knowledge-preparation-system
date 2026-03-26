# =============================================================================
# ECS Service Worker Module — Outputs
# =============================================================================

output "service_name" {
  description = "Name of the ECS service, useful for deployment scripts."
  value       = aws_ecs_service.worker.name
}

output "task_definition_arn" {
  description = "ARN of the latest worker task definition revision."
  value       = aws_ecs_task_definition.worker.arn
}
