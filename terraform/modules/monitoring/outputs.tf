# =============================================================================
# Monitoring Module — Outputs
# =============================================================================

output "app_log_group_name" {
  description = "Name of the CloudWatch Log Group for the App ECS service. Reference this in the App task definition's awslogs configuration."
  value       = aws_cloudwatch_log_group.app.name
}

output "worker_log_group_name" {
  description = "Name of the CloudWatch Log Group for the Worker ECS service. Reference this in the Worker task definition's awslogs configuration."
  value       = aws_cloudwatch_log_group.worker.name
}

output "sns_topic_arn" {
  description = "ARN of the SNS topic that receives all CloudWatch alarm notifications."
  value       = aws_sns_topic.alerts.arn
}
