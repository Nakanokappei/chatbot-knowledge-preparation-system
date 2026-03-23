# Phase 0 実装計画

**ステータス**: CTO 承認待ち
**期間**: 2 週間
**目標**: パイプライン全体を支える基盤を構築し、Laravel ↔ Python Worker の疎通を確認する

---

## 完了基準

Phase 0 は以下がすべて達成された時点で完了とする：

1. ✅ Laravel プロジェクトが起動し、認証付き API が動作する
2. ✅ RDS PostgreSQL + pgvector が接続可能
3. ✅ S3 バケットへの読み書きが動作する
4. ✅ Python Worker コンテナが Fargate 上で起動する
5. ✅ Laravel → SQS → Python Worker → RDS 更新 の一連の疎通が成功する
6. ✅ `datasets` / `dataset_rows` / `jobs` テーブルが作成されている
7. ✅ CSV アップロード API が動作する（ファイルが S3 に保存される）

---

## Week 1: Laravel 基盤 + AWS インフラ

### Day 1-2: プロジェクト初期化

- [ ] Laravel 11+ プロジェクト作成
- [ ] Docker Compose 構成（PHP, PostgreSQL, Redis, LocalStack）
  - LocalStack: SQS / S3 のローカルエミュレーション
- [ ] `.env` 構成（AWS, DB, SQS 接続情報）
- [ ] Git リポジトリ初期化
- [ ] CI/CD の雛形（GitHub Actions: lint + test）

### Day 3-4: データベース + 認証

- [ ] RDS PostgreSQL セットアップ（開発: `db.t4g.medium`）
- [ ] pgvector 拡張有効化
- [ ] Laravel Migration 作成:
  - `tenants`
  - `datasets`
  - `dataset_rows`
  - `jobs`
  - `pipeline_configs`
  - `embedding_cache`
- [ ] Eloquent Model 作成（上記テーブル対応）
- [ ] `TenantScope` trait 実装
- [ ] Laravel Sanctum セットアップ
- [ ] テナント + ユーザーの Seeder

### Day 5: S3 + SQS

- [ ] S3 バケット作成（`{project}-data-dev`）
- [ ] Laravel Filesystem 設定（S3 ディスク）
- [ ] SQS キュー作成（標準キュー + デッドレターキュー）
- [ ] Laravel Queue 設定（SQS ドライバー）

---

## Week 2: Python Worker + 疎通確認

### Day 6-7: Python Worker コンテナ

- [ ] Python プロジェクト構成:
  ```
  worker/
  ├── Dockerfile
  ├── pyproject.toml
  ├── src/
  │   ├── __init__.py
  │   ├── main.py          # SQS ポーリングループ
  │   ├── config.py         # 環境変数読み込み
  │   ├── db.py             # RDS 接続 (psycopg2)
  │   ├── s3.py             # S3 操作
  │   └── steps/
  │       ├── __init__.py
  │       └── ping.py       # 疎通確認用ダミーステップ
  └── tests/
  ```
- [ ] Dockerfile 作成（Python 3.12 + 基本依存）
- [ ] ECR リポジトリ作成 + プッシュ
- [ ] ECS クラスター作成
- [ ] Fargate タスク定義（small: 2vCPU / 8GB）
- [ ] タスクロール（SQS 受信 + S3 読み書き + RDS 接続 + Bedrock 呼び出し）

### Day 8-9: 疎通確認

- [ ] `ping` ステップの実装:
  1. SQS からメッセージ受信
  2. RDS の `jobs` テーブルを `status = 'completed'` に更新
  3. S3 に `ping_result.json` を書き出し
- [ ] Laravel 側のテスト用 API:
  - `POST /api/jobs/ping` → Job 作成 + SQS 送信
  - `GET /api/jobs/{id}` → Job ステータス確認
- [ ] エンドツーエンド疎通テスト:
  ```
  Laravel API → SQS → Python Worker → RDS 更新 → Laravel API で確認
  ```

### Day 10: CSV アップロード + 仕上げ

- [ ] `POST /api/datasets` API 実装:
  - CSV ファイル受信
  - S3 にアップロード
  - `datasets` レコード作成
  - `dataset_rows` にパース・挿入（行数制限: Phase 0 は 1000 行まで）
- [ ] Phase 0 完了テスト実行
- [ ] Phase 0 Completion Report 作成

---

## 技術スタック（Phase 0 確定分）

| レイヤー | 技術 |
|---------|------|
| Language (API) | PHP 8.3 / Laravel 11 |
| Language (Worker) | Python 3.12 |
| Database | PostgreSQL 16 + pgvector |
| Queue | Amazon SQS |
| Storage | Amazon S3 |
| Container | Amazon ECS Fargate |
| Auth | Laravel Sanctum |
| Dev | Docker Compose + LocalStack |
| CI | GitHub Actions |

---

## Phase 0 で作成しないもの（スコープ外）

- Clustering / Embedding / LLM 呼び出し（Phase 1）
- Knowledge Unit テーブル（Phase 1 Migration で追加）
- KU Review UI（Phase 2）
- RLS（Phase 4）
- Fargate Spot 設定（Phase 3）
- Bedrock 呼び出し（Phase 1）

---

## リスクと対策

| リスク | 対策 |
|--------|------|
| pgvector 拡張が RDS で有効化できない | Aurora PostgreSQL への切り替え。Aurora は pgvector を公式サポート |
| Fargate タスク起動が遅い（コールドスタート 30秒〜2分） | Phase 0 では許容。Phase 3 でウォームプール検討 |
| LocalStack の SQS/S3 エミュレーションが不完全 | 開発環境でも AWS 実環境を使用する代替プラン |

---

## 成果物一覧

Phase 0 完了時に以下が揃う：

| 成果物 | 種別 |
|--------|------|
| Laravel プロジェクト（認証・API・Migration） | コード |
| Python Worker コンテナ（SQS 受信・DB 更新） | コード |
| Docker Compose（ローカル開発環境） | 設定 |
| ECS タスク定義（small） | インフラ |
| SQS キュー（標準 + DLQ） | インフラ |
| S3 バケット | インフラ |
| RDS PostgreSQL + pgvector | インフラ |
| 疎通確認結果 | レポート |

---

*上級エンジニア — 2026-03-23*
