# AWS Infrastructure Setup Guide

**Project:** Chatbot Knowledge Preparation System (CKPS)
**Last Updated:** 2026-03-30
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
| ALB | Load balancer (HTTPS + HTTP→HTTPS redirect) |
| ACM | TLS certificate (wildcard) |
| Route 53 | DNS (custom domain) |
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
    ├── iam/             # Task execution + task roles + GitHub OIDC
    ├── dns/             # Route 53 + ACM + HTTPS listener
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
| `allowed_cidr_blocks` | [] | CIDRs allowed to access ALB (empty = open) |
| `domain_name` | "" | Custom domain (e.g. `demo02.poc-pxt.com`) |
| `hosted_zone_name` | "" | Route 53 hosted zone (e.g. `poc-pxt.com`) |
| `github_repo` | "" | GitHub repo for CI/CD OIDC (e.g. `owner/repo`) |

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
Internet → ALB SG (port 80 + 443, restricted to allowed_cidr_blocks)
              ↓  (HTTP 301 → HTTPS)
         App SG (port 80 from ALB SG only)
              ↓
         RDS SG (port 5432 from App SG + Worker SG)

Worker SG: No inbound rules (outbound only: SQS, S3, Bedrock, RDS)
```

**IP Restriction:** ALB access is restricted to allowed CIDRs via `allowed_cidr_blocks` in `dev.tfvars`. Set to `[]` for open access.

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

After Terraform apply, enable pgvector via a one-off ECS task (RDS is in private subnets, not directly accessible):

```bash
# Create override file
cat > /tmp/pgvector-setup.json << 'EOF'
{
  "containerOverrides": [{
    "name": "app",
    "command": ["sh", "-c", "apk add --no-cache postgresql-client && PGPASSWORD='<DB_PASSWORD>' psql -h <RDS_ENDPOINT> -U ckps_admin -d knowledge_prep -c 'CREATE EXTENSION IF NOT EXISTS vector;'"]
  }]
}
EOF

# Run one-off ECS task
aws ecs run-task \
  --cluster kps-dev-cluster \
  --task-definition kps-dev-app \
  --launch-type FARGATE \
  --network-configuration "awsvpcConfiguration={subnets=[<PRIVATE_SUBNET_ID>],securityGroups=[<APP_SG_ID>],assignPublicIp=DISABLED}" \
  --overrides file:///tmp/pgvector-setup.json \
  --region ap-northeast-1 --profile kps-company
```

Get DB password from Secrets Manager:
```bash
aws secretsmanager get-secret-value --secret-id kps-dev/database \
  --region ap-northeast-1 --profile kps-company \
  --query 'SecretString' --output text
```

### 6.3 Run Laravel Migrations

Run migrations via one-off ECS task:

```bash
cat > /tmp/migrate.json << 'EOF'
{
  "containerOverrides": [{
    "name": "app",
    "command": ["sh", "-c", "cd /var/www/html && php artisan migrate --force"]
  }]
}
EOF

aws ecs run-task \
  --cluster kps-dev-cluster \
  --task-definition kps-dev-app \
  --launch-type FARGATE \
  --network-configuration "awsvpcConfiguration={subnets=[<PRIVATE_SUBNET_ID>],securityGroups=[<APP_SG_ID>],assignPublicIp=DISABLED}" \
  --overrides file:///tmp/migrate.json \
  --region ap-northeast-1 --profile kps-company
```

### 6.4 Create Initial User (System Administrator)

The application uses a web-based setup flow controlled by `SETUP_PASSPHRASE`. When no users exist and the passphrase is configured, navigating to the app redirects to `/setup`.

**Step 1: Set `SETUP_PASSPHRASE` in SSM / task definition**

The `SETUP_PASSPHRASE` environment variable must be injected into the App task definition before the initial setup. Set it via SSM Parameter Store (recommended), or temporarily override it in the task definition.

```bash
# Store the passphrase in SSM
aws ssm put-parameter \
  --name "/kps-dev/setup_passphrase" \
  --value "<YOUR_PASSPHRASE>" \
  --type SecureString \
  --region ap-northeast-1 --profile kps-company
```

Ensure the App task definition includes:
```json
{ "name": "SETUP_PASSPHRASE", "valueFrom": "arn:aws:ssm:ap-northeast-1:<ACCOUNT_ID>:parameter/kps-dev/setup_passphrase" }
```

**Step 2: Access `/setup` in your browser**

Navigate to `https://<ALB_DNS>/setup`. The page is only accessible when:
- No users exist in the database, **and**
- `SETUP_PASSPHRASE` is non-empty

Enter your email address and the passphrase. The app generates a one-time registration link (valid 7 days).

**Step 3: Register via the invitation link**

Open the registration link. Set your name and password. Your account is created with the `system_admin` role and no workspace assignment.

**Step 4: Disable setup mode**

After registration, clear the passphrase to prevent re-entry:

```bash
aws ssm put-parameter \
  --name "/kps-dev/setup_passphrase" \
  --value "" \
  --type SecureString \
  --overwrite \
  --region ap-northeast-1 --profile kps-company
```

**Step 5: Create the first Workspace**

Log in as system administrator, then navigate to **Admin → Workspaces → New Workspace** to create the initial workspace and invite workspace owners/members via the UI.

> **Note:** The application uses a `Workspace` model (formerly `Tenant`). Users have one of three roles: `system_admin` (no workspace), `owner`, or `member`.

### 6.5 Application DB User (RLS)

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

# IMPORTANT: ECS Fargate requires linux/amd64 images.
# If building on Apple Silicon (M1/M2/M3), use --platform flag.

# Build and push app image (cross-platform)
docker buildx build --platform linux/amd64 \
  -t <ACCOUNT_ID>.dkr.ecr.ap-northeast-1.amazonaws.com/kps-dev-app:latest \
  --push ./app/

# Build and push worker image (cross-platform)
docker buildx build --platform linux/amd64 \
  -t <ACCOUNT_ID>.dkr.ecr.ap-northeast-1.amazonaws.com/kps-dev-worker:latest \
  --push ./worker/
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
| APP_KEY | Secrets Manager (must have `base64:` prefix) |
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
| CORS | PUT allowed from any origin |
| Versioning | Not enabled |

**Lifecycle Rules** (`infra/s3-lifecycle.json`):

| Rule | Prefix | Transition | Expiration |
|------|--------|------------|------------|
| EmbeddingDataLifecycle | `embeddings/` | 30d → STANDARD_IA, 90d → GLACIER | — |
| PreprocessDataCleanup | `preprocess/` | — | Delete after 90 days |
| ExportsLifecycle | `exports/` | 30d → STANDARD_IA | — |

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

### 11.3 GitHub Actions Deploy Role

**Name:** kps-dev-github-actions-deploy

Created via OIDC (no long-lived access keys). Scoped to a specific GitHub repository.

| Action | Resource |
|--------|----------|
| ecr:GetAuthorizationToken | * |
| ecr:BatchCheckLayerAvailability, PutImage, etc. | App + Worker ECR repos |
| ecs:UpdateService, DescribeServices | ECS cluster |

**Setup:** Set `AWS_DEPLOY_ROLE_ARN` in GitHub repo secrets:
```bash
gh secret set AWS_DEPLOY_ROLE_ARN --body "arn:aws:iam::<ACCOUNT_ID>:role/kps-dev-github-actions-deploy"
```

### 11.4 Worker Task Role

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

Alarm definitions are stored in `infra/cloudwatch-alarms.json` and can be deployed with:

```bash
aws cloudwatch put-metric-alarm \
  --cli-input-json file://infra/cloudwatch-alarms.json \
  --region ap-northeast-1 --profile kps-company
```

| Alarm | Metric | Condition | Action |
|-------|--------|-----------|--------|
| RetrievalLatency | p99 retrieval response time | > 2 s for 5 min | SNS alert |
| ChatLatency | p99 chat response time | > 12 s for 5 min | SNS alert |
| PipelineStepErrors | Pipeline step failure count | ≥ 3 in 15 min | SNS alert |
| TokenCostPerDay | LLM token cost | > $10/hour | SNS alert |
| SQS-DLQ-Messages | DLQ message count | Any message | SNS alert |
| ChatErrorRate | Chat error count | > 5 in 5 min | SNS alert |

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

Configured in `.github/workflows/deploy.yml`. Triggered on push to `main`:

```
Detect changed directories (app/ or worker/)
    ↓
Docker build (only changed images)
    ↓
ECR push (SHA tag + latest)
    ↓
ECS force-new-deployment (only changed services)
```

Authentication uses OIDC — no long-lived access keys needed. The `AWS_DEPLOY_ROLE_ARN` GitHub secret points to the IAM role created by the `iam` Terraform module.

Manual trigger is also available via `workflow_dispatch` (GitHub UI → Actions → Run workflow).

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
APP_URL=http://localhost:8000
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5433
DB_DATABASE=knowledge_prep
DB_USERNAME=ckps_user
DB_PASSWORD=ckps_local_password
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local
AWS_DEFAULT_REGION=ap-northeast-1
AWS_ACCESS_KEY_ID=<YOUR_KEY>
AWS_SECRET_ACCESS_KEY=<YOUR_SECRET>

# Initial setup (set a passphrase to enable /setup on first run)
SETUP_PASSPHRASE=<YOUR_LOCAL_PASSPHRASE>

# Mail (log driver is fine for local — check storage/logs for links)
MAIL_MAILER=log
```

> **Local DB credentials** match `docker-compose.yml` (`ckps_user` / `ckps_local_password`).
> The `db` service listens on host port **5433** (to avoid conflicts with a local PostgreSQL on 5432).

---

## 15. Production Hardening Checklist

Before going to production, address these items:

| Item | Dev Status | Production Action |
|------|-----------|-------------------|
| HTTPS | Enabled (wildcard cert, HTTP→HTTPS redirect) | Done for dev |
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

## 16. Environment Management

### 16.1 Manual control (kps.sh)

The `kps.sh` script in the project root manages RDS and ECS on demand:

```bash
# Start RDS + ECS services (desired count = 1)
./kps.sh start

# Stop ECS services first, then stop RDS
./kps.sh stop

# Show current status of ECS services and RDS
./kps.sh status
```

### 16.2 Automatic weekday schedule

Two EventBridge Scheduler schedules (managed by `terraform/modules/scheduler`) run Lambda functions to start and stop the environment on a fixed weekday schedule:

| Schedule | Cron (JST) | Action |
|----------|------------|--------|
| `kps-dev-weekday-start` | Mon–Fri 08:30 | Start RDS → set ECS desiredCount=1 |
| `kps-dev-weekday-stop`  | Mon–Fri 19:30 | Set ECS desiredCount=0 → stop RDS |

- **Weekends and holidays:** no automatic action — environment stays in its last state.
- **Lambda source:** `terraform/modules/scheduler/lambda/kps_start.py` and `kps_stop.py`

To update the schedule times, change the `schedule_expression` values in `terraform/modules/scheduler/main.tf` and run `terraform apply`.

### 16.3 Removing legacy resources

If the old Saturday-shutdown EventBridge rule (`kps-weekly-saturday-stop` or similar) and the `kps-weekly-stop` Monday Lambda were created manually, delete them:

```bash
# List EventBridge Scheduler schedules to find the legacy names
aws scheduler list-schedules --profile kps-company --region ap-northeast-1

# Delete a schedule by name
aws scheduler delete-schedule \
  --name <LEGACY_SCHEDULE_NAME> \
  --region ap-northeast-1 --profile kps-company

# Delete the legacy kps-weekly-stop Lambda (no longer needed)
aws lambda delete-function \
  --function-name kps-weekly-stop \
  --region ap-northeast-1 --profile kps-company
```

> **Why kps-weekly-stop is no longer needed:** RDS was previously stopped indefinitely and would auto-restart after 7 days. With the new weekday schedule, RDS is stopped every evening and started every morning — the longest continuous stop is ~63 hours (Fri 19:30 → Mon 08:30), well under the 7-day limit.

---

## 17. Useful Commands

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

## 18. Cost Estimate (Dev Environment)

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
