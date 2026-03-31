# ============================================================
# Scheduler module — outputs
# ============================================================

output "start_lambda_arn" {
  description = "ARN of the weekday-start Lambda function."
  value       = aws_lambda_function.start.arn
}

output "stop_lambda_arn" {
  description = "ARN of the weekday-stop Lambda function."
  value       = aws_lambda_function.stop.arn
}

output "start_schedule_arn" {
  description = "ARN of the EventBridge Scheduler schedule that triggers start."
  value       = aws_scheduler_schedule.start.arn
}

output "stop_schedule_arn" {
  description = "ARN of the EventBridge Scheduler schedule that triggers stop."
  value       = aws_scheduler_schedule.stop.arn
}
