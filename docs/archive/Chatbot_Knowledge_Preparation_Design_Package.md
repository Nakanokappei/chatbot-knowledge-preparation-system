# Chatbot Knowledge Preparation System — 設計図書
## CTO Design Package v1
**Date:** 2026-03-23  
**Purpose:** チャットボット向けナレッジ準備システムの設計骨格を確定する  
**Scope:**  
1. Knowledge Unit JSON Schema  
2. ER Diagram  
3. Job State Machine  
4. Pipeline Config Schema  
5. Pipeline Architecture  
6. System Architecture  

---

# 0. 基本方針

本システムは、カスタマーサポート履歴を入力として受け取り、Embedding・Clustering・要約・構造化を通じて、チャットボットで利用可能な **Knowledge Unit** を生成するための **Knowledge Preparation System** である。

本システムにおいて：

- **Cluster は中間生成物**
- **Knowledge Unit は最終成果物**
- **ジョブ再現性は必須**
- **Web/API と重いデータ処理は分離**
- **初期実装は Laravel Control Plane + Python Data Plane**
- **AWS 上で完結する構成を採用**

---

# 1. Knowledge Unit JSON Schema

## 1.1 位置づけ

Knowledge Unit は本システムの最終成果物であり、同時にチャットボット投入用の出力仕様である。

1 Cluster から 1 以上の Knowledge Unit が生成されうるが、Phase 1 では **1 Cluster → 1 Knowledge Unit** を基本とする。

## 1.2 Knowledge Unit JSON Schema（Canonical）

```json
{
  "knowledge_unit_id": "ku_000001",
  "tenant_id": "tenant_001",
  "dataset_id": "dataset_20260323_001",
  "job_id": "job_20260323_001",
  "cluster_id": "cluster_000145",
  "topic": "ログインできない",
  "intent": "ログイン失敗の原因確認と復旧方法を知りたい",
  "summary": "ユーザーがログインに失敗するケースをまとめたナレッジ。原因としてパスワード誤入力、アカウントロック、メールアドレス不一致が多い。",
  "typical_cases": [
    "パスワード再設定後もログインできない",
    "メールアドレスを変更した後に旧アドレスでログインしている",
    "連続失敗によりアカウントがロックされている"
  ],
  "cause_summary": "主な原因は認証情報不一致、アカウントロック、登録メールアドレスの誤認である。",
  "resolution_summary": "登録メールアドレス確認、パスワード再設定、ロック解除待機または管理者対応を案内する。",
  "notes": "本人確認が必要なケースでは自動回答せず有人対応へエスカレーションする。",
  "representative_rows": [
    {
      "row_id": "row_10021",
      "text": "パスワードを再設定したのにログインできません"
    },
    {
      "row_id": "row_10452",
      "text": "何度も失敗してアカウントがロックされたようです"
    }
  ],
  "keywords": [
    "ログイン",
    "パスワード",
    "アカウントロック"
  ],
  "row_count": 183,
  "confidence": 0.87,
  "review_status": "draft",
  "source_refs": {
    "cluster_label": 145,
    "representative_row_ids": ["row_10021", "row_10452", "row_10888"]
  },
  "pipeline_config_version": "pcfg_v1",
  "prompt_version": "ku_prompt_v1",
  "version": 1,
  "created_at": "2026-03-23T12:00:00Z",
  "updated_at": "2026-03-23T12:00:00Z"
}
```

## 1.3 必須フィールド

| field | required | description |
|---|---|---|
| knowledge_unit_id | yes | Knowledge Unit 識別子 |
| tenant_id | yes | テナント識別子 |
| dataset_id | yes | 元データセット識別子 |
| job_id | yes | 生成ジョブ識別子 |
| cluster_id | yes | 元クラスタ識別子 |
| topic | yes | 人間可読のトピック名 |
| intent | yes | ユーザー意図 |
| summary | yes | KUの概要 |
| cause_summary | yes | 原因要約 |
| resolution_summary | yes | 対処要約 |
| row_count | yes | 件数 |
| confidence | yes | 品質信頼度 |
| review_status | yes | draft / reviewed / approved / published |
| version | yes | KUバージョン |

## 1.4 review_status 定義

| status | meaning |
|---|---|
| draft | 自動生成直後 |
| reviewed | 人間レビュー済み |
| approved | 公開承認済み |
| published | チャットボット投入済み |

## 1.5 Phase 1 の制約

- 1 Cluster → 1 Knowledge Unit
- representative_rows は最大 5 件
- typical_cases は最大 5 件
- confidence は 0.0〜1.0
- Knowledge Unit は JSON と CSV の両形式で出力可能とする

---

# 2. ER Diagram

## 2.1 中心設計方針

本システムの中心エンティティは **knowledge_units** である。  
ただし、生成過程の再現性と監査性のため、`datasets` → `dataset_rows` → `clusters` → `knowledge_units` の系譜を保持する。

## 2.2 ER Diagram（論理）

```text
tenants
  └─< datasets
        └─< dataset_rows
        └─< jobs
              └─< clusters
                    └─< cluster_memberships >─ dataset_rows
                    └─< knowledge_units
                          └─< knowledge_unit_versions
              └─< exports
              └─ pipeline_configs

embedding_cache
dataset_rows ── optional link via embedding_hash

knowledge_units
  └─< knowledge_unit_reviews
```

## 2.3 主要テーブル一覧

### tenants
- id
- name
- status
- created_at
- updated_at

### datasets
- id
- tenant_id
- name
- source_type
- original_filename
- s3_raw_path
- row_count
- schema_json
- created_at
- updated_at

### dataset_rows
- id
- dataset_id
- tenant_id
- row_no
- raw_text
- normalized_text
- metadata_json
- embedding_hash
- created_at
- updated_at

### jobs
- id
- tenant_id
- dataset_id
- status
- progress
- pipeline_config_snapshot_json
- error_detail
- started_at
- completed_at
- created_at
- updated_at

### pipeline_configs
- id
- tenant_id
- name
- version
- config_json
- created_at
- updated_at

### clusters
- id
- job_id
- tenant_id
- cluster_label
- topic_name
- intent
- summary
- row_count
- quality_score
- representative_row_ids_json
- created_at
- updated_at

### cluster_memberships
- id
- cluster_id
- dataset_row_id
- membership_score
- created_at
- updated_at

### knowledge_units
- id
- tenant_id
- dataset_id
- job_id
- cluster_id
- topic
- intent
- summary
- typical_case
- cause_summary
- resolution_summary
- notes
- representative_rows_json
- keywords_json
- row_count
- confidence
- review_status
- embedding
- version
- created_at
- updated_at

### knowledge_unit_versions
- id
- knowledge_unit_id
- version
- snapshot_json
- created_at

### knowledge_unit_reviews
- id
- knowledge_unit_id
- reviewer_user_id
- review_status
- review_comment
- created_at

### exports
- id
- tenant_id
- job_id
- export_type
- format
- s3_path
- schema_version
- created_at

### embedding_cache
- id
- embedding_hash
- normalization_version
- model_name
- dimension
- s3_path
- created_at
- updated_at

## 2.4 設計原則

- すべての主要データは `tenant_id` を持つ
- ジョブ再現性のため、`jobs.pipeline_config_snapshot_json` に実行時設定のスナップショットを保持
- KU の編集履歴を保持するため `knowledge_unit_versions` を分離
- Embedding はキャッシュと最終検索用途を分離
- 中間ファイル本体は S3、業務上意味のある成果物は RDS を基本とする

---

# 3. Job State Machine

## 3.1 基本方針

本システムは同期 API システムではなく、**非同期ジョブシステム** として設計する。

初期実装では Laravel Job Chain / Queue を利用し、将来的に必要であれば Step Functions へ移行可能な状態遷移モデルを採用する。

## 3.2 状態一覧

| state | description |
|---|---|
| submitted | ジョブ登録直後 |
| validating | 入力検証・件数確認・コスト見積 |
| preprocessing | 正規化・重複除去・言語処理 |
| embedding | 埋め込み生成 |
| clustering | クラスタリング実行 |
| cluster_analysis | クラスタ命名・要約生成 |
| knowledge_unit_generation | Knowledge Unit 生成 |
| exporting | エクスポート生成 |
| completed | 正常完了 |
| failed | 失敗 |
| cancelled | キャンセル |

## 3.3 状態遷移図

```text
submitted
  ↓
validating
  ↓
preprocessing
  ↓
embedding
  ↓
clustering
  ↓
cluster_analysis
  ↓
knowledge_unit_generation
  ↓
exporting
  ↓
completed

[any running state] ──> failed
[submitted|validating|preprocessing] ──> cancelled
```

## 3.4 状態ごとの責務

### submitted
- job レコード作成
- dataset_id / tenant_id / pipeline config 紐づけ

### validating
- TSV 構造検証
- 対象列存在確認
- 行数確認
- 平均文字数算出
- 推定 token 数
- 推定コスト算出
- 閾値超過時は承認待ちにしてもよい

### preprocessing
- 文字正規化
- 空白・制御文字処理
- 必要なら重複削除
- normalized_text 生成
- embedding_hash 生成

### embedding
- embedding_cache 照会
- 未キャッシュ分のみ埋め込み生成
- S3 にバッチ出力
- 必要に応じて pgvector 用データ生成

### clustering
- embedding 行列読込
- HDBSCAN などを実行
- clusters / cluster_memberships 保存
- quality_score 算出

### cluster_analysis
- representative rows 抽出
- cluster naming
- cluster summary
- intent 候補生成

### knowledge_unit_generation
- structured output で KU 生成
- JSON schema validation
- confidence 算出
- knowledge_units 保存

### exporting
- 元データ + topic
- Knowledge Units JSON/CSV
- summary report
- S3 に保存して export レコード作成

## 3.5 失敗時の方針

- 失敗情報は `jobs.error_detail` に保存
- 失敗ステップを明示
- 入出力 S3 パスは保持
- 手動再実行可能
- 同一 job_id 再開よりも、再実行時は新規 job_id を推奨
- 必要なら `retry_of_job_id` を将来追加

---

# 4. Pipeline Config Schema

## 4.1 位置づけ

Pipeline Config はジョブ再現性の核であり、実行時に使われた設定の完全なスナップショットを保存する。

## 4.2 Canonical JSON Schema Example

```json
{
  "config_id": "pcfg_v1",
  "dataset_version": "dataset_schema_v1",
  "normalization_version": "norm_v1",
  "preprocess": {
    "trim_whitespace": true,
    "normalize_unicode": true,
    "deduplicate": false,
    "language_detection": false
  },
  "embedding": {
    "provider": "bedrock",
    "model": "titan-embed-text-v2",
    "dimension": 1024,
    "batch_size": 1000,
    "cache_enabled": true
  },
  "clustering": {
    "method": "hdbscan",
    "metric": "euclidean",
    "min_cluster_size": 15,
    "min_samples": 5
  },
  "cluster_analysis": {
    "representative_sample_size": 5,
    "keyword_top_n": 10
  },
  "llm": {
    "provider": "bedrock",
    "model": "claude",
    "temperature": 0.0,
    "max_tokens": 2000
  },
  "knowledge_unit": {
    "schema_version": "ku_v1",
    "prompt_version": "ku_prompt_v1",
    "structured_output": true
  },
  "export": {
    "include_topic_column": true,
    "formats": ["csv", "json"]
  },
  "runtime": {
    "random_seed": 42,
    "software_version": "1.0.0"
  }
}
```

## 4.3 必須セクション

| section | description |
|---|---|
| dataset_version | データスキーマ版 |
| normalization_version | 正規化ロジック版 |
| preprocess | 前処理設定 |
| embedding | 埋め込み設定 |
| clustering | クラスタリング設定 |
| cluster_analysis | クラスタ分析設定 |
| llm | 生成モデル設定 |
| knowledge_unit | KU 生成設定 |
| export | 出力設定 |
| runtime | 実行時設定 |

## 4.4 変更管理方針

- Config は `pipeline_configs` に保存
- 実行時には `jobs.pipeline_config_snapshot_json` に複製
- Config 更新後も過去 job の再現性を失わない
- Prompt も version として config に明示する

---

# 5. Pipeline Architecture

## 5.1 パイプラインの本質

本システムは以下の変換パイプラインである。

```text
Support Logs
  ↓
Normalized Rows
  ↓
Embeddings
  ↓
Clusters
  ↓
Cluster Understanding
  ↓
Knowledge Units
  ↓
Chatbot-ready Exports
```

## 5.2 パイプライン全体図

```text
[Upload TSV]
   ↓
[ValidateAndEstimate]
   ↓
[Preprocess]
   ↓
[Embedding]
   ↓
[Clustering]
   ↓
[ClusterAnalysis]
   ↓
[KnowledgeUnitGeneration]
   ↓
[ExportAndFinalize]
```

## 5.3 ステップ詳細

### Step 1: ValidateAndEstimate
入力:
- dataset file
- selected columns
- pipeline config

出力:
- row_count
- avg_text_length
- estimated_tokens
- estimated_embedding_calls
- estimated_llm_calls
- estimated_cost

責務:
- コスト事故防止
- 不正入力防止
- 実行可否判定

### Step 2: Preprocess
入力:
- raw TSV
- preprocess config

出力:
- normalized rows
- row metadata
- embedding_hash list

責務:
- テキスト正規化
- 再現可能な入力整形

### Step 3: Embedding
入力:
- normalized rows
- embedding config

出力:
- embedding matrix
- cache hits / misses
- embedding storage path

責務:
- キャッシュ活用
- バッチ推論
- S3格納

### Step 4: Clustering
入力:
- embedding matrix
- clustering config

出力:
- clusters
- memberships
- quality metrics

責務:
- クラスタ構造生成
- ノイズ検出
- 品質メトリクス算出

### Step 5: ClusterAnalysis
入力:
- cluster memberships
- representative rows
- metadata

出力:
- topic candidates
- intent candidates
- cluster summary
- keywords

責務:
- クラスタの意味解釈
- KU生成の前段

### Step 6: KnowledgeUnitGeneration
入力:
- cluster analysis outputs
- representative rows
- KU prompt/schema

出力:
- structured Knowledge Units
- confidence
- validation results

責務:
- 最終知識単位生成
- JSON schema 準拠保証

### Step 7: ExportAndFinalize
入力:
- knowledge units
- original rows
- export config

出力:
- enriched rows CSV
- knowledge units JSON/CSV
- summary report
- export records

責務:
- 外部利用形式への変換
- S3 配置
- DB メタデータ保存

## 5.4 中間データ保存方針

| data | storage | reason |
|---|---|---|
| raw upload | S3 | 原本保存 |
| normalized rows | S3 / RDS | 再利用と監査 |
| embedding matrix | S3 | 大容量バッチ向け |
| cluster result | RDS | 業務的意味のある中間成果物 |
| knowledge units | RDS | 最終成果物 |
| exports | S3 | ダウンロード用 |

---

# 6. System Architecture

## 6.1 基本方針

上級エンジニアレビューを踏まえ、初期実装では **Laravel を Control Plane、Python on ECS Fargate を Data Plane** とする。

この構成により：

- Web/API 開発速度を確保
- 重い NLP / clustering を Python に集約
- AWS 請求を一元化
- 将来の拡張余地を確保

## 6.2 全体アーキテクチャ図

```text
[User]
   ↓
[Laravel Web/API]
   - Auth
   - Dataset Upload
   - Job Management
   - KU Review UI
   ↓
[Laravel Queue / Horizon]
   ↓
[SQS or ECS RunTask Trigger]
   ↓
[Python Worker on ECS Fargate]
   - Preprocess
   - Embedding
   - Clustering
   - Cluster Analysis
   - KU Generation
   ↓
[RDS PostgreSQL + pgvector]
[S3]
[Amazon Bedrock]
```

## 6.3 レイヤ分離

### Control Plane
担当:
- 認証
- API
- ファイル受付
- ジョブ登録
- ステータス管理
- Knowledge Unit レビューUI
- エクスポート配布

技術:
- Laravel
- Laravel Queue / Horizon
- Sanctum
- Eloquent

### Data Plane
担当:
- 前処理
- 埋め込み生成
- クラスタリング
- LLM呼び出し
- KU生成

技術:
- Python
- ECS Fargate
- NumPy / Pandas
- hdbscan / scikit-learn
- Bedrock SDK

## 6.4 AWS コンポーネント

| layer | component | role |
|---|---|---|
| Web/API | Laravel on ECS/EC2 | UI / API / Job control |
| Queue | Laravel Queue + Horizon | 非同期ジョブ管理 |
| Message / Trigger | SQS or ECS RunTask | Python処理起動 |
| Batch Compute | ECS Fargate | 重い処理 |
| Database | RDS PostgreSQL + pgvector | 永続化 / KU検索 |
| Object Storage | S3 | 原本 / 中間成果物 / exports |
| AI | Amazon Bedrock | embedding / LLM |
| Monitoring | CloudWatch | logs / metrics |
| Secrets | Secrets Manager | API keys / credentials |

## 6.5 S3 パス設計

```text
s3://bucket/{tenant_id}/datasets/{dataset_id}/raw/
s3://bucket/{tenant_id}/jobs/{job_id}/preprocess/
s3://bucket/{tenant_id}/jobs/{job_id}/embedding/
s3://bucket/{tenant_id}/jobs/{job_id}/clustering/
s3://bucket/{tenant_id}/jobs/{job_id}/analysis/
s3://bucket/{tenant_id}/jobs/{job_id}/exports/
s3://bucket/{tenant_id}/embedding-cache/{embedding_hash}/
```

## 6.6 マルチテナント方針

- 全主要テーブルに `tenant_id`
- Eloquent Global Scope
- PostgreSQL Row-Level Security は Phase 2 以降で導入検討
- S3 パスを tenant prefix で分離
- バッチ起動時に tenant_id を必須引数とする
- テナント越境テストを自動化対象に含める

## 6.7 初期実装の技術判断

### 採用
- Laravel Control Plane
- Python on ECS Fargate
- RDS PostgreSQL + pgvector
- S3 + embedding cache
- HDBSCAN
- Bedrock
- Laravel Job Chain / Queue

### 後回し
- Step Functions
- OpenSearch
- 専用 Vector DB
- SageMaker Processing
- 高度な AB テスト基盤

---

# 7. 実装優先順位

## Phase 0
- Laravel 基盤
- RDS PostgreSQL + pgvector
- S3 バケット
- Python worker container
- 認証
- jobs / datasets / dataset_rows テーブル

## Phase 1
- Upload
- ValidateAndEstimate
- Preprocess
- Embedding
- Clustering
- Cluster 保存

## Phase 2
- ClusterAnalysis
- KnowledgeUnitGeneration
- KU Review
- Export

## Phase 3
- Embedding cache 強化
- Quality metrics
- Multi-tenant hardening
- Cost optimization

---

# 8. 設計上の最重要判断

1. **Knowledge Unit を最終成果物とする**
2. **Cluster は中間生成物と位置づける**
3. **再現性のため Pipeline Config を保存する**
4. **重い処理は Python + Fargate に寄せる**
5. **Embedding は S3 キャッシュ + pgvector 検索の二層で扱う**
6. **初期は Laravel Job Chain、将来 Step Functions へ移行可能にする**
7. **ValidateAndEstimate によりコスト事故を防ぐ**

---

# 9. 次の設計成果物

1. Laravel Migration Draft
2. Python Worker Interface Spec
3. Knowledge Unit Prompt Design
4. ValidateAndEstimate Cost Formula
5. Export Schema Specification
6. Phase 0 Implementation Plan

---

# 10. 結論

本システムはクラスタリングツールではなく、**チャットボット向けナレッジ生成のための準備基盤** である。

そのため、設計の中心は：

- 画面ではなくデータモデル
- クラスタではなく Knowledge Unit
- 単発処理ではなく再現可能なジョブ
- API ではなくパイプライン

に置く。

本ドキュメントの 1〜6 は、その骨格を固定するための CTOレベル設計成果物である。
