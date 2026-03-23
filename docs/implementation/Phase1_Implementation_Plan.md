# Phase 1 実装計画

**ステータス**: CTO 承認済み
**期間**: 2 週間（10 営業日）
**目標**: CSV → Embedding → Clustering → Database 保存の自動パイプライン

---

## 確定パラメータ（CTO 決定）

| 項目 | 値 |
|------|-----|
| Embedding モデル | Amazon Titan Text Embeddings V2 |
| Embedding 次元 | 1024 |
| Clustering アルゴリズム | HDBSCAN |
| min_cluster_size | 15 |
| 実行環境 | ローカル Python |

---

## 完了基準（9 項目）

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

## 新規 Python 依存（Phase 1 追加分）

```toml
[project]
dependencies = [
    "boto3>=1.35.0",
    "psycopg2-binary>=2.9.10",
    "python-dotenv>=1.0.0",
    "numpy>=2.0",
    "pandas>=2.2",
    "hdbscan>=0.8.40",
    "pyarrow>=18.0",       # Parquet 読み書き
]
```

---

## Day 1-2: Preprocess ステップ

### 実装ファイル
- `worker/src/steps/preprocess.py`

### 処理内容
1. `dataset_rows` から対象 Job の行を取得
2. テキスト正規化:
   - 空白の統一（全角→半角、連続空白→単一）
   - 制御文字の除去
   - 先頭・末尾の空白トリム
3. 正規化結果を `dataset_rows.normalized_text` に更新
4. 正規化済みデータを Parquet 形式で S3 に保存
5. 次ステップ（embedding）を SQS に送信

### S3 出力パス
```
s3://knowledge-prep-data-dev/{tenant_id}/jobs/{job_id}/preprocess/normalized_rows.parquet
```

---

## Day 3-5: Embedding ステップ

### 実装ファイル
- `worker/src/steps/embedding.py`
- `worker/src/bedrock_client.py`

### 処理内容
1. S3 から正規化済み Parquet を読み込み
2. 各行の normalized_text に対して:
   a. キャッシュキー算出: `SHA256(normalized_text + "titan-embed-v2" + "1024")`
   b. `embedding_cache` テーブルでキャッシュチェック
   c. キャッシュミスの行のみ Bedrock Titan Embed v2 を呼び出し
   d. キャッシュミス分を `embedding_cache` に保存
3. 全行の Embedding を NumPy 配列に集約
4. S3 に `.npy` 形式で保存
5. 次ステップ（clustering）を SQS に送信

### Bedrock 呼び出し
```python
bedrock.invoke_model(
    modelId="amazon.titan-embed-text-v2:0",
    body=json.dumps({
        "inputText": normalized_text,
        "dimensions": 1024,
        "normalize": True
    })
)
```

### バッチ処理
- 1 回の API 呼び出しで 1 テキスト（Titan Embed v2 の制約）
- 並列度: 10 同時リクエスト（asyncio or ThreadPoolExecutor）
- レート制限: Exponential backoff + 最大 3 回リトライ

### キャッシュ設計
```sql
-- embedding_cache テーブル (Phase 0 で作成済み)
embedding_hash  VARCHAR(64) PRIMARY KEY  -- SHA256 hex
embedding_model VARCHAR(100)
dimension       INTEGER
s3_path         VARCHAR(500)  -- S3 上の個別 Embedding ファイルパス or NULL
embedding_vector VECTOR(1024) -- pgvector (オプション)
created_at      TIMESTAMP
```

### S3 出力パス
```
s3://knowledge-prep-data-dev/{tenant_id}/jobs/{job_id}/embedding/embeddings.npy
```

---

## Day 6-8: Clustering ステップ

### 実装ファイル
- `worker/src/steps/clustering.py`

### 処理内容
1. S3 から Embedding `.npy` を読み込み
2. HDBSCAN 実行:
   ```python
   import hdbscan
   clusterer = hdbscan.HDBSCAN(
       min_cluster_size=15,
       min_samples=5,
       metric='euclidean',
       cluster_selection_method='eom',
       prediction_data=True,
   )
   labels = clusterer.fit_predict(embeddings)
   ```
3. クラスタ結果を RDS に保存:
   - `clusters` テーブル: クラスタ毎のレコード（label, row_count）
   - `cluster_memberships` テーブル: 各行のクラスタ所属
4. クラスタ品質メトリクス算出（シルエットスコア等）
5. 結果 JSON を S3 に保存
6. Job status を `completed` に更新

### HDBSCAN パラメータ（CTO 確定 + 補完）
```python
{
    "min_cluster_size": 15,      # CTO 決定
    "min_samples": 5,            # デフォルト推奨
    "metric": "euclidean",       # Titan Embed は正規化済み
    "cluster_selection_method": "eom",  # Excess of Mass
}
```

### RDS 保存内容

**clusters テーブル:**
```sql
INSERT INTO clusters (job_id, tenant_id, cluster_label, row_count, quality_score)
VALUES (job_id, tenant_id, label, count, silhouette_score);
```

**cluster_memberships テーブル:**
```sql
INSERT INTO cluster_memberships (cluster_id, dataset_row_id, membership_score)
VALUES (cluster_id, row_id, probability);
```

### S3 出力パス
```
s3://knowledge-prep-data-dev/{tenant_id}/jobs/{job_id}/clustering/cluster_results.json
```

---

## Day 9: Dashboard 拡張

### 追加表示項目
- クラスタ数（ノイズ除く）
- 各クラスタのサイズ（行数）
- ノイズポイント数
- シルエットスコア

### 表示イメージ
```
Job #5: completed (100%)
  Dataset: support_logs.csv (500 rows)
  Clusters: 12 (+38 noise points)
  Quality: Silhouette = 0.42

  Cluster | Size | % of Total
  --------|------|----------
  0       | 85   | 17.0%
  1       | 72   | 14.4%
  ...
```

---

## Day 10: 結合テスト + キャッシュ検証

### テストシナリオ

1. **初回実行**: CSV → Preprocess → Embedding → Clustering → 完了
   - 全行で Bedrock API 呼び出し
   - キャッシュミス率 = 100%

2. **再実行**: 同じ CSV で新 Job 作成 → 再実行
   - Embedding キャッシュヒット率 = 100%
   - Bedrock API 呼び出し = 0 回
   - クラスタリング結果が同一であることを確認

---

## ステップチェイン機構

各ステップ完了時に次ステップを自動送信します。

```python
# worker/src/step_chain.py
STEP_SEQUENCE = ["preprocess", "embedding", "clustering"]

def dispatch_next_step(current_step, job_id, tenant_id, dataset_id, output_s3_path, pipeline_config):
    """Send the next step message to SQS after current step completes."""
    idx = STEP_SEQUENCE.index(current_step)
    if idx + 1 >= len(STEP_SEQUENCE):
        return None  # No more steps; pipeline complete

    next_step = STEP_SEQUENCE[idx + 1]
    sqs.send_message(
        QueueUrl=SQS_QUEUE_URL,
        MessageBody=json.dumps({
            "job_id": job_id,
            "tenant_id": tenant_id,
            "dataset_id": dataset_id,
            "step": next_step,
            "input_s3_path": output_s3_path,
            "pipeline_config": pipeline_config,
        })
    )
    return next_step
```

---

## リスクと対策

| リスク | 対策 |
|--------|------|
| Bedrock Titan Embed v2 のレート制限 | ThreadPool 並列度を 10 に制限 + Exponential backoff |
| 大量データでの HDBSCAN メモリ不足 | Phase 1 はローカル実行のため Mac のメモリ (16GB+) で対応。10 万件超は Phase 3 Fargate |
| Embedding 次元 1024 でのキャッシュサイズ | 1 万件 × 1024 × 4bytes = 約 40MB。Phase 1 では問題なし |
| Bedrock の初回利用でユースケース入力を求められる | 画面指示に従って入力 |

---

*上級エンジニア — 2026-03-24*
