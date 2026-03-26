# ============================================================
# Security Groups Module
# ============================================================
# Defines the network access control boundaries between the
# four tiers of the application stack:
#
#   Internet  -->  ALB  -->  App (ECS)  -->  RDS
#                            Worker (ECS) -->  RDS
#
# Each security group follows the principle of least privilege:
# only the ports and source groups strictly required are opened.
# ============================================================

# ----------------------------------------------------------
# ALB Security Group
# ----------------------------------------------------------
# The Application Load Balancer accepts HTTP traffic from the
# public internet on port 80. When a custom domain with ACM
# is configured, port 443 should be added here.
# ----------------------------------------------------------

resource "aws_security_group" "alb" {
  name        = "${var.name_prefix}-alb-sg"
  description = "Allow inbound HTTP to the Application Load Balancer"
  vpc_id      = var.vpc_id

  # Accept HTTP from anywhere
  ingress {
    description = "HTTP from internet"
    from_port   = 80
    to_port     = 80
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  # Allow all outbound so the ALB can reach ECS targets
  egress {
    description = "Allow all outbound"
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = merge(var.common_tags, {
    Name = "${var.name_prefix}-alb-sg"
  })
}

# ----------------------------------------------------------
# Application (Web) Security Group
# ----------------------------------------------------------
# The ECS web application tasks accept traffic only from the
# ALB on port 80. All outbound traffic is permitted so that
# the application can reach RDS, SQS, S3, and external APIs.
# ----------------------------------------------------------

resource "aws_security_group" "app" {
  name        = "${var.name_prefix}-app-sg"
  description = "Allow inbound HTTP from ALB to application containers"
  vpc_id      = var.vpc_id

  # Accept HTTP exclusively from the ALB security group
  ingress {
    description     = "HTTP from ALB"
    from_port       = 80
    to_port         = 80
    protocol        = "tcp"
    security_groups = [aws_security_group.alb.id]
  }

  # Permit all outbound for AWS service endpoints and APIs
  egress {
    description = "Allow all outbound"
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = merge(var.common_tags, {
    Name = "${var.name_prefix}-app-sg"
  })
}

# ----------------------------------------------------------
# Worker Security Group
# ----------------------------------------------------------
# Background workers pull jobs from SQS and write to RDS /
# S3; they do not receive inbound connections. Only outbound
# is required.
# ----------------------------------------------------------

resource "aws_security_group" "worker" {
  name        = "${var.name_prefix}-worker-sg"
  description = "Outbound-only access for background worker containers"
  vpc_id      = var.vpc_id

  # No ingress rules — workers are never contacted directly

  # Permit all outbound for RDS, SQS, S3, and external APIs
  egress {
    description = "Allow all outbound"
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = merge(var.common_tags, {
    Name = "${var.name_prefix}-worker-sg"
  })
}

# ----------------------------------------------------------
# RDS Security Group
# ----------------------------------------------------------
# The PostgreSQL database accepts connections only from the
# application and worker security groups on port 5432. No
# direct internet access is allowed.
# ----------------------------------------------------------

resource "aws_security_group" "rds" {
  name        = "${var.name_prefix}-rds-sg"
  description = "Allow PostgreSQL access from app and worker containers"
  vpc_id      = var.vpc_id

  # Accept PostgreSQL connections from the web application
  ingress {
    description     = "PostgreSQL from app containers"
    from_port       = 5432
    to_port         = 5432
    protocol        = "tcp"
    security_groups = [aws_security_group.app.id]
  }

  # Accept PostgreSQL connections from the background workers
  ingress {
    description     = "PostgreSQL from worker containers"
    from_port       = 5432
    to_port         = 5432
    protocol        = "tcp"
    security_groups = [aws_security_group.worker.id]
  }

  # Allow outbound (needed for RDS patching and monitoring)
  egress {
    description = "Allow all outbound"
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = merge(var.common_tags, {
    Name = "${var.name_prefix}-rds-sg"
  })
}
