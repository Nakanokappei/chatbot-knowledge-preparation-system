# AWS Environment Guide

**Date**: 2026-03-24

---

## 1. Environment Separation

**開発用と本番用は別の AWS アカウントです。**

| Environment | AWS Account | Purpose |
|------------|-------------|---------|
| Development | 444332185803 (個人) | ローカル開発、プロトタイプ、テスト |
| Production | (会社アカウント — TBD) | 本番運用、顧客データ |

### 重要ルール

- **開発アカウントのリソースを本番で使わない**
- **開発アカウントに顧客データを置かない**
- **本番アカウントの認証情報を開発マシンに保存しない**（AWS SSO / IAM Identity Center 推奨）
- **ECR イメージは本番アカウントの ECR に push する**（クロスアカウント不要）
- **Secrets Manager のシークレットは各アカウントで個別に管理**

---

## 2. Development Account (444332185803)

**用途**: エンジニアのローカル開発・テスト専用

| Resource | Name | Region |
|----------|------|--------|
| IAM User | kappei | - |
| SQS Queue | ckps-pipeline-dev | ap-northeast-1 |
| SQS DLQ | ckps-dlq-dev | ap-northeast-1 |
| S3 Bucket | knowledge-prep-data-dev | ap-northeast-1 |
| Bedrock | Titan Embed v2 + Claude Haiku | ap-northeast-1 |
| ECR | ckps-app, ckps-worker | ap-northeast-1 |
| ECS Cluster | ckps-staging | ap-northeast-1 |
| Secrets Manager | ckps/staging/* | ap-northeast-1 |
| CloudWatch Logs | /ecs/ckps-worker | ap-northeast-1 |
| DB | ローカル PostgreSQL 17 | localhost |

### このアカウントでやること

- コード開発・テスト
- Docker イメージビルド・テスト
- SQS / S3 / Bedrock の API 動作確認
- ECS タスク定義のテスト（staging クラスタ）
- CI/CD パイプラインのテスト

### このアカウントでやらないこと

- 顧客データの処理
- 本番ドメインの DNS 設定
- 本番 RDS の作成
- 本番 IAM ロールの作成

---

## 3. Production Account (会社 — TBD)

**用途**: 本番サービス運用

| Resource | Name (proposed) | Notes |
|----------|----------------|-------|
| VPC | ckps-production-vpc | Private subnets + NAT Gateway |
| RDS | ckps-production | db.r6g.large, PostgreSQL 17, Multi-AZ |
| ECS Cluster | ckps-production | Fargate |
| ECS Service | ckps-app-production | desired: 2+, ALB attached |
| ECS Service | ckps-worker-production | desired: 1+ |
| ECR | ckps-app, ckps-worker | 本番アカウント内に作成 |
| ALB | ckps-alb-production | HTTPS, HSTS, access logs |
| S3 | ckps-data-production | Versioning + Lifecycle |
| SQS | ckps-pipeline-production | + DLQ |
| Secrets Manager | ckps/production/* | DB, APP_KEY |
| CloudWatch | /ecs/ckps-* | Alarms + Dashboard |
| Route 53 | (company domain) | HTTPS via ACM |
| IAM | ckps-ecs-task-execution | Least privilege |
| IAM | ckps-worker-task-role | SQS/S3/Bedrock only |
| IAM | ckps-app-task-role | SQS send/S3 read/Bedrock |

### 本番アカウントで必要な事前準備

1. **VPC 設計**: private subnets (ECS) + public subnets (ALB) + NAT Gateway
2. **RDS 作成**: db.r6g.large, PostgreSQL 17, encrypted, Multi-AZ
3. **非オーナー DB ユーザー**: `ckps_app` (RLS 用)
4. **ECR リポジトリ**: 本番アカウント内に作成
5. **IAM ロール**: ECS 用、最小権限
6. **Secrets Manager**: DB credentials, APP_KEY
7. **ACM 証明書**: ドメイン用 HTTPS
8. **SNS トピック**: CloudWatch アラーム通知先

---

## 4. CI/CD Cross-Account Deploy

GitHub Actions から本番アカウントにデプロイする場合：

```
GitHub Actions
  → OIDC認証（推奨。長期アクセスキーを使わない）
  → 本番アカウントの deploy role を assume
  → ECR push
  → ECS deploy
```

### GitHub Actions で必要な Secrets

| Secret | Value |
|--------|-------|
| AWS_DEPLOY_ROLE_ARN_DEV | arn:aws:iam::444332185803:role/github-deploy |
| AWS_DEPLOY_ROLE_ARN_PROD | arn:aws:iam::(会社アカウント):role/github-deploy |

**長期 AWS アクセスキーを GitHub Secrets に入れないこと。OIDC を使用。**

---

## 5. Environment-Specific Configuration

### .env の差分

| Variable | Development | Production |
|----------|------------|------------|
| APP_ENV | local | production |
| APP_DEBUG | true | **false** |
| DB_HOST | 127.0.0.1 | (RDS endpoint) |
| DB_USER | nakanokappei (owner) | **ckps_app** (non-owner) |
| SQS_QUEUE_URL | ckps-pipeline-dev | ckps-pipeline-production |
| S3_BUCKET | knowledge-prep-data-dev | ckps-data-production |

### 本番では絶対にやらないこと

- APP_DEBUG=true
- DB ユーザーに superuser を使用
- S3 バケットを public に設定
- セキュリティグループで 0.0.0.0/0 を許可（RDS）
- .env ファイルを Docker イメージに含める

---

*Senior Engineer — 2026-03-24*
