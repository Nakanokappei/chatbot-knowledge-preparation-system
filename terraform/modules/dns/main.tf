# =============================================================================
# DNS Module — Route 53 ALIAS record + ACM certificate lookup + HTTPS listener
# =============================================================================
# Creates a DNS record pointing to the ALB and adds an HTTPS listener
# using an existing wildcard ACM certificate.

# Look up the existing hosted zone
data "aws_route53_zone" "this" {
  name         = var.hosted_zone_name
  private_zone = false
}

# Look up the existing wildcard certificate
data "aws_acm_certificate" "wildcard" {
  domain      = "*.${var.hosted_zone_name}"
  statuses    = ["ISSUED"]
  most_recent = true
}

# DNS ALIAS record pointing to the ALB
resource "aws_route53_record" "app" {
  zone_id = data.aws_route53_zone.this.zone_id
  name    = var.subdomain
  type    = "A"

  alias {
    name                   = var.alb_dns_name
    zone_id                = var.alb_zone_id
    evaluate_target_health = true
  }
}

# HTTPS listener on the ALB
resource "aws_lb_listener" "https" {
  load_balancer_arn = var.alb_arn
  port              = 443
  protocol          = "HTTPS"
  ssl_policy        = "ELBSecurityPolicy-TLS13-1-2-2021-06"
  certificate_arn   = data.aws_acm_certificate.wildcard.arn

  default_action {
    type             = "forward"
    target_group_arn = var.target_group_arn
  }

  tags = var.common_tags
}

# Redirect HTTP to HTTPS
resource "aws_lb_listener_rule" "http_redirect" {
  listener_arn = var.http_listener_arn
  priority     = 1

  action {
    type = "redirect"
    redirect {
      port        = "443"
      protocol    = "HTTPS"
      status_code = "HTTP_301"
    }
  }

  condition {
    path_pattern {
      values = ["/*"]
    }
  }
}
