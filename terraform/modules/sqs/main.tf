# SQS Module — Simple Queue Service
#
# Creates a main pipeline queue and a dead-letter queue (DLQ).
# The pipeline queue carries work items for long-running processing
# steps such as keyword extraction and clustering, so the visibility
# timeout is set high (15 minutes). Messages that fail delivery
# three times are routed to the DLQ for inspection.

resource "aws_sqs_queue" "pipeline_dlq" {
  name = "${var.name_prefix}-pipeline-dlq"

  # Retain failed messages for 14 days to allow investigation.
  message_retention_seconds = 1209600

  tags = var.common_tags
}

resource "aws_sqs_queue" "pipeline" {
  name = "${var.name_prefix}-pipeline"

  # Each pipeline step can run for up to 15 minutes, so the message
  # must remain invisible to other consumers for that duration.
  visibility_timeout_seconds = 900

  # Keep messages for up to 14 days if the worker is temporarily down.
  message_retention_seconds = 1209600

  # After 3 failed receive attempts, move the message to the DLQ
  # so it does not block the queue indefinitely.
  redrive_policy = jsonencode({
    deadLetterTargetArn = aws_sqs_queue.pipeline_dlq.arn
    maxReceiveCount     = 3
  })

  tags = var.common_tags
}
