# Implementation Review Request  
## Chatbot Knowledge Preparation System  
**CTO Draft – Implementation Challenges Review**

---

# 1. 目的

本システムは、カスタマーサポート履歴を分析し、  
チャットボットで利用可能な Knowledge Unit（ナレッジ単位）を生成するための  
ナレッジ準備システムを構築することを目的とする。

本ドキュメントは、実装前に技術的課題・アーキテクチャ上の問題・リスクを  
上級エンジニアにレビューしてもらうためのものである。

---

# 2. システムの最終目的

Support Logs → Embedding → Clustering → Topic / Intent Discovery →  
Cluster Summaries → Knowledge Unit Generation → Chatbot Knowledge Base

最終成果物は Knowledge Unit であり、クラスタリング結果は中間生成物である。

---

# 3. Knowledge Unit 定義（ドラフト）

| field | description |
|---|---|
| knowledge_unit_id | ID |
| dataset_id | 元データ |
| job_id | 生成ジョブ |
| cluster_id | 元クラスタ |
| topic | トピック |
| intent | ユーザー意図 |
| summary | 概要 |
| typical_case | 典型事例 |
| cause_summary | 原因要約 |
| resolution_summary | 対処要約 |
| notes | 注意点 |
| representative_rows | 代表レコード |
| row_count | 件数 |
| confidence | 信頼度 |
| review_status | レビュー状態 |
| version | バージョン |
| created_at | 作成日時 |

---

# 4. 実装上の技術課題（レビュー依頼）

## 4.1 Embedding 保存場所
- S3
- PostgreSQL + pgvector
- OpenSearch
- Vector DB

## 4.2 Clustering 実行環境
- Lambda
- ECS Fargate
- EC2
- SageMaker
- Python Service

## 4.3 Step Functions 設計粒度
Preprocess → Embedding → Clustering → Naming → Summaries → Knowledge Units → Export

## 4.4 Embedding キャッシュ設計
hash(normalized_text + embedding_model + dimension)

## 4.5 Job 再現性
- dataset version
- preprocess rule version
- embedding model
- clustering method
- clustering params
- prompt version
- random seed
- software version

## 4.6 Knowledge Unit 生成ロジック
Cluster → Knowledge Unit 変換ロジック

## 4.7 データモデル中心
dataset_rows → clusters → knowledge_units

## 4.8 Export 設計
- 元データ + topic
- Knowledge Units
- Cluster Summary
- Representative Examples

## 4.9 マルチテナント設計
tenant_id / S3 partition / DB partition / IAM / Cognito

## 4.10 コスト構造
Embedding / LLM / Fargate / Aurora / S3 / Step Functions

---

# 5. 上級エンジニアへのレビュー依頼事項

1. 全体アーキテクチャの妥当性  
2. Job System 設計  
3. Embedding 保存方式  
4. Clustering 実行環境  
5. Step Functions 設計粒度  
6. Data Model（Knowledge Unit 中心でよいか）  
7. Job 再現性設計  
8. Embedding キャッシュ戦略  
9. Knowledge Unit 生成ロジック  
10. Export 設計  
11. 将来拡張性  
12. コスト構造  
13. 技術的リスク  
14. 実装難易度が高い部分  
15. 実装順序  

---

# 6. CTO コメント

このシステムはクラスタリングツールではなく、  
チャットボットナレッジ生成基盤である。

最終成果物は Cluster ではなく Knowledge Unit。

Knowledge Unit を中心データモデルとして設計する方針。
