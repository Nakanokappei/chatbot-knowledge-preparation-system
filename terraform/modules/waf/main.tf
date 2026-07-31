# =============================================================================
# WAF Module — AWS WAFv2 Web ACL for ALB protection
# =============================================================================
# Attaches a Web ACL to the Application Load Balancer with AWS managed rule
# sets covering OWASP Top 10, known bad inputs, and SQL injection.
#
# CloudWatch metrics are enabled for each rule group so security events
# can be monitored and alerted on via the monitoring module.
# =============================================================================

# ---------------------------------------------------------------------------
# Web ACL — the main WAF resource
# ---------------------------------------------------------------------------
# Default action is ALLOW; managed rule groups BLOCK known threats.

resource "aws_wafv2_web_acl" "this" {
  name  = "${var.name_prefix}-waf"
  scope = "REGIONAL"

  default_action {
    allow {}
  }

  # OWASP Top 10 — covers XSS, path traversal, LFI/RFI, etc.
  rule {
    name     = "AWSManagedRulesCommonRuleSet"
    priority = 10

    override_action {
      none {}
    }

    statement {
      managed_rule_group_statement {
        name        = "AWSManagedRulesCommonRuleSet"
        vendor_name = "AWS"

        # SizeRestrictions_BODY blocks any request body over 8 KB, which is
        # incompatible with this product: CSV datasets (/dataset/upload) and
        # source documents (/documents) are uploaded at up to 50 MB. WAF can
        # only inspect the first 8 KB of a body in any case, so the rule adds
        # no inspection coverage here — it only rejects the upload outright.
        # Counted instead of blocked so the metric stays visible; every other
        # rule in the group, including the XSS and LFI body checks, keeps
        # blocking as before.
        rule_action_override {
          name = "SizeRestrictions_BODY"

          action_to_use {
            count {}
          }
        }
      }
    }

    visibility_config {
      cloudwatch_metrics_enabled = true
      metric_name                = "${var.name_prefix}-common-rules"
      sampled_requests_enabled   = true
    }
  }

  # Known bad inputs — Log4j, SSRF probes, etc.
  rule {
    name     = "AWSManagedRulesKnownBadInputsRuleSet"
    priority = 20

    override_action {
      none {}
    }

    statement {
      managed_rule_group_statement {
        name        = "AWSManagedRulesKnownBadInputsRuleSet"
        vendor_name = "AWS"
      }
    }

    visibility_config {
      cloudwatch_metrics_enabled = true
      metric_name                = "${var.name_prefix}-bad-inputs"
      sampled_requests_enabled   = true
    }
  }

  # SQL injection detection
  rule {
    name     = "AWSManagedRulesSQLiRuleSet"
    priority = 30

    override_action {
      none {}
    }

    statement {
      managed_rule_group_statement {
        name        = "AWSManagedRulesSQLiRuleSet"
        vendor_name = "AWS"
      }
    }

    visibility_config {
      cloudwatch_metrics_enabled = true
      metric_name                = "${var.name_prefix}-sqli"
      sampled_requests_enabled   = true
    }
  }

  visibility_config {
    cloudwatch_metrics_enabled = true
    metric_name                = "${var.name_prefix}-waf"
    sampled_requests_enabled   = true
  }

  tags = merge(var.common_tags, {
    Name = "${var.name_prefix}-waf"
  })
}

# ---------------------------------------------------------------------------
# Associate the Web ACL with the ALB
# ---------------------------------------------------------------------------

resource "aws_wafv2_web_acl_association" "alb" {
  resource_arn = var.alb_arn
  web_acl_arn  = aws_wafv2_web_acl.this.arn
}
