# ============================================================
# Scheduler module — weekday auto-start / auto-stop
# ============================================================
#
# Creates two Lambda functions (start, stop) and two
# EventBridge Scheduler schedules expressed in Asia/Tokyo time:
#
#   Weekday start : Mon-Fri 08:30 JST
#   Weekday stop  : Mon-Fri 19:30 JST
#
# Weekends and public holidays are not covered — the environment
# remains in whatever state it was left in on Friday evening.
#
# Resources created:
#   - aws_iam_role.lambda              (Lambda execution role)
#   - aws_iam_role_policy.lambda       (RDS + ECS + Logs permissions)
#   - aws_lambda_function.start        (kps-<env>-start)
#   - aws_lambda_function.stop         (kps-<env>-stop)
#   - aws_iam_role.scheduler           (EventBridge Scheduler role)
#   - aws_iam_role_policy.scheduler    (InvokeFunction on both Lambdas)
#   - aws_scheduler_schedule.start     (Mon-Fri 08:30 JST)
#   - aws_scheduler_schedule.stop      (Mon-Fri 19:30 JST)
# ============================================================

data "aws_caller_identity" "current" {}

# ------------------------------------------------------------------
# Package Lambda source files into zip archives
# ------------------------------------------------------------------

data "archive_file" "start" {
  type        = "zip"
  source_file = "${path.module}/lambda/kps_start.py"
  output_path = "${path.module}/lambda/kps_start.zip"
}

data "archive_file" "stop" {
  type        = "zip"
  source_file = "${path.module}/lambda/kps_stop.py"
  output_path = "${path.module}/lambda/kps_stop.zip"
}

# ------------------------------------------------------------------
# IAM: Lambda execution role with RDS + ECS + CloudWatch Logs
# ------------------------------------------------------------------

resource "aws_iam_role" "lambda" {
  name = "${var.name_prefix}-scheduler-lambda"

  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect    = "Allow"
      Principal = { Service = "lambda.amazonaws.com" }
      Action    = "sts:AssumeRole"
    }]
  })

  tags = var.common_tags
}

resource "aws_iam_role_policy" "lambda" {
  name = "${var.name_prefix}-scheduler-lambda"
  role = aws_iam_role.lambda.id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        # Start and stop the specific RDS instance only
        Effect   = "Allow"
        Action   = ["rds:StartDBInstance", "rds:StopDBInstance"]
        Resource = "arn:aws:rds:${var.aws_region}:${data.aws_caller_identity.current.account_id}:db:${var.rds_identifier}"
      },
      {
        # Update ECS services within the target cluster
        Effect   = "Allow"
        Action   = ["ecs:UpdateService"]
        Resource = "arn:aws:ecs:${var.aws_region}:${data.aws_caller_identity.current.account_id}:service/${var.ecs_cluster}/*"
      },
      {
        # Write Lambda execution logs to CloudWatch Logs
        Effect   = "Allow"
        Action   = ["logs:CreateLogGroup", "logs:CreateLogStream", "logs:PutLogEvents"]
        Resource = "arn:aws:logs:${var.aws_region}:${data.aws_caller_identity.current.account_id}:*"
      }
    ]
  })
}

# ------------------------------------------------------------------
# Lambda: kps-<env>-start — starts RDS then ECS services
# ------------------------------------------------------------------

resource "aws_lambda_function" "start" {
  filename         = data.archive_file.start.output_path
  source_code_hash = data.archive_file.start.output_base64sha256
  function_name    = "${var.name_prefix}-start"
  role             = aws_iam_role.lambda.arn
  handler          = "kps_start.handler"
  runtime          = "python3.12"
  # RDS start and ECS update are both async API calls — 30 s is ample
  timeout          = 30

  environment {
    variables = {
      REGION   = var.aws_region
      CLUSTER  = var.ecs_cluster
      RDS_ID   = var.rds_identifier
      SERVICES = join(",", var.ecs_services)
    }
  }

  tags = var.common_tags
}

# ------------------------------------------------------------------
# Lambda: kps-<env>-stop — stops ECS services then RDS
# ------------------------------------------------------------------

resource "aws_lambda_function" "stop" {
  filename         = data.archive_file.stop.output_path
  source_code_hash = data.archive_file.stop.output_base64sha256
  function_name    = "${var.name_prefix}-stop"
  role             = aws_iam_role.lambda.arn
  handler          = "kps_stop.handler"
  runtime          = "python3.12"
  timeout          = 30

  environment {
    variables = {
      REGION   = var.aws_region
      CLUSTER  = var.ecs_cluster
      RDS_ID   = var.rds_identifier
      SERVICES = join(",", var.ecs_services)
    }
  }

  tags = var.common_tags
}

# ------------------------------------------------------------------
# IAM: EventBridge Scheduler execution role (invoke both Lambdas)
# ------------------------------------------------------------------

resource "aws_iam_role" "scheduler" {
  name = "${var.name_prefix}-eventbridge-scheduler"

  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect    = "Allow"
      Principal = { Service = "scheduler.amazonaws.com" }
      Action    = "sts:AssumeRole"
    }]
  })

  tags = var.common_tags
}

resource "aws_iam_role_policy" "scheduler" {
  name = "${var.name_prefix}-eventbridge-scheduler"
  role = aws_iam_role.scheduler.id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect   = "Allow"
      Action   = ["lambda:InvokeFunction"]
      Resource = [aws_lambda_function.start.arn, aws_lambda_function.stop.arn]
    }]
  })
}

# ------------------------------------------------------------------
# EventBridge Scheduler — Weekday start (Mon-Fri 08:30 JST)
# ------------------------------------------------------------------

resource "aws_scheduler_schedule" "start" {
  name        = "${var.name_prefix}-weekday-start"
  group_name  = "default"
  description = "Start KPS every weekday at 08:30 JST (RDS + ECS)"

  # Fire at exactly the scheduled time — no flexibility window
  flexible_time_window { mode = "OFF" }

  # Cron expressed in Asia/Tokyo — no UTC offset arithmetic required
  schedule_expression          = "cron(30 8 ? * MON-FRI *)"
  schedule_expression_timezone = "Asia/Tokyo"

  target {
    arn      = aws_lambda_function.start.arn
    role_arn = aws_iam_role.scheduler.arn
  }
}

# ------------------------------------------------------------------
# EventBridge Scheduler — Weekday stop (Mon-Fri 19:30 JST)
# ------------------------------------------------------------------

resource "aws_scheduler_schedule" "stop" {
  name        = "${var.name_prefix}-weekday-stop"
  group_name  = "default"
  description = "Stop KPS every weekday at 19:30 JST (ECS first, then RDS)"

  flexible_time_window { mode = "OFF" }

  schedule_expression          = "cron(30 19 ? * MON-FRI *)"
  schedule_expression_timezone = "Asia/Tokyo"

  target {
    arn      = aws_lambda_function.stop.arn
    role_arn = aws_iam_role.scheduler.arn
  }
}
