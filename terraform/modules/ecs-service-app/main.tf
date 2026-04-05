# =============================================================================
# ECS Service — App (Laravel web application on Fargate)
# =============================================================================
# Defines the Fargate task definition and ECS service for the web-facing
# Laravel application container. The service registers with the ALB target
# group so it can receive HTTP traffic through the load balancer.
# =============================================================================

# -----------------------------------------------------------------------------
# Task Definition
# A single "app" container runs the Laravel application image. Environment
# variables supply database coordinates, SQS queue URL, S3 bucket name, and
# the public APP_URL derived from the ALB DNS. Secrets (DB credentials and
# APP_KEY) are injected from Secrets Manager at container start.
# -----------------------------------------------------------------------------
resource "aws_ecs_task_definition" "app" {
  family                   = "${var.name_prefix}-app"
  requires_compatibilities = ["FARGATE"]
  network_mode             = "awsvpc"
  cpu                      = var.app_cpu
  memory                   = var.app_memory
  execution_role_arn       = var.execution_role_arn
  task_role_arn            = var.app_task_role_arn

  container_definitions = jsonencode([
    {
      name      = "app"
      image     = var.app_image
      essential = true

      # The container listens on port 80 for HTTP traffic from the ALB.
      portMappings = [
        {
          containerPort = 80
          protocol      = "tcp"
        }
      ]

      # Ship container stdout/stderr to CloudWatch Logs.
      logConfiguration = {
        logDriver = "awslogs"
        options = {
          "awslogs-group"         = var.log_group_name
          "awslogs-region"        = var.aws_region
          "awslogs-stream-prefix" = "app"
        }
      }

      # Plain-text environment variables for the Laravel runtime.
      environment = [
        { name = "APP_ENV",            value = var.environment },
        { name = "APP_DEBUG",          value = "true" },
        { name = "LOG_CHANNEL",        value = "stderr" },
        { name = "DB_CONNECTION",      value = "pgsql" },
        { name = "DB_HOST",            value = var.db_host },
        { name = "DB_PORT",            value = "5432" },
        { name = "DB_DATABASE",        value = var.db_name },
        { name = "SQS_QUEUE_URL",      value = var.sqs_queue_url },
        { name = "AWS_DEFAULT_REGION", value = var.aws_region },
        { name = "S3_BUCKET",          value = var.s3_bucket },
        { name = "CSV_DISK_DRIVER",   value = "s3" },
        { name = "CDN_DOMAIN",         value = var.cdn_domain },
        { name = "APP_URL",            value = "http://${var.alb_dns_name}" },
      ]

      # Secrets injected from AWS Secrets Manager at task launch.
      # The special "::key::" syntax extracts individual JSON keys.
      secrets = concat([
        { name = "DB_USERNAME", valueFrom = "${var.db_secret_arn}:username::" },
        { name = "DB_PASSWORD", valueFrom = "${var.db_secret_arn}:password::" },
        { name = "APP_KEY",     valueFrom = var.app_key_secret_arn },
      ], var.openai_api_key_secret_arn != "" ? [
        { name = "OPENAI_API_KEY", valueFrom = var.openai_api_key_secret_arn },
      ] : [])
    }
  ])

  tags = merge(var.common_tags, {
    Name = "${var.name_prefix}-app-task"
  })
}

# -----------------------------------------------------------------------------
# ECS Service
# Runs the desired number of app tasks behind the ALB. Tasks are placed in
# private subnets with no public IP — all internet egress goes through a NAT
# gateway. Rolling deployments keep at least 50 % of tasks healthy.
# -----------------------------------------------------------------------------
resource "aws_ecs_service" "app" {
  name            = "${var.name_prefix}-app"
  cluster         = var.cluster_id
  task_definition = aws_ecs_task_definition.app.arn
  desired_count   = var.desired_count
  launch_type     = "FARGATE"

  # Place tasks in private subnets without public IP addresses.
  network_configuration {
    subnets          = var.private_subnet_ids
    security_groups  = [var.app_sg_id]
    assign_public_ip = false
  }

  # Register tasks with the ALB target group on container port 80.
  load_balancer {
    target_group_arn = var.target_group_arn
    container_name   = "app"
    container_port   = 80
  }

  # Allow rolling deployments to temporarily halve the task count,
  # and permit up to double the desired count during transitions.
  deployment_minimum_healthy_percent = 50
  deployment_maximum_percent         = 200

  tags = merge(var.common_tags, {
    Name = "${var.name_prefix}-app-service"
  })
}
