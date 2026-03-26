# =============================================================================
# ECS Service — Worker (Python SQS consumer on Fargate Spot)
# =============================================================================
# Defines the Fargate task definition and ECS service for the background
# worker that polls SQS and processes pipeline jobs. The worker is not
# web-facing — it has no load balancer attachment and runs exclusively on
# FARGATE_SPOT to minimise cost for batch-style workloads.
# =============================================================================

# -----------------------------------------------------------------------------
# Task Definition
# A single "worker" container runs the Python entry point (src.main) in SQS
# polling mode. Database credentials are injected from Secrets Manager.
# The worker does not expose any ports — it only makes outbound connections
# to SQS, S3, and PostgreSQL.
# -----------------------------------------------------------------------------
resource "aws_ecs_task_definition" "worker" {
  family                   = "${var.name_prefix}-worker"
  requires_compatibilities = ["FARGATE"]
  network_mode             = "awsvpc"
  cpu                      = var.worker_cpu
  memory                   = var.worker_memory
  execution_role_arn       = var.execution_role_arn
  task_role_arn            = var.worker_task_role_arn

  container_definitions = jsonencode([
    {
      name      = "worker"
      image     = var.worker_image
      essential = true

      # Override the default container entrypoint to run the SQS poller.
      command = ["python", "-m", "src.main"]

      # Ship container stdout/stderr to CloudWatch Logs.
      logConfiguration = {
        logDriver = "awslogs"
        options = {
          "awslogs-group"         = var.log_group_name
          "awslogs-region"        = var.aws_region
          "awslogs-stream-prefix" = "worker"
        }
      }

      # Plain-text environment variables for the Python runtime.
      environment = [
        { name = "DB_HOST",            value = var.db_host },
        { name = "DB_PORT",            value = "5432" },
        { name = "DB_NAME",            value = var.db_name },
        { name = "SQS_QUEUE_URL",      value = var.sqs_queue_url },
        { name = "AWS_DEFAULT_REGION", value = var.aws_region },
        { name = "S3_BUCKET",          value = var.s3_bucket },
        { name = "POLL_MODE",          value = "sqs" },
      ]

      # Secrets injected from AWS Secrets Manager at task launch.
      secrets = [
        { name = "DB_USER",     valueFrom = "${var.db_secret_arn}:username::" },
        { name = "DB_PASSWORD", valueFrom = "${var.db_secret_arn}:password::" },
      ]
    }
  ])

  tags = merge(var.common_tags, {
    Name = "${var.name_prefix}-worker-task"
  })
}

# -----------------------------------------------------------------------------
# ECS Service
# Runs the worker on FARGATE_SPOT to take advantage of spare capacity
# pricing. The worker can tolerate brief downtime, so minimum healthy
# percent is 0 and maximum percent is 100 — during deployment the old task
# stops before the new one starts.
# -----------------------------------------------------------------------------
resource "aws_ecs_service" "worker" {
  name            = "${var.name_prefix}-worker"
  cluster         = var.cluster_id
  task_definition = aws_ecs_task_definition.worker.arn
  desired_count   = var.desired_count

  # Use Spot capacity exclusively for cost savings on background work.
  capacity_provider_strategy {
    capacity_provider = "FARGATE_SPOT"
    weight            = 1
  }

  # Place tasks in private subnets without public IP addresses.
  network_configuration {
    subnets          = var.private_subnet_ids
    security_groups  = [var.worker_sg_id]
    assign_public_ip = false
  }

  # No load_balancer block — the worker is not web-facing.

  # The worker can be briefly unavailable during deployments. Setting
  # minimum to 0 and maximum to 100 ensures a stop-then-start rollout
  # that avoids running two workers simultaneously.
  deployment_minimum_healthy_percent = 0
  deployment_maximum_percent         = 100

  tags = merge(var.common_tags, {
    Name = "${var.name_prefix}-worker-service"
  })
}
