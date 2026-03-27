# DNS Module — Outputs

output "fqdn" {
  description = "Fully qualified domain name"
  value       = aws_route53_record.app.fqdn
}

output "https_listener_arn" {
  description = "ARN of the HTTPS listener"
  value       = aws_lb_listener.https.arn
}
