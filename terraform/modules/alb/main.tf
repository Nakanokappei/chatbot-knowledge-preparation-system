# =============================================================================
# ALB Module — Application Load Balancer for the web-facing ECS service
# =============================================================================
# Provisions a public ALB, an HTTP target group for Fargate tasks, and an
# HTTP listener that forwards traffic to the target group.
# =============================================================================

# -----------------------------------------------------------------------------
# Application Load Balancer
# Sits in public subnets and accepts inbound HTTP traffic from the internet.
# -----------------------------------------------------------------------------
resource "aws_lb" "this" {
  name               = "${var.name_prefix}-alb"
  internal           = false
  load_balancer_type = "application"
  subnets            = var.public_subnet_ids
  security_groups    = [var.alb_sg_id]

  tags = merge(var.common_tags, {
    Name = "${var.name_prefix}-alb"
  })
}

# -----------------------------------------------------------------------------
# Target Group
# Receives traffic from the ALB listener and routes it to Fargate task IPs.
# The health check hits the Rails/Laravel "/up" endpoint to confirm readiness.
# A short deregistration delay avoids long drains during deployments.
# -----------------------------------------------------------------------------
resource "aws_lb_target_group" "app" {
  name        = "${var.name_prefix}-app-tg"
  port        = 80
  protocol    = "HTTP"
  vpc_id      = var.vpc_id
  target_type = "ip"

  # Health check probes the application readiness endpoint.
  health_check {
    path                = "/up"
    interval            = 30
    healthy_threshold   = 2
    unhealthy_threshold = 3
  }

  # Keep deregistration short so rolling deploys finish quickly.
  deregistration_delay = 30

  tags = merge(var.common_tags, {
    Name = "${var.name_prefix}-app-tg"
  })
}

# -----------------------------------------------------------------------------
# HTTP Listener (port 80)
# Forwards all inbound HTTP requests to the app target group.
# In production this would be upgraded to HTTPS with an ACM certificate.
# -----------------------------------------------------------------------------
resource "aws_lb_listener" "http" {
  load_balancer_arn = aws_lb.this.arn
  port              = 80
  protocol          = "HTTP"

  default_action {
    type             = "forward"
    target_group_arn = aws_lb_target_group.app.arn
  }

  tags = merge(var.common_tags, {
    Name = "${var.name_prefix}-http-listener"
  })
}
