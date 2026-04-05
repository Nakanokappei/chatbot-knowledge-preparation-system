/*
 * main.tf — Root module that wires all child modules together.
 *
 * Module dependency order:
 *   VPC → Security Groups → (RDS, ALB, ECS Services)
 *   ECR, SQS, S3 → (ECS Services)
 *   Secrets → IAM → (ECS Services)
 *   Monitoring depends on ECS + RDS + SQS outputs
 */

# ------------------------------------------------------------------
# Phase 2: Network — VPC, subnets, NAT Gateway
# ------------------------------------------------------------------

module "vpc" {
  source = "./modules/vpc"

  vpc_cidr            = var.vpc_cidr
  name_prefix         = local.name_prefix
  availability_zones  = local.availability_zones
  nat_gateway_count   = var.nat_gateway_count
  common_tags         = local.common_tags
}

module "security_groups" {
  source = "./modules/security-groups"

  vpc_id              = module.vpc.vpc_id
  name_prefix         = local.name_prefix
  common_tags         = local.common_tags
  allowed_cidr_blocks = var.allowed_cidr_blocks
}

# ------------------------------------------------------------------
# Phase 3: Storage — ECR, SQS, S3
# ------------------------------------------------------------------

module "ecr" {
  source = "./modules/ecr"

  name_prefix = local.name_prefix
  common_tags = local.common_tags
}

module "sqs" {
  source = "./modules/sqs"

  name_prefix = local.name_prefix
  common_tags = local.common_tags
}

module "s3" {
  source = "./modules/s3"

  bucket_name = local.csv_bucket_name
  name_prefix = local.name_prefix
  common_tags = local.common_tags
}

module "cdn" {
  source = "./modules/cdn"

  name_prefix               = local.name_prefix
  s3_bucket_id              = module.s3.bucket_name
  s3_bucket_arn             = module.s3.bucket_arn
  s3_bucket_regional_domain = module.s3.bucket_regional_domain_name
  common_tags               = local.common_tags
}

# ------------------------------------------------------------------
# Phase 4: Secrets — Secrets Manager + SSM Parameter Store
# ------------------------------------------------------------------

module "secrets" {
  source = "./modules/secrets"

  name_prefix = local.name_prefix
  db_username = var.db_username
  db_name     = var.db_name
  common_tags = local.common_tags
}

module "ssm_parameters" {
  source = "./modules/ssm-parameters"

  name_prefix    = local.name_prefix
  sqs_queue_url  = module.sqs.queue_url
  s3_bucket      = module.s3.bucket_name
  db_host        = module.rds.address
  db_name        = var.db_name
  aws_region     = var.aws_region
  common_tags    = local.common_tags
}

# ------------------------------------------------------------------
# Phase 5: IAM — Execution role + App/Worker task roles
# ------------------------------------------------------------------

module "iam" {
  source = "./modules/iam"

  name_prefix     = local.name_prefix
  common_tags     = local.common_tags
  secret_arns     = [module.secrets.db_secret_arn, module.secrets.app_key_secret_arn]
  parameter_arns  = values(module.ssm_parameters.parameter_arns)
  sqs_queue_arn   = module.sqs.queue_arn
  s3_bucket_arn   = module.s3.bucket_arn
  github_repo     = var.github_repo
  ecr_repo_arns   = [module.ecr.app_repository_arn, module.ecr.worker_repository_arn]
  ecs_cluster_arn = module.ecs.cluster_arn
}

# ------------------------------------------------------------------
# Phase 6: Database — RDS PostgreSQL 17 + pgvector
# ------------------------------------------------------------------

module "rds" {
  source = "./modules/rds"

  name_prefix         = local.name_prefix
  common_tags         = local.common_tags
  private_subnet_ids  = module.vpc.private_subnet_ids
  rds_sg_id           = module.security_groups.rds_sg_id
  db_instance_class   = var.db_instance_class
  db_name             = var.db_name
  db_username         = var.db_username
  db_password         = module.secrets.db_password
  multi_az            = var.multi_az
  force_ssl           = var.force_ssl
  skip_final_snapshot = var.skip_final_snapshot
}

# ------------------------------------------------------------------
# Phase 7: Compute — ECS Cluster, ALB, App Service, Worker Service
# ------------------------------------------------------------------

module "ecs" {
  source = "./modules/ecs"

  name_prefix = local.name_prefix
  common_tags = local.common_tags
}

module "alb" {
  source = "./modules/alb"

  name_prefix       = local.name_prefix
  common_tags       = local.common_tags
  vpc_id            = module.vpc.vpc_id
  public_subnet_ids = module.vpc.public_subnet_ids
  alb_sg_id         = module.security_groups.alb_sg_id
}

/*
 * ECS services depend on monitoring for log groups, so we use computed
 * log group names (matching the monitoring module's naming convention)
 * to break the circular dependency: monitoring → ecs_service → monitoring.
 */

module "ecs_service_app" {
  source = "./modules/ecs-service-app"

  name_prefix        = local.name_prefix
  common_tags        = local.common_tags
  environment        = var.environment
  cluster_id         = module.ecs.cluster_id
  execution_role_arn = module.iam.execution_role_arn
  app_task_role_arn  = module.iam.app_task_role_arn
  app_image          = var.app_image != "" ? var.app_image : "${module.ecr.app_repository_url}:latest"
  app_cpu            = var.app_cpu
  app_memory         = var.app_memory
  desired_count      = var.app_desired_count
  private_subnet_ids = module.vpc.private_subnet_ids
  app_sg_id          = module.security_groups.app_sg_id
  target_group_arn   = module.alb.target_group_arn
  log_group_name     = "/ecs/${local.name_prefix}/app"
  db_host            = module.rds.address
  db_name            = var.db_name
  db_secret_arn      = module.secrets.db_secret_arn
  app_key_secret_arn = module.secrets.app_key_secret_arn
  sqs_queue_url      = module.sqs.queue_url
  s3_bucket          = module.s3.bucket_name
  aws_region         = var.aws_region
  alb_dns_name       = module.alb.alb_dns_name
  cdn_domain         = module.cdn.domain_name
}

module "ecs_service_worker" {
  source = "./modules/ecs-service-worker"

  name_prefix          = local.name_prefix
  common_tags          = local.common_tags
  cluster_id           = module.ecs.cluster_id
  execution_role_arn   = module.iam.execution_role_arn
  worker_task_role_arn = module.iam.worker_task_role_arn
  worker_image         = var.worker_image != "" ? var.worker_image : "${module.ecr.worker_repository_url}:latest"
  worker_cpu           = var.worker_cpu
  worker_memory        = var.worker_memory
  desired_count        = var.worker_desired_count
  private_subnet_ids   = module.vpc.private_subnet_ids
  worker_sg_id         = module.security_groups.worker_sg_id
  log_group_name       = "/ecs/${local.name_prefix}/worker"
  db_host              = module.rds.address
  db_name              = var.db_name
  db_secret_arn        = module.secrets.db_secret_arn
  sqs_queue_url        = module.sqs.queue_url
  s3_bucket            = module.s3.bucket_name
  aws_region           = var.aws_region
}

# ------------------------------------------------------------------
# Phase 8: Monitoring — Log Groups, Alarms, SNS
# ------------------------------------------------------------------

# ------------------------------------------------------------------
# DNS + HTTPS (optional — only when domain_name is set)
# ------------------------------------------------------------------

module "dns" {
  source = "./modules/dns"
  count  = var.domain_name != "" ? 1 : 0

  hosted_zone_name  = var.hosted_zone_name
  subdomain         = var.domain_name
  alb_dns_name      = module.alb.alb_dns_name
  alb_zone_id       = module.alb.alb_zone_id
  alb_arn           = module.alb.alb_arn
  target_group_arn  = module.alb.target_group_arn
  http_listener_arn = module.alb.listener_arn
  common_tags       = local.common_tags
}

# ------------------------------------------------------------------
# WAF (optional — only when enable_waf is true)
# ------------------------------------------------------------------

module "waf" {
  source = "./modules/waf"
  count  = var.enable_waf ? 1 : 0

  name_prefix = local.name_prefix
  common_tags = local.common_tags
  alb_arn     = module.alb.alb_arn
}

# ------------------------------------------------------------------
# Scheduler — weekday auto-start (08:30 JST) / auto-stop (19:30 JST)
# ------------------------------------------------------------------

module "scheduler" {
  source = "./modules/scheduler"

  name_prefix    = local.name_prefix
  aws_region     = var.aws_region
  rds_identifier = "${local.name_prefix}-postgres"
  ecs_cluster    = "${local.name_prefix}-cluster"
  ecs_services   = ["${local.name_prefix}-app", "${local.name_prefix}-worker"]
  common_tags    = local.common_tags
}

# ------------------------------------------------------------------
# Phase 8: Monitoring
# ------------------------------------------------------------------

module "monitoring" {
  source = "./modules/monitoring"

  name_prefix      = local.name_prefix
  common_tags      = local.common_tags
  rds_instance_id  = module.rds.endpoint
  dlq_queue_name   = "${local.name_prefix}-pipeline-dlq"
  app_service_name = "${local.name_prefix}-app"
  ecs_cluster_name = module.ecs.cluster_name
}
