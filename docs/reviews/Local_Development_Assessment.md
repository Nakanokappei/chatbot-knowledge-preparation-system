# ローカル開発環境アセスメント — シナリオ A

**日付**: 2026-03-23
**結論**: **Phase 1 終了まで自宅環境のみで開発可能**

---

## 現状（既に動作確認済み）

| コンポーネント | ローカル実装 | 状態 |
|--------------|------------|------|
| Laravel | Herd (PHP 8.4) | ✅ 動作中 |
| PostgreSQL | Homebrew (17 + pgvector 0.8.2) | ✅ 動作中 |
| Python Worker | Python 3.13 直接実行 | ✅ 疎通確認済み |

---

## AWS サービスのローカル代替

| AWS サービス | ローカル代替 | 方法 |
|-------------|------------|------|
| **SQS** | Laravel database queue + Python `--local` モード | Laravel がDBキューに投入 → Python Worker をCLIで直接起動 |
| **S3** | ローカルファイルシステム | Laravel の `local` ディスク (`storage/app/`) |
| **Bedrock** | Bedrock API 直接呼び出し | AWS 認証情報のみ必要。**従量課金のためローカルでも利用可**（月 ~¥300） |
| **ECS Fargate** | 不要 | Python を直接実行 |
| **CloudWatch** | Laravel Telescope + ファイルログ | `/tmp/knowledge-prep-debug.log` |

---

## 具体的な構成変更

### 1. SQS → Laravel database queue + Python CLI

SQS を使わず、Laravel と Python を以下のように連携：

```
Laravel API → pipeline_jobs テーブルに status='submitted' で保存
    ↓
開発者が手動で Python Worker を起動（またはスクリプトで自動化）
    ↓
Python Worker --local '{"job_id":N, ...}' → RDS 直接更新
    ↓
Laravel API で結果確認
```

Phase 1 以降で自動化が必要になったら：
- Laravel の database queue で Job を dispatch
- Python 側のウォッチスクリプトが pipeline_jobs テーブルをポーリング

### 2. S3 → ローカルストレージ

```
本番: s3://bucket/{tenant_id}/datasets/{dataset_id}/raw/
    ↓
ローカル: storage/app/{tenant_id}/datasets/{dataset_id}/raw/
```

Laravel の Filesystem 設定で `FILESYSTEM_DISK=local` に切り替えるだけ。
コード側は `Storage::disk()` で抽象化済みのため変更不要。

### 3. Bedrock → AWS 認証情報のみ設定

Bedrock は API サービスのため、ローカルからも呼び出し可能。

```bash
# ~/.aws/credentials に設定するだけ
aws configure
```

Phase 1 で Embedding/LLM を使い始めた時点で従量課金が発生：
- 1万件テスト: 約 $2.41（¥360）
- 月間テスト 5回: 約 $12（¥1,800）

---

## ローカル開発ワークフロー

```
1. Laravel 起動
   $ cd app && php artisan serve
   → http://localhost:8000

2. CSV アップロード（curl or Postman）
   $ curl -X POST http://localhost:8000/api/datasets \
       -H "Authorization: Bearer {token}" \
       -F "file=@test.csv" -F "name=テストデータ"

3. パイプラインジョブ作成
   $ curl -X POST http://localhost:8000/api/pipeline-jobs \
       -H "Authorization: Bearer {token}" \
       -d '{"dataset_id": 1}'

4. Python Worker 実行
   $ cd worker && python3 -m src.main --local \
       '{"job_id":1,"tenant_id":1,"step":"preprocess"}'

5. 結果確認
   $ curl http://localhost:8000/api/pipeline-jobs/1 \
       -H "Authorization: Bearer {token}"
```

---

## フェーズ別の AWS 依存

| Phase | AWS 必要? | 必要なもの | 月額目安 |
|-------|----------|-----------|---------|
| Phase 0 | **不要** | なし（全てローカル） | ¥0 |
| Phase 1 | **Bedrock のみ** | AWS 認証情報 + Bedrock アクセス | ~¥1,800 |
| Phase 2 | **Bedrock のみ** | 同上 | ~¥1,800 |
| Phase 3 以降 | 必要 | AWS デプロイ（シナリオ B or C） | ¥8,700〜 |

---

## 必要な設定変更

`.env` を以下に変更するだけ：

```
FILESYSTEM_DISK=local          # S3 → ローカル
QUEUE_CONNECTION=database      # SQS → DB キュー
```

---

*上級エンジニア — 2026-03-23*
