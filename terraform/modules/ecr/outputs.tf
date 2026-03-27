# ECR Module — Outputs
#
# Expose the full repository URLs so downstream modules (ECS task
# definitions, CI/CD pipelines) can reference the correct registry.

output "app_repository_url" {
  description = "Full URL of the app container repository"
  value       = aws_ecr_repository.app.repository_url
}

output "worker_repository_url" {
  description = "Full URL of the worker container repository"
  value       = aws_ecr_repository.worker.repository_url
}

output "app_repository_arn" {
  description = "ARN of the app container repository"
  value       = aws_ecr_repository.app.arn
}

output "worker_repository_arn" {
  description = "ARN of the worker container repository"
  value       = aws_ecr_repository.worker.arn
}
