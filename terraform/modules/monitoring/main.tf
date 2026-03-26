# =============================================================================
# Monitoring Module — CloudWatch Log Groups, Alarms, and SNS Alerts
# =============================================================================
# This module sets up the observability layer for the CKPS ECS deployment:
#
# - Log groups for the App and Worker ECS services, with a 30-day retention
#   policy to control costs while retaining enough history for debugging.
# - An SNS topic that serves as the central alarm notification channel.
# - CloudWatch alarms that detect critical failure conditions:
#     * RDS CPU saturation (> 80%)
#     * RDS storage exhaustion (< 5 GB free)
#     * Dead-letter queue accumulation (any message means a pipeline failure)
#     * App service unavailability (zero running tasks)
# =============================================================================

# ===========================================================================
# CloudWatch Log Groups
# ===========================================================================
# Each ECS service writes its stdout/stderr to a dedicated log group. The
# log group names follow the /ecs/<prefix>/<service> convention, which makes
# them easy to find in the console and reference from task definitions.

resource "aws_cloudwatch_log_group" "app" {
  name              = "/ecs/${var.name_prefix}/app"
  retention_in_days = 30

  tags = merge(var.common_tags, {
    Service = "app"
  })
}

resource "aws_cloudwatch_log_group" "worker" {
  name              = "/ecs/${var.name_prefix}/worker"
  retention_in_days = 30

  tags = merge(var.common_tags, {
    Service = "worker"
  })
}

# ===========================================================================
# SNS Topic — central alarm notification target
# ===========================================================================
# All CloudWatch alarms publish to this topic. Subscribers (email, Slack
# webhook, PagerDuty, etc.) are configured outside this module since they
# depend on team preferences and communication tooling.

resource "aws_sns_topic" "alerts" {
  name = "${var.name_prefix}-alerts"
  tags = var.common_tags
}

# ===========================================================================
# CloudWatch Alarms
# ===========================================================================

# ---------------------------------------------------------------------------
# RDS CPU Utilization — sustained high CPU indicates the database is under
# heavy load and may need a larger instance class or query optimization.
# ---------------------------------------------------------------------------
resource "aws_cloudwatch_metric_alarm" "rds_cpu_high" {
  alarm_name          = "${var.name_prefix}-rds-cpu-high"
  alarm_description   = "RDS CPU utilization exceeds 80% for 5 minutes."
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 2
  metric_name         = "CPUUtilization"
  namespace           = "AWS/RDS"
  period              = 300
  statistic           = "Average"
  threshold           = 80
  treat_missing_data  = "missing"

  dimensions = {
    DBInstanceIdentifier = var.rds_instance_id
  }

  alarm_actions = [aws_sns_topic.alerts.arn]
  ok_actions    = [aws_sns_topic.alerts.arn]

  tags = var.common_tags
}

# ---------------------------------------------------------------------------
# RDS Free Storage — alerts when available storage drops below 5 GB. This
# gives the team time to investigate before auto-scaling hits max_allocated
# or the database runs out of space entirely.
# ---------------------------------------------------------------------------
resource "aws_cloudwatch_metric_alarm" "rds_free_storage_low" {
  alarm_name          = "${var.name_prefix}-rds-free-storage-low"
  alarm_description   = "RDS free storage space is below 5 GB."
  comparison_operator = "LessThanThreshold"
  evaluation_periods  = 1
  metric_name         = "FreeStorageSpace"
  namespace           = "AWS/RDS"
  period              = 300
  statistic           = "Average"
  # 5 GB expressed in bytes (CloudWatch reports storage metrics in bytes)
  threshold          = 5368709120
  treat_missing_data = "missing"

  dimensions = {
    DBInstanceIdentifier = var.rds_instance_id
  }

  alarm_actions = [aws_sns_topic.alerts.arn]
  ok_actions    = [aws_sns_topic.alerts.arn]

  tags = var.common_tags
}

# ---------------------------------------------------------------------------
# SQS Dead-Letter Queue — any message in the DLQ means a pipeline job has
# failed its maximum retry attempts. Even a single message warrants
# investigation, so the threshold is zero.
# ---------------------------------------------------------------------------
resource "aws_cloudwatch_metric_alarm" "dlq_messages" {
  alarm_name          = "${var.name_prefix}-dlq-messages"
  alarm_description   = "Dead-letter queue contains messages — pipeline jobs have failed."
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 1
  metric_name         = "ApproximateNumberOfMessagesVisible"
  namespace           = "AWS/SQS"
  period              = 300
  statistic           = "Maximum"
  threshold           = 0
  treat_missing_data  = "notBreaching"

  dimensions = {
    QueueName = var.dlq_queue_name
  }

  alarm_actions = [aws_sns_topic.alerts.arn]
  ok_actions    = [aws_sns_topic.alerts.arn]

  tags = var.common_tags
}

# ---------------------------------------------------------------------------
# ECS App Running Count — fires when the App service has zero running tasks.
# This means the web application is completely unavailable to users.
# ---------------------------------------------------------------------------
resource "aws_cloudwatch_metric_alarm" "app_running_count" {
  alarm_name          = "${var.name_prefix}-app-running-count-low"
  alarm_description   = "App service has zero running tasks — application is unavailable."
  comparison_operator = "LessThanThreshold"
  evaluation_periods  = 1
  metric_name         = "RunningTaskCount"
  namespace           = "ECS/ContainerInsights"
  period              = 60
  statistic           = "Average"
  threshold           = 1
  treat_missing_data  = "breaching"

  dimensions = {
    ServiceName = var.app_service_name
    ClusterName = var.ecs_cluster_name
  }

  alarm_actions = [aws_sns_topic.alerts.arn]
  ok_actions    = [aws_sns_topic.alerts.arn]

  tags = var.common_tags
}
