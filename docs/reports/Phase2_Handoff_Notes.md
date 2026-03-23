# Phase 2 引き継ぎ事項

**日付**: 2026-03-24
**状態**: Phase 2 実装中（cluster_analysis ステップのテスト待ち）

---

## 1. 現在の状態

### コミット

```
eb4f3df Phase 0-1 complete, Phase 2 in progress: Knowledge Preparation System
```

### Phase 2 進捗

| タスク | 状態 |
|--------|------|
| cluster_analysis_logs / knowledge_unit_versions Migration | ✅ 完了 |
| language カラム追加 (knowledge_units) | ✅ 完了 |
| Bedrock Claude LLM クライアント | ✅ 完了・接続確認済み |
| cluster_analysis ステップ | ✅ コード完了・**テスト待ち** |
| knowledge_unit_generation ステップ | ❌ 未実装 |
| Dashboard 拡張（KU 表示） | ❌ 未実装 |
| Knowledge Unit Export（JSON + CSV） | ❌ 未実装 |
| Phase 2 結合テスト | ❌ 未実施 |

---

## 2. ブロッカー

### Anthropic モデルアクセス承認待ち

- **状況**: AWS Bedrock で Anthropic Claude モデルのユースケースフォームを送信済み
- **待ち時間**: 15 分〜（送信時刻: 2026-03-24 01:30 頃）
- **影響**: `cluster_analysis` ステップが Claude Sonnet を呼び出すため、承認されるまでテスト不可
- **エラーメッセージ**: `ResourceNotFoundException: Model use case details have not been submitted`

### 確認方法

```bash
cd "/Volumes/Satechi SSD/01_Active/Chatbot Knowledge Preparation System/worker"
python3 -c "
from src.bedrock_llm_client import invoke_claude
result = invoke_claude('Say hello in JSON: {\"test\": true}')
print(result['parsed_json'])
"
```

成功すれば `{'test': True}` のような JSON が返る。

---

## 3. 再開手順

### Step 1: Anthropic アクセス確認

上記コマンドを実行。成功したら Step 2 へ。

### Step 2: cluster_analysis ステップのテスト

```bash
cd "/Volumes/Satechi SSD/01_Active/Chatbot Knowledge Preparation System/worker"

# Job #5 のステータスをリセット（前回テストで failed になっている）
python3 -c "
from src.db import get_connection
conn = get_connection()
cur = conn.cursor()
cur.execute(\"UPDATE pipeline_jobs SET status='clustering', progress=100 WHERE id=5\")
conn.commit()
cur.close()
conn.close()
print('Job 5 reset.')
"

# cluster_analysis を実行
python3 -m src.main --local '{"job_id":5,"tenant_id":1,"dataset_id":2,"step":"cluster_analysis","pipeline_config":{"phase":"2"}}'
```

期待される結果:
- 3 クラスタそれぞれに topic_name, intent, summary が付与される
- cluster_analysis_logs に 3 レコード（プロンプト + レスポンス）が保存される
- 次ステップ (knowledge_unit_generation) が SQS に送信される

### Step 3: knowledge_unit_generation ステップの実装

実装ファイル: `worker/src/steps/knowledge_unit_generation.py`

処理内容:
1. clusters テーブルから topic_name, intent, summary を取得
2. cluster_representatives から代表行テキストを取得
3. cluster_centroids から centroid_vector を取得
4. knowledge_units テーブルに INSERT
5. knowledge_unit_versions テーブルに v1 を INSERT
6. Job status = completed

main.py のステップ登録も必要:
```python
from src.steps import knowledge_unit_generation
STEP_HANDLERS["knowledge_unit_generation"] = knowledge_unit_generation
```

### Step 4: Dashboard 拡張

- `/knowledge-units` ページ追加（KU 一覧）
- ルート追加 (`routes/web.php`)
- DashboardController にメソッド追加
- `resources/views/dashboard/knowledge_units.blade.php` 作成

### Step 5: Export 機能

- `GET /api/knowledge-units/export?job_id={id}&format=json`
- `GET /api/knowledge-units/export?job_id={id}&format=csv`
- API Controller にメソッド追加

### Step 6: Phase 2 結合テスト

全パイプライン実行（5 ステップ自動チェイン）:
```
preprocess → embedding → clustering → cluster_analysis → knowledge_unit_generation
```

---

## 4. 主要ファイルの場所

### Python Worker

| ファイル | 役割 |
|---------|------|
| `worker/src/main.py` | エントリポイント + ステップレジストリ |
| `worker/src/step_chain.py` | ステップ自動チェイン（STEP_SEQUENCE 定義） |
| `worker/src/config.py` | 環境変数読み込み（.env） |
| `worker/src/db.py` | RDS 接続 + ジョブステータス更新 |
| `worker/src/bedrock_client.py` | Titan Embed v2 クライアント |
| `worker/src/bedrock_llm_client.py` | Claude Sonnet クライアント |
| `worker/src/steps/preprocess.py` | Phase 1: テキスト正規化 |
| `worker/src/steps/embedding.py` | Phase 1: Embedding 生成 + キャッシュ |
| `worker/src/steps/clustering.py` | Phase 1: HDBSCAN |
| `worker/src/steps/cluster_analysis.py` | Phase 2: LLM 分析（実装済み・テスト待ち） |

### Laravel

| ファイル | 役割 |
|---------|------|
| `app/app/Http/Controllers/DashboardController.php` | Dashboard + ジョブ詳細 + パイプライン dispatch |
| `app/routes/web.php` | Web ルート |
| `app/resources/views/dashboard/index.blade.php` | 一覧ページ |
| `app/resources/views/dashboard/show.blade.php` | ジョブ詳細（クラスタ結果表示） |

### 設定ファイル

| ファイル | 内容 |
|---------|------|
| `app/.env` | Laravel 環境変数（SQS URL, S3 バケット等） |
| `worker/.env` | Python Worker 環境変数 |
| `~/.aws/credentials` | AWS アクセスキー |

---

## 5. AWS リソース

| リソース | 名称 | リージョン |
|---------|------|-----------|
| SQS メインキュー | `ckps-pipeline-dev` | ap-northeast-1 |
| SQS DLQ | `ckps-dlq-dev` | ap-northeast-1 |
| S3 バケット | `knowledge-prep-data-dev` | ap-northeast-1 |
| Bedrock Embedding | Titan Embed v2 | ap-northeast-1 |
| Bedrock LLM | Claude 3.5 Sonnet v2 (APAC profile) | ap-northeast-1 |
| IAM ユーザー | `kappei` | - |
| AWS アカウント | `444332185803` | - |

---

## 6. CTO 決定事項（Phase 2）

| 項目 | 決定 |
|------|------|
| LLM | Claude 3.5 Sonnet (Bedrock, APAC profile) |
| Structured Output | 必須 |
| Prompt Versioning | `PROMPT_VERSION = "cluster_analysis_v1"` |
| LLM Logs | cluster_analysis_logs テーブルに保存（必須） |
| KU Versioning | knowledge_unit_versions テーブル |
| review_status | draft → reviewed → approved → rejected |
| Export | JSON + CSV |

### Phase 2 完了基準（8 項目）

1. cluster_analysis ステップが動作
2. topic_name が生成される
3. intent が生成される
4. summary が生成される
5. knowledge_units テーブルに保存
6. Knowledge Unit Dashboard 表示
7. Knowledge Unit Export JSON
8. Knowledge Unit Export CSV

---

## 7. テストデータ

- **CSV**: `customer_support_tickets.csv`（約 8,000 行、500 行をロード済み）
- **Dataset ID**: 2（`customer_support_tickets (500 rows)`）
- **既存ジョブ**: Job #4, #5, #6（Phase 1 テスト済み、各 3 クラスタ）
- **Embedding キャッシュ**: 486 件（97.2% ヒット率）

---

*上級エンジニア — 2026-03-24*
