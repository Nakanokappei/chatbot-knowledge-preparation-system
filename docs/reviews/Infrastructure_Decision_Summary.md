# インフラ・通信方式 確定提案 — 上級エンジニア

**日付**: 2026-03-23
**目的**: Phase 0 着手に必要なインフラ決定事項を一覧化し、CTO 承認を得る

---

## 承認依頼事項一覧

以下の 7 項目すべてが承認されれば、Phase 0 着手可能と判断する。

---

### 1. Laravel ↔ Python Worker 通信方式

| 項目 | 決定 |
|------|------|
| 方式 | **SQS + DB ポーリング** |
| SQS | 標準キュー（FIFO 不要。ジョブ単位の順序は DB 側で制御） |
| DB ポーリング間隔 | 10 秒 |
| デッドレターキュー | 有効（maxReceiveCount: 3） |
| 詳細 | → `adr/ADR_0005_SQS_DB_Polling.md` |

### 2. Embedding / LLM プロバイダー

| 項目 | 決定 |
|------|------|
| プロバイダー | **Amazon Bedrock** |
| Embedding | `amazon.titan-embed-text-v2:0`（1024 次元） |
| LLM | `anthropic.claude-sonnet-4-20250514` |
| 詳細 | → `adr/ADR_0006_Bedrock_Provider.md` |

### 3. パイプラインオーケストレーション

| 項目 | 決定 |
|------|------|
| Phase 1〜2 | **Laravel Job Chain + SQS** |
| Step Functions | 将来の移行候補として保留 |
| 詳細 | → `adr/ADR_0007_Job_Chain_over_StepFunctions.md` |

### 4. Fargate タスク定義

| プロファイル | vCPU | メモリ | 用途 | Spot |
|------------|------|--------|------|------|
| small | 2 | 8 GB | Preprocess, ClusterAnalysis, KU Generation | Yes |
| medium | 4 | 30 GB | Embedding, 小〜中規模 Clustering | Yes |
| large | 8 | 60 GB | 大規模 Clustering（50万件超） | Yes |

Phase 0 では **small + medium** のみ作成。large は Phase 3 以降。

### 5. RDS インスタンス

| 環境 | インスタンス | ストレージ |
|------|------------|-----------|
| 開発 | `db.t4g.medium`（2vCPU / 4GB） | 50 GB gp3 |
| 本番 | `db.r6g.large`（2vCPU / 16GB） | 100 GB gp3 |
| pgvector | 拡張有効化 | HNSW or IVFFlat は Phase 2 で検討 |

Phase 0 では開発環境のみ構築。

### 6. S3 バケット構成

| バケット | 用途 |
|---------|------|
| `{project}-data-{env}` | データセット原本、中間成果物、エクスポート |

ライフサイクルポリシー:
- `jobs/*/embedding/` : 90 日で Intelligent-Tiering
- `jobs/*/preprocess/` : 90 日で Intelligent-Tiering

### 7. 認証（Phase 0 レベル）

| 項目 | 決定 |
|------|------|
| Laravel 認証 | Sanctum（API トークン） |
| テナント分離 | Eloquent Global Scope（`TenantScope` trait） |
| RLS | Phase 2 以降 |
| Python Worker | IAM ロール（Fargate タスクロール） |

---

## 確認事項

上記 7 項目で異論または変更がある場合はご指摘ください。

全項目承認であれば、`implementation/Phase0_Implementation_Plan.md` に基づき着手します。

---

*上級エンジニア — 2026-03-23*
