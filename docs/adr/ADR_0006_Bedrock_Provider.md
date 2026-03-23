# ADR-0006: Embedding / LLM プロバイダーに Amazon Bedrock を採用

**ステータス**: 提案（CTO承認待ち）
**日付**: 2026-03-23
**提案者**: 上級エンジニア

---

## Context

本システムは Embedding 生成と LLM 呼び出し（クラスタ命名・要約・KU生成）に AI モデルを使用する。プロバイダーの選択は、コスト・レイテンシ・データ所在・運用負荷に影響する。

## 選択肢

| 項目 | Amazon Bedrock | OpenAI API |
|------|---------------|------------|
| AWS 統合 | ネイティブ（IAM, VPC, CloudWatch） | 外部 API（キー管理別途） |
| データ所在 | AWS リージョン内で完結 | 外部送信 |
| Batch Inference | あり（50% コスト削減） | あり（50% コスト削減） |
| Embedding モデル | Titan Embed Text v2 | text-embedding-3-small/large |
| LLM モデル | Claude Sonnet | GPT-4o / Claude via API |
| 課金 | AWS 請求に統合 | 別請求 |
| レート制限 | Provisioned Throughput 可能 | Tier ベース |

## Decision

**Amazon Bedrock を Phase 1 の標準プロバイダーとする。**

### 採用モデル

| 用途 | モデル ID | 理由 |
|------|----------|------|
| Embedding | `amazon.titan-embed-text-v2:0` | AWS ネイティブ、1024 次元、コスト効率良 |
| LLM (ClusterAnalysis, KU生成) | `anthropic.claude-sonnet-4-20250514` | Structured Output 対応、日本語品質高 |

## Consequences

### メリット
- AWS 請求一元化（テナント別コスト配賦が容易）
- IAM ベースの認証（API キー管理不要）
- VPC エンドポイント経由でデータが AWS 内に留まる
- Batch Inference で非リアルタイム処理のコスト半減

### デメリット
- モデル選択肢が OpenAI より限定的
- Bedrock の新モデル提供が OpenAI より遅れる場合がある

### 将来の拡張
- Pipeline Config の `embedding.provider` / `llm.provider` を Strategy Pattern で抽象化済みのため、OpenAI への切り替えは Config 変更のみで可能

---

## 関連
- Pipeline Config Schema: `embedding.provider`, `llm.provider`
