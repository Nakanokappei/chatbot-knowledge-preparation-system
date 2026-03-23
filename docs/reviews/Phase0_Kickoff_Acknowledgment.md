# Phase 0 着手確認 — 上級エンジニア

**日付**: 2026-03-23
**宛先**: CTO
**件名**: インフラ承認確認 + Phase 0 着手宣言

---

## 1. 承認事項の確認

以下の CTO 決定を確認・受領しました。

### 確定アーキテクチャ

| 項目 | 確定内容 |
|------|---------|
| Communication | SQS + DB Polling |
| AI Provider | Amazon Bedrock (Titan Embed v2 / Claude Sonnet) |
| Orchestration | Laravel Job Chain + SQS |
| Worker | Python on ECS Fargate (Spot) |
| Storage | S3 (中間データ) + RDS PostgreSQL (メタデータ) |
| Vector | pgvector |
| Multi-tenant | tenant_id（全テーブル + S3 パス） |

### 設計原則 7 項目

| # | 原則 | 確認 |
|---|------|------|
| 1 | Reproducibility — pipeline_config + dataset_version + model_version で再現可能 | ✅ |
| 2 | Idempotent Jobs — 再実行でデータが壊れない | ✅ |
| 3 | Intermediate Data on S3 — ステップ間データは S3 | ✅ |
| 4 | Metadata in RDS — RDS はメタデータ・状態管理のみ | ✅ |
| 5 | Tenant Isolation — 全テーブル・S3 パスに tenant_id | ✅ |
| 6 | Cost Visibility — ValidateAndEstimate でコスト事前表示 | ✅ |
| 7 | Knowledge Unit is Final Product — 最終成果物は KU | ✅ |

上記 7 原則は、Phase 0 以降の全実装判断の基準とします。

---

## 2. Phase 0 完了基準の確認

CTO 定義の完了基準をそのまま採用します：

> **Laravel → SQS → Python Worker → RDS → Laravel UI**
> のエンドツーエンド疎通成功を Phase 0 完了とする。

具体的な 8 項目：

1. Laravel API で Dataset Upload ができる
2. datasets / dataset_rows に保存される
3. Job を作成できる
4. Laravel → SQS にメッセージ送信できる
5. Python Worker が SQS メッセージを受信できる
6. Python Worker が RDS に接続できる
7. Python Worker が jobs.status / progress を更新できる
8. Laravel UI から Job 状態が確認できる

---

## 3. Phase 0 実装スケジュール

`implementation/Phase0_Implementation_Plan.md` に基づき、以下のスケジュールで進行します。

### Week 1（Day 1-5）: Laravel 基盤 + AWS インフラ

| Day | 作業 |
|-----|------|
| 1-2 | Laravel プロジェクト初期化、Docker Compose、Git |
| 3-4 | RDS + pgvector、Migration（tenants〜embedding_cache）、Sanctum |
| 5 | S3 バケット、SQS キュー（標準 + DLQ） |

### Week 2（Day 6-10）: Python Worker + 疎通

| Day | 作業 |
|-----|------|
| 6-7 | Python Worker コンテナ、ECR、ECS タスク定義 |
| 8-9 | ping ステップ疎通確認（Laravel → SQS → Worker → RDS） |
| 10 | CSV アップロード API、Phase 0 完了テスト |

---

## 4. 報告方針

- **完了時**: `reports/Phase0_Completion_Report.md` を作成
- **問題発生時**: 即時報告（ドキュメント + 口頭）
- **判断が必要な場合**: ADR を起票し CTO レビューを依頼

---

## 5. 着手宣言

**Phase 0 実装を本日より開始します。**

最初のアクションは以下の通りです：

1. Laravel プロジェクト初期化
2. Docker Compose 環境構築（PHP / PostgreSQL / Redis / LocalStack）
3. Git リポジトリ作成

---

*上級エンジニア — 2026-03-23*
