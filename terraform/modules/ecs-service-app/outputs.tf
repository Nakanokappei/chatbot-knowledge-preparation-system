# =============================================================================
# ECS Service App Module — Outputs
# =============================================================================

output "service_name" {
  description = "Name of the ECS service, useful for deployment scripts."
  value       = aws_ecs_service.app.name
}

output "task_definition_arn" {
  description = "ARN of the latest app task definition revision."
  value       = aws_ecs_task_definition.app.arn
}
