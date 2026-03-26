# =============================================================================
# IAM Module — Outputs
# =============================================================================

output "execution_role_arn" {
  description = "ARN of the shared ECS task execution role (used in task definition execution_role_arn)."
  value       = aws_iam_role.ecs_task_execution.arn
}

output "app_task_role_arn" {
  description = "ARN of the App task role (used in App task definition task_role_arn)."
  value       = aws_iam_role.app_task.arn
}

output "worker_task_role_arn" {
  description = "ARN of the Worker task role (used in Worker task definition task_role_arn)."
  value       = aws_iam_role.worker_task.arn
}
