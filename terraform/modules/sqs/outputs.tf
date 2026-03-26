# SQS Module — Outputs
#
# Expose both URL (for sending/receiving) and ARN (for IAM policies)
# of the main queue and dead-letter queue.

output "queue_url" {
  description = "URL of the main pipeline queue"
  value       = aws_sqs_queue.pipeline.url
}

output "queue_arn" {
  description = "ARN of the main pipeline queue"
  value       = aws_sqs_queue.pipeline.arn
}

output "dlq_url" {
  description = "URL of the dead-letter queue"
  value       = aws_sqs_queue.pipeline_dlq.url
}

output "dlq_arn" {
  description = "ARN of the dead-letter queue"
  value       = aws_sqs_queue.pipeline_dlq.arn
}
