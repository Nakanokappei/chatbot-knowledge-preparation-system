# SSM Parameter Store Module — Outputs
#
# Export all parameter ARNs as a map so that IAM policies can
# reference individual parameters or iterate over the full set.

output "parameter_arns" {
  description = "Map of parameter names to their ARNs"
  value = {
    sqs_queue_url = aws_ssm_parameter.sqs_queue_url.arn
    s3_bucket     = aws_ssm_parameter.s3_bucket.arn
    db_host       = aws_ssm_parameter.db_host.arn
    db_name       = aws_ssm_parameter.db_name.arn
    aws_region    = aws_ssm_parameter.aws_region.arn
  }
}
