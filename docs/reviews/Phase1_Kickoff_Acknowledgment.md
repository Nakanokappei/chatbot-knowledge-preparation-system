# Phase 1 着手確認 — 上級エンジニア

**日付**: 2026-03-24
**宛先**: CTO
**件名**: Phase 0 承認受領 + Phase 1 着手宣言

---

## 1. 承認事項の確認

以下の CTO 決定を確認・受領しました。

### 4 Plane Architecture（確定）

```
User → Laravel (Control Plane) → SQS (Queue Plane) → Python Worker (Data Plane) → RDS / S3 (Storage Plane)
```

### Phase 1 技術決定（確定）

| 項目 | 決定 |
|------|------|
| Embedding モデル | Amazon Titan Embed v2 |
| Embedding 次元 | 1024 |
| Clustering | HDBSCAN |
| min_cluster_size | 15 |
| 実行環境 | ローカル Python（Phase 3 で Fargate 移行） |
| テストデータ | CTO 提供 |

### Phase 1 完了基準（確定、9 項目）

1. CSV アップロード → dataset_rows 保存
2. dataset_rows → Embedding 生成
3. Embedding → S3 に保存
4. Embedding Cache が機能
5. Embedding → HDBSCAN → clusters 作成
6. dataset_rows → cluster_memberships 保存
7. clusters テーブルに cluster_size 保存
8. Laravel Dashboard でクラスタ数・サイズ確認可能
9. 同じ CSV を再実行した場合、Embedding Cache がヒット

---

## 2. Phase 1 実装計画

### Step 分割

Phase 0 のステップレジストリに以下の 3 ステップを追加します。

| Step | 処理内容 | 実行環境 |
|------|---------|---------|
| `preprocess` | CSV パース → dataset_rows 保存 → テキスト正規化 | Python Worker (ローカル) |
| `embedding` | dataset_rows → Titan Embed v2 → S3 保存 + キャッシュ | Python Worker (ローカル) |
| `clustering` | S3 Embedding 読込 → HDBSCAN → clusters + cluster_memberships 保存 | Python Worker (ローカル) |

### パイプラインフロー

```
Laravel API (Job 作成)
  ↓ SQS: {step: "preprocess"}
Python Worker: preprocess
  ↓ SQS: {step: "embedding"}
Python Worker: embedding
  ↓ SQS: {step: "clustering"}
Python Worker: clustering
  ↓ RDS: Job status = completed
Laravel Dashboard: クラスタ結果表示
```

### スケジュール

| 期間 | タスク | 完了基準 |
|------|--------|---------|
| Day 1-2 | Preprocess ステップ | #1 CSV → dataset_rows |
| Day 3-5 | Embedding ステップ | #2 #3 #4 Embedding 生成 + S3 保存 + キャッシュ |
| Day 6-8 | Clustering ステップ | #5 #6 #7 HDBSCAN + clusters + memberships |
| Day 9 | Dashboard 拡張 | #8 クラスタ結果表示 |
| Day 10 | 結合テスト + キャッシュ検証 | #9 再実行でキャッシュヒット |

---

## 3. 設計上の判断事項

### 3.1 ステップ間のデータ受け渡し

各ステップは S3 パスで中間データを受け渡します（設計原則 #3 準拠）。

```
s3://knowledge-prep-data-dev/{tenant_id}/jobs/{job_id}/
  ├── preprocess/
  │   └── normalized_rows.parquet
  ├── embedding/
  │   └── embeddings.npy
  └── clustering/
      └── cluster_results.json
```

### 3.2 Embedding キャッシュ設計

```
キャッシュキー: SHA256(normalized_text + "titan-embed-v2" + "1024")
格納先: embedding_cache テーブル (Phase 0 で Migration 済み)
ヒット時: S3 から既存 Embedding を取得（API 呼び出しスキップ）
```

### 3.3 ステップチェイン

各ステップ完了時に、次のステップを SQS に自動送信します。

```python
# preprocess ステップ完了後
sqs.send_message(step="embedding", input_s3_path="s3://.../preprocess/")

# embedding ステップ完了後
sqs.send_message(step="clustering", input_s3_path="s3://.../embedding/")
```

---

## 4. テストデータについて

CTO 提供のテスト CSV をお待ちしています。

CSV がなくても、以下のダミーデータで開発を先行します：
- 100 行程度のカスタマーサポートログ（英語）
- カラム: `id`, `text`, `category`（任意）

CSV 提供後に実データで検証を行います。

---

## 5. 着手宣言

**Phase 1 実装を本日より開始します。**

最初のアクションは以下の通りです：

1. Preprocess ステップの実装（`worker/src/steps/preprocess.py`）
2. ステップチェイン機構の実装（ステップ完了 → 次ステップ自動送信）
3. ダミーテストデータの作成

---

*上級エンジニア — 2026-03-24*
