
# CTO Response — Infrastructure Decision Summary Approval

**Date:** 2026-03-23  
**From:** CTO  
**To:** Engineering Lead / Senior Engineer  
**Subject:** Infrastructure Decisions Approval and Phase 0 Start Authorization

---

## 1. Review Result

提出された以下のドキュメントを確認しました。

- Infrastructure_Decision_Summary.md
- ADR_0005_SQS_DB_Polling.md
- ADR_0006_Bedrock_Provider.md
- ADR_0007_Job_Chain_over_StepFunctions.md
- Phase0_Implementation_Plan.md

インフラ構成、通信方式、AI プロバイダー、オーケストレーション方式についてレビューしました。

**結論：提案された 7 項目すべて承認します。**

Phase 0 実装に進んでください。

---

## 2. Approved Architecture (Final Decision)

本システムの基本アーキテクチャを以下で確定します。

### Control Plane
- Laravel (API / Web / Job Management)
- Laravel Queue / Horizon

### Queue / Messaging
- Amazon SQS
- Dead Letter Queue 有効

### Data Plane
- Python Worker on ECS Fargate
- Fargate Spot 優先使用

### Storage
- Amazon S3（データ・中間ファイル・エクスポート）
- Amazon RDS PostgreSQL + pgvector

### AI / Embedding / LLM
- Amazon Bedrock
  - Embedding: amazon.titan-embed-text-v2:0
  - LLM: anthropic.claude-sonnet-4-20250514

### Orchestration
- Phase 1〜2: Laravel Job Chain + SQS
- Step Functions: 将来移行候補

---

## 3. Communication Architecture (Final)

Laravel ↔ Python Worker 通信方式は以下で確定します。

**SQS + RDS 更新方式**

### Data Flow

1. Laravel が jobs レコード作成
2. Laravel が SQS に Step メッセージ送信
3. Python Worker が SQS メッセージ取得
4. Python Worker が処理実行
5. Python Worker が RDS を直接更新
6. Python Worker が次 Step の SQS メッセージ送信
7. Laravel は DB 状態を UI に反映

---

## 4. Important System Design Principles

本システムの設計原則を以下に定義します。

### Principle 1 — Reproducibility
pipeline_config + dataset_version + model_version で結果が再現できること。

### Principle 2 — Idempotent Jobs
同じ job を再実行してもデータが壊れない設計にすること。

### Principle 3 — Intermediate Data on S3
ステップ間の中間データはすべて S3 に保存する。

### Principle 4 — Metadata in RDS
RDS はメタデータ・状態管理のみ保存する。

### Principle 5 — Tenant Isolation
すべてのテーブルと S3 パスに tenant_id を含める。

### Principle 6 — Cost Visibility
ValidateAndEstimate により実行前にコストを表示する。

### Principle 7 — Knowledge Unit is Final Product
本システムの最終成果物は Knowledge Unit とする。

---

## 5. S3 Path Structure (Standard)

S3 パス構造は以下を標準とする。

```
s3://bucket/{tenant_id}/datasets/{dataset_id}/
s3://bucket/{tenant_id}/jobs/{job_id}/preprocess/
s3://bucket/{tenant_id}/jobs/{job_id}/embedding/
s3://bucket/{tenant_id}/jobs/{job_id}/clustering/
s3://bucket/{tenant_id}/jobs/{job_id}/exports/
s3://bucket/{tenant_id}/embedding-cache/
```

---

## 6. Phase 0 Completion Criteria

Phase 0 の完了基準を以下とします。

### Phase 0 完了条件

以下がすべて動作すること：

1. Laravel API で Dataset Upload ができる
2. datasets / dataset_rows に保存される
3. Job を作成できる
4. Laravel → SQS にメッセージ送信できる
5. Python Worker が SQS メッセージを受信できる
6. Python Worker が RDS に接続できる
7. Python Worker が jobs.status / progress を更新できる
8. Laravel UI から Job 状態が確認できる

**Laravel → SQS → Python Worker → RDS → Laravel UI**
のエンドツーエンド疎通成功を Phase 0 完了とする。

---

## 7. Final Decision

| Item | Decision |
|------|---------|
| Communication | SQS + DB Polling |
| AI Provider | Bedrock |
| Orchestration | Laravel Job Chain |
| Worker | Python on Fargate |
| Storage | S3 + RDS PostgreSQL |
| Vector | pgvector |
| Multi-tenant | tenant_id |
| Intermediate Data | S3 |
| Metadata | RDS |

**All infrastructure decisions are approved.**

---

## 8. Authorization

**Phase 0 Implementation を開始してください。**

