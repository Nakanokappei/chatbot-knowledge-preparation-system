# AWS Infrastructure Setup Guide

**Project:** Chatbot Knowledge Preparation System (CKPS)
**Last Updated:** 2026-03-27
**Author:** Senior Engineer

---

## 1. Architecture Overview

```
Internet
    ↓
ALB (Public Subnets)
    ↓
┌─────────────────────────────────────────┐
│          Private Subnets                 │
│                                          │
│  ┌──────────┐      ┌──────────────┐     │
│  │ App      │      │ Worker       │     │
│  │ (Fargate)│      │ (Fargate     │     │
│  │ PHP-FPM  │      │  Spot)       │     │
│  │ + nginx  │      │ Python 3.12  │     │
│  └────┬─────┘      └──────┬───────┘     │
│       │                    │             │
│       ├────────┬───────────┤             │
│       ↓        ↓           ↓             │
│    RDS       SQS          S3             │
│  (PG 17+    (Pipeline    (CSV           │
│   pgvector)  + DLQ)      Uploads)       │
│                                          │
│       ↓ (via NAT Gateway)               │
│    AWS Bedrock                           │
│  (Titan Embed v2 + Claude)              │
└─────────────────────────────────────────┘
```

### AWS Services Used

| Service | Purpose |
|---------|---------|
| ECS Fargate | App + Worker containers |
| RDS PostgreSQL 17 | Database with pgvector |
| SQS | Pipeline job queue + DLQ |
| S3 | CSV uploads, embeddings cache |
| ECR | Container image registry |
| ALB | Load balancer (HTTP) |
| Bedrock | LLM (Claude) + Embeddings (Titan) |
| Secrets Manager | DB password, APP_KEY |
| SSM Parameter Store | Configuration values |
| CloudWatch | Logs + Alarms |
| SNS | Alert notifications |

---

## 2. Prerequisites

### 2.1 Tools

```bash
# Terraform
brew install terraform    # >= 1.5

# AWS CLI
brew install awscli       # v2

# Docker
brew install --cask docker

# git-filter-repo (optional, for history cleanup)
pip3 install git-filter-repo
```

### 2.2 AWS CLI Profile Setup

```bash
aws configure --profile kps-company
# AWS Access Key ID:     <YOUR_ACCESS_KEY_ID>
# AWS Secret Access Key: <YOUR_SECRET_ACCESS_KEY>
# Default region:        ap-northeast-1
# Default output format: json
```

Verify access:

```bash
aws sts get-caller-identity --profile kps-company
```

Expected output:

```json
{
  "UserId": "<USER_ID>",
  "Account": "<ACCOUNT_ID>",
  "Arn": "arn:aws:iam::<ACCOUNT_ID>:user/<USERNAME>"
}
```

---

## 3. Terraform State Backend (One-time Setup)

Before running Terraform, create the state backend manually:

```bash
# S3 bucket for state storage
aws s3api create-bucket \
  --bucket kps-terraform-state-<ACCOUNT_ID> \
  --region ap-northeast-1 \
  --create-bucket-configuration LocationConstraint=ap-northeast-1 \
  --profile kps-company

aws s3api put-bucket-versioning \
  --bucket kps-terraform-state-<ACCOUNT_ID> \
  --versioning-configuration Status=Enabled \
  --profile kps-company

aws s3api put-bucket-encryption \
  --bucket kps-terraform-state-<ACCOUNT_ID> \
  --server-side-encryption-configuration \
    '{"Rules":[{"ApplyServerSideEncryptionByDefault":{"SSEAlgorithm":"AES256"}}]}' \
  --profile kps-company

# DynamoDB table for state locking
aws dynamodb create-table \
  --table-name kps-terraform-lock \
  --attribute-definitions AttributeName=LockID,AttributeType=S \
  --key-schema AttributeName=LockID,KeyType=HASH \
  --billing-mode PAY_PER_REQUEST \
  --region ap-northeast-1 \
  --profile kps-company
```

Update `terraform/backend.tf` with your account ID:

```hcl
terraform {
  backend "s3" {
    bucket         = "kps-terraform-state-<ACCOUNT_ID>"
    key            = "kps/terraform.tfstate"
    region         = "ap-northeast-1"
    dynamodb_table = "kps-terraform-lock"
    encrypt        = true
    profile        = "kps-company"
  }
}
```

---

## 4. Terraform Deployment

### 4.1 Module Structure

```
terraform/
├── main.tf              # Root orchestration (module calls)
├── variables.tf         # Input variables
├── locals.tf            # Computed values
├── outputs.tf           # Outputs (ALB DNS, ECR URLs, etc.)
├── versions.tf          # Provider version constraints
├── provider.tf          # AWS provider config
├── backend.tf           # Remote state backend
└── modules/
    ├── vpc/             # VPC, subnets, NAT, IGW
    ├── security-groups/ # ALB, App, Worker, RDS SGs
    ├── ecr/             # Container registries
    ├── sqs/             # Pipeline queue + DLQ
    ├── s3/              # CSV uploads bucket
    ├── secrets/         # Secrets Manager entries
    ├── ssm-parameters/  # Configuration parameters
    ├── iam/             # Task execution + task roles
    ├── rds/             # PostgreSQL instance
    ├── alb/             # Application Load Balancer
    ├── ecs/             # ECS Fargate cluster
    ├── ecs-service-app/ # App service + task definition
    ├── ecs-service-worker/ # Worker service + task def
    └── monitoring/      # CloudWatch, SNS, alarms
```

### 4.2 Provisioning Order

Terraform handles dependencies automatically, but the logical order is:

```
Phase 1: terraform init
Phase 2: VPC + Security Groups
Phase 3: ECR + SQS + S3
Phase 4: Secrets Manager + SSM Parameters
Phase 5: IAM Roles
Phase 6: RDS (PostgreSQL + pgvector)
Phase 7: ECS Cluster + ALB + Services
Phase 8: CloudWatch + SNS Alarms
```

### 4.3 Initialize and Apply

```bash
cd terraform/

# Initialize (downloads providers, configures backend)
terraform init

# Review planned changes
terraform plan -out=tfplan

# Apply
terraform apply tfplan
```

### 4.4 Key Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `aws_region` | ap-northeast-1 | AWS region |
| `aws_profile` | kps-company | CLI profile name |
| `environment` | dev | Environment name |
| `project_name` | kps | Resource name prefix |
| `vpc_cidr` | 10.0.0.0/16 | VPC CIDR block |
| `db_instance_class` | db.t4g.micro | RDS instance size |
| `db_name` | knowledge_prep | Database name |
| `db_username` | ckps_admin | DB master username |
| `app_cpu` | 256 | App container CPU (0.25 vCPU) |
| `app_memory` | 512 | App container memory (MiB) |
| `worker_cpu` | 1024 | Worker container CPU (1 vCPU) |
| `worker_memory` | 2048 | Worker container memory (MiB) |
| `app_desired_count` | 1 | App replica count |
| `worker_desired_count` | 1 | Worker replica count |

---

## 5. Network Architecture

### 5.1 VPC Layout

| Subnet | CIDR | AZ | Purpose |
|--------|------|----|---------|
| Public A | 10.0.1.0/24 | ap-northeast-1a | ALB, NAT Gateway |
| Public C | 10.0.2.0/24 | ap-northeast-1c | ALB (multi-AZ) |
| Private A | 10.0.11.0/24 | ap-northeast-1a | ECS tasks, RDS |
| Private C | 10.0.12.0/24 | ap-northeast-1c | ECS tasks, RDS |

### 5.2 Security Groups

```
Internet → ALB SG (port 80 from 0.0.0.0/0)
              ↓
         App SG (port 80 from ALB SG only)
              ↓
         RDS SG (port 5432 from App SG + Worker SG)

Worker SG: No inbound rules (outbound only: SQS, S3, Bedrock, RDS)
```

---

## 6. Database Setup

### 6.1 RDS Configuration

| Setting | Value |
|---------|-------|
| Engine | PostgreSQL 17 |
| Instance | db.t4g.micro (dev) |
| Storage | 20 GB GP3, auto-scaling to 100 GB |
| Encryption | Enabled (at rest) |
| Backup | 7 days retention |
| Public Access | Disabled (private subnets only) |
| Multi-AZ | No (dev), Yes (prod recommended) |

### 6.2 Post-Provisioning: Enable pgvector

After Terraform apply, connect to RDS and enable the pgvector extension:

```bash
# Get RDS endpoint from Terraform output
terraform output rds_endpoint

# Connect via bastion or local tunnel
psql -h <RDS_ENDPOINT> -U ckps_admin -d knowledge_prep

# Enable pgvector
CREATE EXTENSION IF NOT EXISTS vector;
```

### 6.3 Application DB User (RLS)

Create a non-owner user for the application (required for Row Level Security):

```sql
CREATE USER ckps_app WITH PASSWORD '<GENERATED_PASSWORD>';
GRANT CONNECT ON DATABASE knowledge_prep TO ckps_app;
GRANT USAGE ON SCHEMA public TO ckps_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO ckps_app;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO ckps_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO ckps_app;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO ckps_app;
```

---

## 7. Container Images

### 7.1 Build and Push

```bash
# Login to ECR
aws ecr get-login-password --region ap-northeast-1 --profile kps-company \
  | docker login --username AWS --password-stdin \
    <ACCOUNT_ID>.dkr.ecr.ap-northeast-1.amazonaws.com

# Build app image
docker build -t kps-app ./app/
docker tag kps-app:latest \
  <ACCOUNT_ID>.dkr.ecr.ap-northeast-1.amazonaws.com/kps-dev-app:latest
docker push \
  <ACCOUNT_ID>.dkr.ecr.ap-northeast-1.amazonaws.com/kps-dev-app:latest

# Build worker image
docker build -t kps-worker ./worker/
docker tag kps-worker:latest \
  <ACCOUNT_ID>.dkr.ecr.ap-northeast-1.amazonaws.com/kps-dev-worker:latest
docker push \
  <ACCOUNT_ID>.dkr.ecr.ap-northeast-1.amazonaws.com/kps-dev-worker:latest
```

### 7.2 Image Details

**App Container** (`app/Dockerfile`):
- Base: php:8.4-fpm-alpine
- Runtime: nginx + php-fpm via supervisord
- Port: 80
- Health check: GET /up
- Extensions: pdo_pgsql, pgsql, intl, mbstring, opcache

**Worker Container** (`worker/Dockerfile`):
- Base: python:3.12-slim
- Command: `python -m src.main`
- No inbound ports (SQS polling only)
- Dependencies: boto3, pandas, numpy, hdbscan, scikit-learn

### 7.3 ECR Lifecycle

Both repositories are configured to retain the last 10 images. Older images are automatically deleted.

---

## 8. ECS Services

### 8.1 App Service

| Setting | Value |
|---------|-------|
| Launch Type | FARGATE (on-demand) |
| CPU / Memory | 256 / 512 MiB |
| Desired Count | 1 |
| Network | Private subnets, no public IP |
| Load Balancer | ALB target group (port 80) |
| Deployment | Rolling (min 50%, max 200%) |

**Environment Variables** (injected by ECS task definition):

| Variable | Source |
|----------|--------|
| APP_ENV | Terraform variable |
| DB_CONNECTION | Hardcoded: pgsql |
| DB_HOST | RDS endpoint |
| DB_PORT | 5432 |
| DB_DATABASE | knowledge_prep |
| DB_USERNAME | Secrets Manager |
| DB_PASSWORD | Secrets Manager |
| APP_KEY | Secrets Manager |
| SQS_QUEUE_URL | SSM Parameter |
| S3_BUCKET | SSM Parameter |
| AWS_DEFAULT_REGION | ap-northeast-1 |
| APP_URL | ALB DNS name |

### 8.2 Worker Service

| Setting | Value |
|---------|-------|
| Launch Type | FARGATE_SPOT (cost savings) |
| CPU / Memory | 1024 / 2048 MiB |
| Desired Count | 1 |
| Network | Private subnets, no public IP |
| Load Balancer | None (background worker) |
| Deployment | Stop-before-start (min 0%, max 100%) |

**Environment Variables**:

| Variable | Source |
|----------|--------|
| DB_HOST | RDS endpoint |
| DB_PORT | 5432 |
| DB_NAME | knowledge_prep |
| DB_USER | Secrets Manager |
| DB_PASSWORD | Secrets Manager |
| SQS_QUEUE_URL | SSM Parameter |
| S3_BUCKET | SSM Parameter |
| POLL_MODE | sqs |
| AWS_DEFAULT_REGION | ap-northeast-1 |

---

## 9. SQS Configuration

| Setting | Main Queue | Dead-Letter Queue |
|---------|-----------|-------------------|
| Name | kps-dev-pipeline | kps-dev-pipeline-dlq |
| Visibility Timeout | 900s (15 min) | — |
| Message Retention | 14 days | 14 days |
| Max Receive Count | 3 (then → DLQ) | — |

Pipeline flow:

```
Laravel dispatches job → SQS Main Queue
    ↓
Python Worker polls (20s long-poll)
    ↓
Process (embedding, clustering, KU generation)
    ↓
Success → Delete message
Failure (3x) → Route to DLQ → CloudWatch alarm
```

---

## 10. S3 Configuration

| Setting | Value |
|---------|-------|
| Bucket Name | kps-dev-csv-uploads |
| Encryption | SSE-S3 (AES256) |
| Public Access | All blocked |
| Lifecycle | Delete after 90 days |
| CORS | PUT allowed from any origin |
| Versioning | Not enabled |

---

## 11. IAM Roles

### 11.1 Task Execution Role

**Name:** kps-dev-ecs-task-execution

Permissions:
- `AmazonECSTaskExecutionRolePolicy` (managed)
- `secretsmanager:GetSecretValue` (scoped to kps secrets)
- `ssm:GetParameters` (scoped to kps parameters)

### 11.2 App Task Role

**Name:** kps-dev-app-task

| Action | Resource |
|--------|----------|
| sqs:SendMessage | Pipeline queue ARN |
| s3:PutObject, GetObject, DeleteObject | CSV bucket/* |
| bedrock:InvokeModel | All models (wildcard) |

### 11.3 Worker Task Role

**Name:** kps-dev-worker-task

| Action | Resource |
|--------|----------|
| sqs:ReceiveMessage, DeleteMessage, ChangeMessageVisibility | Pipeline queue ARN |
| s3:GetObject, PutObject | CSV bucket/* |
| bedrock:InvokeModel | All models (wildcard) |
| cloudwatch:PutMetricData | Custom metrics |

---

## 12. Monitoring & Alerts

### 12.1 CloudWatch Log Groups

| Log Group | Retention |
|-----------|-----------|
| /ecs/kps-dev/app | 30 days |
| /ecs/kps-dev/worker | 30 days |

### 12.2 Alarms

| Alarm | Condition | Action |
|-------|-----------|--------|
| RDS CPU High | CPUUtilization > 80% for 5 min | SNS alert |
| RDS Free Storage Low | FreeStorageSpace < 5 GB for 5 min | SNS alert |
| DLQ Messages | Any message in DLQ | SNS alert |
| App Running Count Low | RunningTaskCount < 1 for 1 min | SNS alert |

### 12.3 SNS Topic

**Name:** kps-dev-alerts

Add email subscription after provisioning:

```bash
aws sns subscribe \
  --topic-arn arn:aws:sns:ap-northeast-1:<ACCOUNT_ID>:kps-dev-alerts \
  --protocol email \
  --notification-endpoint your-email@example.com \
  --profile kps-company
```

---

## 13. Deployment

### 13.1 Manual Deployment

```bash
./scripts/deploy.sh <environment> <image-tag>

# Example:
./scripts/deploy.sh staging latest
./scripts/deploy.sh production abc123def
```

The script:
1. Fetches current ECS task definitions
2. Updates container image tags
3. Registers new task definition revisions
4. Triggers ECS force-new-deployment
5. Waits for service stability (10 min timeout)

### 13.2 CI/CD (GitHub Actions)

Triggered on push to `main`:

```
Lint (PHPStan + flake8)
    ↓
Docker build → ECR push (SHA tag + latest)
    ↓
Deploy to staging
    ↓
Smoke test (/up, /login, /api/datasets, /api/chat)
    ↓
Deploy to production
```

Authentication uses OIDC (`AWS_DEPLOY_ROLE_ARN` in GitHub Secrets).

### 13.3 Smoke Test

```bash
./scripts/smoke_test.sh <base-url> <api-token>

# Checks:
# - GET /up          → 200 (health)
# - GET /login       → 200 (web UI)
# - GET /api/user    → 200 (auth)
# - GET /api/datasets → 200 (data)
# - POST /api/retrieve → 200 (RAG)
# - POST /api/chat    → 200 (chat)
```

---

## 14. Local Development

### 14.1 Docker Compose

```bash
# Start all services
docker compose up -d

# Services:
#   db:     PostgreSQL 17 + pgvector (port 5433)
#   app:    Laravel (port 8000)
#   worker: Python SQS worker
```

### 14.2 Required .env Variables (Local)

Copy `.env.example` and configure:

```bash
cp app/.env.example app/.env

# Key overrides for local:
APP_ENV=local
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5433
DB_DATABASE=knowledge_prep
DB_USERNAME=postgres
DB_PASSWORD=postgres
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local
AWS_BEDROCK_REGION=ap-northeast-1
AWS_ACCESS_KEY_ID=<YOUR_KEY>
AWS_SECRET_ACCESS_KEY=<YOUR_SECRET>
```

---

## 15. Production Hardening Checklist

Before going to production, address these items:

| Item | Dev Status | Production Action |
|------|-----------|-------------------|
| HTTPS | HTTP only (port 80) | Add ACM certificate + HTTPS listener on ALB |
| RDS SSL | Disabled (`rds.force_ssl=0`) | Enable SSL enforcement |
| NAT Gateway | Single (1 AZ) | Add second NAT for HA |
| Multi-AZ RDS | Disabled | Enable for failover |
| Final Snapshot | Skipped | Set `skip_final_snapshot=false` |
| App Resources | 0.25 vCPU / 512 MiB | Scale based on load testing |
| Bedrock | Wildcard model access | Restrict to specific model ARNs |
| ALB WAF | Not configured | Add AWS WAF for DDoS/bot protection |
| Backup Testing | Not automated | Schedule quarterly restore drills |
| SNS Subscribers | None | Add on-call email/PagerDuty |

---

## 16. Useful Commands

```bash
# View ECS service status
aws ecs describe-services \
  --cluster kps-dev-cluster \
  --services kps-dev-app kps-dev-worker \
  --profile kps-company

# View recent logs
aws logs tail /ecs/kps-dev/app --since 1h --profile kps-company
aws logs tail /ecs/kps-dev/worker --since 1h --profile kps-company

# Check SQS queue depth
aws sqs get-queue-attributes \
  --queue-url <SQS_QUEUE_URL> \
  --attribute-names ApproximateNumberOfMessages \
  --profile kps-company

# Check DLQ
aws sqs get-queue-attributes \
  --queue-url <DLQ_URL> \
  --attribute-names ApproximateNumberOfMessages \
  --profile kps-company

# Force new deployment (restart containers)
aws ecs update-service \
  --cluster kps-dev-cluster \
  --service kps-dev-app \
  --force-new-deployment \
  --profile kps-company

# View Terraform state
cd terraform/ && terraform state list
```

---

## 17. Cost Estimate (Dev Environment)

| Service | Spec | Estimated Monthly |
|---------|------|-------------------|
| ECS Fargate (App) | 0.25 vCPU / 512 MiB × 24h | ~$10 |
| ECS Fargate Spot (Worker) | 1 vCPU / 2 GiB × 24h | ~$15 |
| RDS (db.t4g.micro) | Single-AZ, 20 GB | ~$15 |
| NAT Gateway | 1 instance + data | ~$35 |
| ALB | 1 instance + LCU | ~$18 |
| S3 | < 1 GB | ~$0.03 |
| SQS | < 1M requests | ~$0 |
| ECR | < 5 GB images | ~$0.50 |
| CloudWatch | Logs + alarms | ~$5 |
| **Total** | | **~$100/month** |

> NAT Gateway is the largest fixed cost. For dev, consider NAT instance (t4g.nano ~$3/month) as alternative.

---

## Appendix: Terraform Outputs

After `terraform apply`, the following outputs are available:

```bash
terraform output

# Key outputs:
# alb_dns_name     = ALB public DNS (access point)
# ecr_app_url      = ECR repository URL for app
# ecr_worker_url   = ECR repository URL for worker
# rds_endpoint     = RDS connection endpoint
# sqs_queue_url    = SQS pipeline queue URL
# s3_bucket_name   = S3 bucket name
```
