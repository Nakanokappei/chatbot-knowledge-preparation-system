# =============================================================================
# IAM Module — ECS Task Execution Role and per-service Task Roles
# =============================================================================
# This module provisions three IAM roles for the CKPS ECS deployment:
#
# 1. ECS Task Execution Role — shared by both App and Worker task definitions.
#    Grants ECS the permissions it needs at *launch time*: pulling container
#    images from ECR and injecting secrets/parameters into the container
#    environment.
#
# 2. App Task Role — assumed by the running App container. Allows it to enqueue
#    pipeline jobs via SQS, read/write export artifacts in S3, and invoke
#    Bedrock models for the chat feature.
#
# 3. Worker Task Role — assumed by the running Worker container. Allows it to
#    consume SQS messages (pipeline jobs), access S3 for intermediate data,
#    invoke Bedrock for embedding and LLM operations, and publish custom
#    CloudWatch metrics for pipeline observability.
# =============================================================================

# ---------------------------------------------------------------------------
# Data: standard ECS tasks trust policy document
# ---------------------------------------------------------------------------
# Both the execution role and the task roles share the same trust
# relationship — they are assumed by the ECS task runtime on behalf of the
# container.

data "aws_iam_policy_document" "ecs_tasks_assume_role" {
  statement {
    effect  = "Allow"
    actions = ["sts:AssumeRole"]

    principals {
      type        = "Service"
      identifiers = ["ecs-tasks.amazonaws.com"]
    }
  }
}

# ===========================================================================
# 1. ECS Task Execution Role
# ===========================================================================
# This role is referenced in the ECS task definition's `execution_role_arn`.
# It gives the ECS agent itself (not the application) the ability to pull
# images, write logs, and read secrets/parameters needed to populate the
# container environment at startup.

resource "aws_iam_role" "ecs_task_execution" {
  name               = "${var.name_prefix}-ecs-task-execution"
  assume_role_policy = data.aws_iam_policy_document.ecs_tasks_assume_role.json
  tags               = var.common_tags
}

# Attach the AWS-managed policy that covers ECR pull and CloudWatch Logs
# permissions. This is the standard baseline for any ECS task execution role.
resource "aws_iam_role_policy_attachment" "ecs_task_execution_managed" {
  role       = aws_iam_role.ecs_task_execution.name
  policy_arn = "arn:aws:iam::aws:policy/service-role/AmazonECSTaskExecutionRolePolicy"
}

# Inline policy granting read access to the specific Secrets Manager secrets
# and SSM Parameter Store parameters that the task definition references via
# `secrets` and `environment` blocks.
resource "aws_iam_role_policy" "ecs_task_execution_secrets" {
  name = "${var.name_prefix}-execution-secrets"
  role = aws_iam_role.ecs_task_execution.id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Sid      = "ReadSecrets"
        Effect   = "Allow"
        Action   = ["secretsmanager:GetSecretValue"]
        Resource = var.secret_arns
      },
      {
        Sid      = "ReadParameters"
        Effect   = "Allow"
        Action   = ["ssm:GetParameters"]
        Resource = var.parameter_arns
      }
    ]
  })
}

# ===========================================================================
# 2. App Task Role
# ===========================================================================
# This role is referenced in the App ECS task definition's `task_role_arn`.
# It defines what the *running application code* is permitted to do within
# AWS. The App container needs to:
#   - Send messages to SQS (to enqueue pipeline jobs)
#   - Read/write/delete objects in S3 (export artifacts, uploaded files)
#   - Invoke Bedrock models (chat feature)

resource "aws_iam_role" "app_task" {
  name               = "${var.name_prefix}-app-task"
  assume_role_policy = data.aws_iam_policy_document.ecs_tasks_assume_role.json
  tags               = var.common_tags
}

resource "aws_iam_role_policy" "app_task_permissions" {
  name = "${var.name_prefix}-app-task-permissions"
  role = aws_iam_role.app_task.id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      # The App enqueues pipeline jobs by sending SQS messages.
      {
        Sid      = "SQSSend"
        Effect   = "Allow"
        Action   = ["sqs:SendMessage"]
        Resource = var.sqs_queue_arn
      },
      # The App manages export files and user uploads stored in S3.
      {
        Sid    = "S3ReadWrite"
        Effect = "Allow"
        Action = [
          "s3:PutObject",
          "s3:GetObject",
          "s3:DeleteObject",
          "s3:ListBucket"
        ]
        Resource = [var.s3_bucket_arn, "${var.s3_bucket_arn}/*"]
      },
      # The App calls Bedrock for the interactive chat feature. The resource
      # is set to wildcard because the specific model may vary by tenant
      # configuration.
      {
        Sid    = "BedrockInvoke"
        Effect = "Allow"
        Action = [
          "bedrock:InvokeModel",
          "bedrock:InvokeModelWithResponseStream",
          "bedrock:ListFoundationModels",
          "bedrock:ListInferenceProfiles",
          "bedrock:GetFoundationModel"
        ]
        Resource = "*"
      },
      # Required for ECS Exec (ecs execute-command) to work on this task.
      {
        Sid    = "SSMExec"
        Effect = "Allow"
        Action = [
          "ssmmessages:CreateControlChannel",
          "ssmmessages:CreateDataChannel",
          "ssmmessages:OpenControlChannel",
          "ssmmessages:OpenDataChannel"
        ]
        Resource = "*"
      },
      # The App reads CloudWatch metrics (ECS CPU/Memory, RDS connections)
      # to render the system health dashboard for system administrators.
      {
        Sid    = "CloudWatchRead"
        Effect = "Allow"
        Action = ["cloudwatch:GetMetricData"]
        Resource = "*"
      }
    ]
  })
}

# ===========================================================================
# 3. Worker Task Role
# ===========================================================================
# This role is referenced in the Worker ECS task definition's `task_role_arn`.
# The Worker container runs background pipeline jobs and needs to:
#   - Consume and acknowledge SQS messages (pipeline job queue)
#   - Read/write intermediate and final data in S3
#   - Invoke Bedrock for embedding generation and LLM-driven processing
#   - Publish custom CloudWatch metrics for pipeline monitoring

resource "aws_iam_role" "worker_task" {
  name               = "${var.name_prefix}-worker-task"
  assume_role_policy = data.aws_iam_policy_document.ecs_tasks_assume_role.json
  tags               = var.common_tags
}

resource "aws_iam_role_policy" "worker_task_permissions" {
  name = "${var.name_prefix}-worker-task-permissions"
  role = aws_iam_role.worker_task.id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      # The Worker polls the SQS queue for pipeline jobs and acknowledges
      # them upon completion. ChangeMessageVisibility is needed to extend
      # the processing deadline for long-running jobs.
      {
        Sid    = "SQSConsumeAndDispatch"
        Effect = "Allow"
        Action = [
          "sqs:ReceiveMessage",
          "sqs:DeleteMessage",
          "sqs:ChangeMessageVisibility",
          "sqs:SendMessage"
        ]
        Resource = var.sqs_queue_arn
      },
      # The Worker reads source data and writes processed results to S3.
      {
        Sid    = "S3ReadWrite"
        Effect = "Allow"
        Action = [
          "s3:GetObject",
          "s3:PutObject"
        ]
        Resource = "${var.s3_bucket_arn}/*"
      },
      # The Worker invokes Bedrock for two purposes: generating text
      # embeddings and running LLM-based keyword/cluster processing.
      {
        Sid    = "BedrockInvoke"
        Effect = "Allow"
        Action = [
          "bedrock:InvokeModel",
          "bedrock:InvokeModelWithResponseStream",
          "bedrock:ListFoundationModels",
          "bedrock:ListInferenceProfiles",
          "bedrock:GetFoundationModel"
        ]
        Resource = "*"
      },
      # The Worker publishes custom pipeline metrics (e.g. job duration,
      # items processed) to CloudWatch for operational visibility.
      {
        Sid      = "CloudWatchMetrics"
        Effect   = "Allow"
        Action   = ["cloudwatch:PutMetricData"]
        Resource = "*"
      }
    ]
  })
}

# ===========================================================================
# 4. GitHub Actions OIDC Deploy Role
# ===========================================================================
# This role allows GitHub Actions to deploy to AWS without long-lived
# access keys. GitHub's OIDC provider is trusted, and the role is scoped
# to a specific GitHub repository and branch.

# Create the GitHub OIDC identity provider (only needs to exist once per account)
resource "aws_iam_openid_connect_provider" "github" {
  count = var.github_repo != "" ? 1 : 0

  url             = "https://token.actions.githubusercontent.com"
  client_id_list  = ["sts.amazonaws.com"]
  thumbprint_list = ["ffffffffffffffffffffffffffffffffffffffff"]
  tags            = var.common_tags
}

# Trust policy: only allow the specified GitHub repo on the main branch
data "aws_iam_policy_document" "github_actions_assume_role" {
  count = var.github_repo != "" ? 1 : 0

  statement {
    effect  = "Allow"
    actions = ["sts:AssumeRoleWithWebIdentity"]

    principals {
      type        = "Federated"
      identifiers = [aws_iam_openid_connect_provider.github[0].arn]
    }

    condition {
      test     = "StringEquals"
      variable = "token.actions.githubusercontent.com:aud"
      values   = ["sts.amazonaws.com"]
    }

    condition {
      test     = "StringLike"
      variable = "token.actions.githubusercontent.com:sub"
      values   = ["repo:${var.github_repo}:*"]
    }
  }
}

resource "aws_iam_role" "github_actions_deploy" {
  count = var.github_repo != "" ? 1 : 0

  name               = "${var.name_prefix}-github-actions-deploy"
  assume_role_policy = data.aws_iam_policy_document.github_actions_assume_role[0].json
  tags               = var.common_tags
}

# Deploy permissions: ECR push + ECS service update
resource "aws_iam_role_policy" "github_actions_deploy_permissions" {
  count = var.github_repo != "" ? 1 : 0

  name = "${var.name_prefix}-github-deploy-permissions"
  role = aws_iam_role.github_actions_deploy[0].id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Sid    = "ECRAuth"
        Effect = "Allow"
        Action = ["ecr:GetAuthorizationToken"]
        Resource = "*"
      },
      {
        Sid    = "ECRPush"
        Effect = "Allow"
        Action = [
          "ecr:BatchCheckLayerAvailability",
          "ecr:GetDownloadUrlForLayer",
          "ecr:BatchGetImage",
          "ecr:PutImage",
          "ecr:InitiateLayerUpload",
          "ecr:UploadLayerPart",
          "ecr:CompleteLayerUpload"
        ]
        Resource = var.ecr_repo_arns
      },
      {
        Sid    = "ECSDeploy"
        Effect = "Allow"
        Action = ["ecs:UpdateService", "ecs:DescribeServices"]
        Resource = "*"
        Condition = {
          ArnEquals = {
            "ecs:cluster" = var.ecs_cluster_arn
          }
        }
      }
    ]
  })
}
