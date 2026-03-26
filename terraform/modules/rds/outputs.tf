# =============================================================================
# RDS Module — Outputs
# =============================================================================

output "endpoint" {
  description = "Full connection endpoint including port (e.g. mydb.abc123.us-east-1.rds.amazonaws.com:5432)."
  value       = aws_db_instance.this.endpoint
}

output "address" {
  description = "Hostname of the RDS instance without port."
  value       = aws_db_instance.this.address
}

output "port" {
  description = "Port number the PostgreSQL instance is listening on."
  value       = aws_db_instance.this.port
}

output "db_name" {
  description = "Name of the database created on the instance."
  value       = aws_db_instance.this.db_name
}
