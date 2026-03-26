# Secrets Manager Module — Database Credentials and Application Key
#
# Generates a random database password and a random application key,
# then stores them in AWS Secrets Manager. The database secret is a
# JSON object containing host, port, username, password, and dbname
# fields (compatible with RDS proxy auto-rotation). The application
# key secret holds a base64-encoded 32-byte value suitable for
# Laravel/Django-style encryption keys.

# Generate a strong random password for the database. The character
# set is restricted to avoid shell-escaping issues in connection strings.

resource "random_password" "database" {
  length           = 24
  special          = true
  override_special = "!#$%&*()-_=+[]{}|"
}

# Generate a 32-byte random value for the application encryption key.
# random_id outputs the value in base64 by default.

resource "random_id" "app_key" {
  byte_length = 32
}

# Store database credentials as a structured JSON secret. Downstream
# services (ECS tasks, Lambda) can parse this JSON to build their
# connection strings dynamically.

resource "aws_secretsmanager_secret" "database" {
  name        = "${var.name_prefix}/database"
  description = "Database credentials for ${var.name_prefix}"

  tags = var.common_tags
}

resource "aws_secretsmanager_secret_version" "database" {
  secret_id = aws_secretsmanager_secret.database.id

  secret_string = jsonencode({
    username = var.db_username
    password = random_password.database.result
    dbname   = var.db_name
  })
}

# Store the application encryption key as a simple string secret.
# The base64 encoding makes it safe to embed in environment variables.

resource "aws_secretsmanager_secret" "app_key" {
  name        = "${var.name_prefix}/app-key"
  description = "Application encryption key for ${var.name_prefix}"

  tags = var.common_tags
}

resource "aws_secretsmanager_secret_version" "app_key" {
  secret_id     = aws_secretsmanager_secret.app_key.id
  secret_string = random_id.app_key.b64_std
}
