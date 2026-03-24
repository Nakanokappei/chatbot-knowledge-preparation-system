# Fargate Deployment Guide — CKPS Worker

## Prerequisites

- AWS CLI v2 configured (`aws sts get-caller-identity` succeeds)
- Docker installed and running
- AWS Account: `444332185803`
- Region: `ap-northeast-1` (Tokyo)

---

## 1. ECR Repository Setup

```bash
# Create ECR repository for the worker image
aws ecr create-repository \
    --repository-name ckps-worker \
    --region ap-northeast-1

# Authenticate Docker to ECR
aws ecr get-login-password --region ap-northeast-1 \
    | docker login --username AWS --password-stdin \
    444332185803.dkr.ecr.ap-northeast-1.amazonaws.com
```

---

## 2. Build and Push Worker Image

```bash
cd worker/

# Build the image
docker build -t ckps-worker:latest .

# Tag for ECR
docker tag ckps-worker:latest \
    444332185803.dkr.ecr.ap-northeast-1.amazonaws.com/ckps-worker:latest

# Push to ECR
docker push \
    444332185803.dkr.ecr.ap-northeast-1.amazonaws.com/ckps-worker:latest
```

---

## 3. Secrets Manager Setup

Store database credentials in Secrets Manager:

```bash
aws secretsmanager create-secret \
    --name ckps/db \
    --region ap-northeast-1 \
    --secret-string '{
        "host": "YOUR_RDS_ENDPOINT",
        "port": "5432",
        "dbname": "knowledge_prep",
        "username": "YOUR_DB_USER",
        "password": "YOUR_DB_PASSWORD"
    }'
```

Note the ARN — update `infra/ecs-task-worker.json` with the correct secret ARN
(replace `XXXXXX` suffix).

---

## 4. IAM Roles

### Execution Role (`ckps-ecs-execution-role`)

Allows ECS to pull images from ECR and read Secrets Manager:

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Effect": "Allow",
            "Action": [
                "ecr:GetDownloadUrlForLayer",
                "ecr:BatchGetImage",
                "ecr:GetAuthorizationToken",
                "logs:CreateLogStream",
                "logs:PutLogEvents",
                "secretsmanager:GetSecretValue"
            ],
            "Resource": "*"
        }
    ]
}
```

Trust policy: `ecs-tasks.amazonaws.com`

### Task Role (`ckps-ecs-task-role`)

Allows the worker container to access SQS, S3, and Bedrock:

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Effect": "Allow",
            "Action": [
                "sqs:ReceiveMessage",
                "sqs:DeleteMessage",
                "sqs:SendMessage",
                "sqs:GetQueueAttributes"
            ],
            "Resource": "arn:aws:sqs:ap-northeast-1:444332185803:ckps-*"
        },
        {
            "Effect": "Allow",
            "Action": [
                "s3:GetObject",
                "s3:PutObject",
                "s3:ListBucket"
            ],
            "Resource": [
                "arn:aws:s3:::knowledge-prep-data-dev",
                "arn:aws:s3:::knowledge-prep-data-dev/*"
            ]
        },
        {
            "Effect": "Allow",
            "Action": [
                "bedrock:InvokeModel"
            ],
            "Resource": "*"
        }
    ]
}
```

---

## 5. CloudWatch Log Group

```bash
aws logs create-log-group \
    --log-group-name /ecs/ckps-worker \
    --region ap-northeast-1
```

---

## 6. ECS Cluster and Service

```bash
# Create cluster
aws ecs create-cluster \
    --cluster-name ckps-cluster \
    --capacity-providers FARGATE FARGATE_SPOT \
    --default-capacity-provider-strategy \
        capacityProvider=FARGATE_SPOT,weight=1 \
    --region ap-northeast-1

# Register task definition
aws ecs register-task-definition \
    --cli-input-json file://infra/ecs-task-worker.json \
    --region ap-northeast-1

# Create service (1 desired task, Fargate Spot)
aws ecs create-service \
    --cluster ckps-cluster \
    --service-name ckps-worker \
    --task-definition ckps-worker \
    --desired-count 1 \
    --launch-type FARGATE \
    --capacity-provider-strategy \
        capacityProvider=FARGATE_SPOT,weight=1 \
    --network-configuration '{
        "awsvpcConfiguration": {
            "subnets": ["REPLACE_SUBNET_ID"],
            "securityGroups": ["REPLACE_SG_ID"],
            "assignPublicIp": "ENABLED"
        }
    }' \
    --region ap-northeast-1
```

> **Note**: Replace `REPLACE_SUBNET_ID` and `REPLACE_SG_ID` with your VPC subnet
> and security group that has access to RDS and outbound internet.

---

## 7. Update Deployment

```bash
# Build and push new image
cd worker/
docker build -t ckps-worker:latest .
docker tag ckps-worker:latest \
    444332185803.dkr.ecr.ap-northeast-1.amazonaws.com/ckps-worker:latest
docker push \
    444332185803.dkr.ecr.ap-northeast-1.amazonaws.com/ckps-worker:latest

# Force new deployment (pulls latest image)
aws ecs update-service \
    --cluster ckps-cluster \
    --service ckps-worker \
    --force-new-deployment \
    --region ap-northeast-1
```

---

## 8. Local Docker Development

```bash
# Start PostgreSQL only
docker compose up -d db

# Start all services (requires AWS credentials in .env or environment)
docker compose up -d

# View worker logs
docker compose logs -f worker

# Rebuild after code changes
docker compose build worker
docker compose up -d worker
```

Required environment variables (set in `.env` at project root or export):
- `AWS_ACCESS_KEY_ID`
- `AWS_SECRET_ACCESS_KEY`
- `SQS_QUEUE_URL`

---

## 9. Troubleshooting

### Worker exits immediately
Check CloudWatch Logs at `/ecs/ckps-worker`. Common causes:
- `SQS_QUEUE_URL` not set or invalid
- Database connection refused (check security group, RDS endpoint)

### SIGTERM handling
The worker handles SIGTERM gracefully — it finishes the current step before
exiting. ECS sends SIGTERM with a 30-second `stopTimeout` before SIGKILL.

### Fargate Spot interruption
Fargate Spot tasks may be interrupted. The worker's graceful shutdown ensures
in-progress messages are not lost (SQS visibility timeout returns the message
to the queue for retry).

### hdbscan build failure in Docker
Ensure `gcc` and `g++` are installed in the Docker build stage.
The worker Dockerfile includes these in the `apt-get install` step.

---

*Senior Engineer — 2026-03-24*
