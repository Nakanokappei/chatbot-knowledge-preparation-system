# =============================================================================
# ECS Cluster Module — Fargate-only cluster with Container Insights
# =============================================================================
# Creates an ECS cluster configured for serverless Fargate workloads.
# The default capacity provider strategy favours FARGATE_SPOT to minimise
# cost in development environments; production can override via variables.
# =============================================================================

# -----------------------------------------------------------------------------
# ECS Cluster
# Container Insights is enabled so CloudWatch collects task-level metrics
# (CPU, memory, network) without requiring a custom sidecar.
# -----------------------------------------------------------------------------
resource "aws_ecs_cluster" "this" {
  name = "${var.name_prefix}-cluster"

  setting {
    name  = "containerInsights"
    value = "enabled"
  }

  tags = merge(var.common_tags, {
    Name = "${var.name_prefix}-cluster"
  })
}

# -----------------------------------------------------------------------------
# Capacity Provider Strategy
# Both FARGATE and FARGATE_SPOT are registered. The default strategy uses
# FARGATE_SPOT (weight 1) for dev cost savings. Individual services can
# override the strategy if they need on-demand guarantees.
# -----------------------------------------------------------------------------
resource "aws_ecs_cluster_capacity_providers" "this" {
  cluster_name = aws_ecs_cluster.this.name

  capacity_providers = ["FARGATE", "FARGATE_SPOT"]

  default_capacity_provider_strategy {
    capacity_provider = "FARGATE_SPOT"
    weight            = 1
  }
}
