# ADR-0005: Laravel ↔ Python Worker 通信方式

**ステータス**: 提案（CTO承認待ち）
**日付**: 2026-03-23
**提案者**: 上級エンジニア

---

## Context

本システムは Laravel（Control Plane）と Python on Fargate（Data Plane）の 2 層構成を採用する。両者間の通信方式は、パイプライン全体の信頼性・デバッグ容易性・実装コストに直結する最重要インフラ決定の一つである。

## 選択肢

### A: SQS + DB ポーリング（推奨）

```
Laravel → SQS → Python Worker → RDS 直接更新
Laravel Horizon が DB をポーリングして UI 反映
```

- Python は Laravel API に依存しない
- SQS がバッファとなり Fargate 起動遅延を吸収
- デッドレターキューで失敗メッセージを保持
- RDS は両方からアクセス可能

### B: SQS + Laravel Callback API

```
Laravel → SQS → Python Worker → Laravel API を呼び出し
```

- Laravel 側に Webhook 受信エンドポイントが必要
- Python から Laravel への認証・CSRF 管理が追加負担
- Laravel API のダウンタイムが Python 側の完了通知を阻害

### C: ECS RunTask 直接呼び出し

```
Laravel → ECS RunTask API → Python Worker → RDS 直接更新
```

- SQS を介さず直接起動
- Fargate 起動待ち（30秒〜2分）の間 Laravel が同期ブロック or ポーリング必要
- メッセージバッファがないためバースト時の制御が困難

## Decision

**方式 A: SQS + DB ポーリングを採用する。**

## Consequences

### メリット
- Python Worker が Laravel に依存しない（疎結合）
- SQS の可視性タイムアウト＋デッドレターキューで障害復旧が容易
- Laravel 再デプロイ中も Python 処理は継続可能
- 実装がシンプル（Python 側は SQS 受信 + DB 書き込みのみ）

### デメリット
- DB ポーリングの遅延（数秒〜十数秒）。リアルタイム性は不要なため許容範囲
- RDS への接続元が 2 系統になるため、コネクション管理に注意

### 実装概要

**SQS メッセージフォーマット**:
```json
{
  "job_id": "job_20260323_001",
  "tenant_id": "tenant_001",
  "step": "embedding",
  "input_s3_path": "s3://bucket/tenant_001/jobs/job_001/preprocess/",
  "pipeline_config": { ... }
}
```

**Python Worker のフロー**:
1. SQS からメッセージ受信
2. RDS の `jobs.status` を更新（例: `embedding`）
3. S3 から入力データ読み込み
4. 処理実行
5. S3 に結果書き出し
6. RDS の `jobs` を更新（status, progress, step_outputs_json）
7. 次ステップの SQS メッセージ送信（パイプライン継続）
8. 全ステップ完了時は `jobs.status = completed`

**Laravel 側のフロー**:
1. ジョブ作成 → DB 保存 → SQS 送信
2. Horizon Worker が `jobs` テーブルをポーリング（10秒間隔）
3. ステータス変更を検知 → UI 反映 / 通知

---

## 関連
- ADR-0001: Laravel Control Plane
- ADR-0002: Fargate Data Plane
