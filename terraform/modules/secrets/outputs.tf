# Secrets Manager Module — Outputs

output "db_secret_arn" {
  description = "ARN of the database credentials secret"
  value       = aws_secretsmanager_secret.database.arn
}

output "app_key_secret_arn" {
  description = "ARN of the application encryption key secret"
  value       = aws_secretsmanager_secret.app_key.arn
}

output "db_password" {
  description = "Generated database password (use only for RDS provisioning)"
  value       = random_password.database.result
  sensitive   = true
}
