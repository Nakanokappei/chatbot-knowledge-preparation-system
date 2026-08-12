# AWS デモ環境の廃止と再移築手順

**廃止日**: 2026-08-13
**理由**: デモ期間の終了と、同一 AWS アカウントで voc-triage プロジェクトを稼働させるため
**廃止前の環境**: https://demo02.poc-pxt.com （dev 環境がそのまま顧客デモに使われていた）

このドキュメントは 2 つの問いに答えるためにある。

1. 手元で KPS を動かすには何をすればよいか（→ [ローカルで動かす](#ローカルで動かす)）
2. AWS に戻すには何をすればよいか（→ [AWS へ再移築する](#aws-へ再移築する)）

## 現在の状態（2026-08-13 時点）

**動いている KPS は存在しない。** AWS も、ローカルの docker 環境も削除済みで、
このリポジトリはアーカイブ（`02_Archive/`）に置かれている。

| | 状態 |
|---|---|
| AWS | `terraform destroy` で 85 リソースを削除。Terraform state は 0 リソース |
| Secrets Manager | `kps-dev/app-key` `kps-dev/database` `kps-dev/openai-api-key` を待機期間なしで完全消去 |
| CloudWatch Logs | KPS 由来のロググループ 4 件を削除 |
| ローカル docker | コンテナ・ボリューム（pgdata / csv-uploads / miniodata）・イメージをすべて削除 |
| `kps.sh` | 削除済みリソースを操作するため機能しない。再移築後は再び使える |

再開するには、下のどちらかの手順を最初から実行すること。
`docker compose up -d` しただけでは空の DB が立ち上がるだけで、デモデータは戻らない。

---

## 退避したもの

すべて `/Volumes/Satechi SSD/04_BackupSnapshots/kps-decommission-2026-08-13/` にある。
**このディレクトリが失われると再移築できない。** 中身の大半は Git 管理外だからである。

| パス | 中身 | 備考 |
|---|---|---|
| `db/kps.dump` | RDS `knowledge_prep` の pg_dump（custom 形式, 264MB） | 廃止直前の全データ |
| `db/globals.sql` | ロール定義（`pg_dumpall --globals-only`） | RDS 制約によりパスワードは含まれない |
| `s3/` | S3 `kps-dev-csv-uploads` の全 34,063 オブジェクト（2.4GB） | バケットと件数・バイト数が一致することを確認済み |
| `config/dev.tfvars`, `config/prod.tfvars` | Terraform 変数 | **Git 管理外**。IP 許可リスト・ドメイン・ハードニング値を含む |
| `config/docs/` | `docs/` 一式 | **Git 管理外** |
| `config/dot.env`, `config/dot.env.docker` | ローカル用環境変数 | **Git 管理外** |
| `config/secret-kps-dev-*.txt` | Secrets Manager の値（app-key / database / openai-api-key） | 平文。取り扱い注意 |
| `tfstate/terraform.tfstate.pre-destroy` | 削除直前の Terraform state | 旧構成の参照用 |

---

## ローカルで動かす

AWS が消えたため、SQS は **ElasticMQ**、S3 は **MinIO** に置き換えてある。
アプリケーションコードは変更していない。AWS SDK が標準で解釈する
`AWS_ENDPOINT_URL_SQS` / `AWS_ENDPOINT_URL_S3` を `docker-compose.yml` で
渡しているだけなので、この環境変数を外せばそのまま本物の AWS を向く。

### 起動

```bash
docker compose up -d
```

| サービス | ホスト側ポート | 備考 |
|---|---|---|
| app（Laravel） | 8000 | http://localhost:8000 |
| db（PostgreSQL + pgvector） | **5434** | voc-triage が 5433 を使うため 5433 から変更 |
| minio（S3 互換） | 9000 / 9001 | 9001 は Web コンソール |
| elasticmq（SQS 互換） | 9324 | キュー `kps-dev-pipeline` を自動作成 |

### データを投入する

DB とオブジェクトストレージの両方を戻す。バケット名は DB 内の `s3://` パスと
一致していなければならないため、AWS 時代と同じ `kps-dev-csv-uploads` を使う。

```bash
ARCHIVE="/Volumes/Satechi SSD/04_BackupSnapshots/kps-decommission-2026-08-13"
CID=$(docker compose ps -q db)

docker compose exec -T db psql -U ckps_user -d postgres -c "DROP DATABASE IF EXISTS knowledge_prep;"
docker compose exec -T db psql -U ckps_user -d postgres -c "CREATE DATABASE knowledge_prep OWNER ckps_user;"
docker cp "$ARCHIVE/db/kps.dump" "$CID:/tmp/kps.dump"
docker compose exec -T db pg_restore -U ckps_user -d knowledge_prep --no-owner --no-privileges /tmp/kps.dump
```

```bash
ARCHIVE="/Volumes/Satechi SSD/04_BackupSnapshots/kps-decommission-2026-08-13"
AWS_ACCESS_KEY_ID=ckps_local AWS_SECRET_ACCESS_KEY=ckps_local_secret AWS_DEFAULT_REGION=ap-northeast-1 \
  aws s3 sync "$ARCHIVE/s3" s3://kps-dev-csv-uploads --endpoint-url http://localhost:9000
```

### 動く範囲と動かない範囲

- **動く**: 既存デモデータの閲覧、CSV アップロード、ジョブ投入（Laravel → ElasticMQ → worker）、
  OpenAI 系の埋め込みモデル（`text-embedding-3-small` / `-large`）を使ったパイプライン
- **動かない**: Bedrock 系の埋め込みモデル（`amazon.titan-embed-text-v2:0`）。
  ローカルコンテナは MinIO 用のダミー認証情報を使うため、Bedrock は呼べない。
  必要なら app/worker に本物の AWS 認証情報を渡す

### 注意点

- `docker compose exec app php artisan ...` を **root で実行すると `storage/logs/laravel.log` が
  root 所有になり、以後 php-fpm（www-data）が書けず 500 になる。**
  発生したら `docker compose exec -T app chown -R www-data:www-data storage bootstrap/cache` で戻す
- `.env` の `SQS_QUEUE_URL` / `S3_BUCKET` は意図的に未設定。
  設定すると compose のローカル既定値を上書きしてしまう

---

## AWS へ再移築する

### 残っている前提資産（廃止時に消していない）

- Route 53 ホストゾーン `poc-pxt.com`
- ACM ワイルドカード証明書 `*.poc-pxt.com`
- Terraform state バケット `kps-terraform-state-891377034477`
- Terraform ロックテーブル `kps-terraform-lock`

これらは Terraform の data source またはブートストラップ資産で、`destroy` の対象外だった。
どれかが失われている場合は先に作り直すこと。

### 手順

1. **変数ファイルを戻す**（Git 管理外なのでアーカイブから）

   ```bash
   cp "/Volumes/Satechi SSD/04_BackupSnapshots/kps-decommission-2026-08-13/config/dev.tfvars" terraform/envs/dev.tfvars
   ```

2. **OpenAI キーの Secret を作り直し、ARN を書き換える**

   旧 Secret は廃止時に削除した。新しく作ると **ARN 末尾のランダム 6 文字が変わる**ため、
   `dev.tfvars` の `openai_api_key_secret_arn` を新しい ARN に更新しなければならない。

   ```bash
   aws secretsmanager create-secret --name kps-dev/openai-api-key \
     --secret-string "$(cat .../config/secret-kps-dev-openai-api-key.txt)" \
     --profile kps-company --region ap-northeast-1
   ```

3. **インフラを作る**

   ```bash
   cd terraform
   terraform init
   terraform apply -var-file=envs/dev.tfvars
   ```

   ECS サービスは `<ECR>:latest` を参照するが、この時点でイメージは無いのでタスクは
   起動に失敗する。手順 4 まで進めば解消する。

4. **イメージをビルドして配置する**

   `.github/workflows/deploy.yml` の `push:` トリガーを戻し（廃止時に外した）、
   main に push すると ECR ビルドと ECS デプロイが走る。手動なら `workflow_dispatch` でもよい。

5. **マイグレーションを流す**

   ```bash
   TASK=$(aws ecs list-tasks --profile kps-company --region ap-northeast-1 \
     --cluster kps-dev-cluster --service-name kps-dev-app --query 'taskArns[0]' --output text)
   PATH="/opt/homebrew/bin:$PATH" aws ecs execute-command --profile kps-company --region ap-northeast-1 \
     --cluster kps-dev-cluster --task "$TASK" --container app --interactive \
     --command "php artisan migrate --force"
   ```

6. **データを戻す**（必要なら）

   S3 は `aws s3 sync <アーカイブ>/s3 s3://kps-dev-csv-uploads`。
   DB は app コンテナに `apk add --no-cache postgresql17-client aws-cli` を入れ、
   アーカイブの `kps.dump` を S3 経由で渡して `pg_restore` する。
   DB パスワードは Terraform が新しく生成するので、コンテナの `$DB_PASSWORD` を使うこと
   （アーカイブの旧パスワードではない）。

### 再移築時にはまりやすい点

- **Secrets Manager の復旧待機期間**: 削除した Secret は既定 30 日間、同名で再作成できない。
  廃止時に `--force-delete-without-recovery` で完全消去してあるため、この問題は起きないはず
- **GitHub OIDC プロバイダ**: `token.actions.githubusercontent.com` はアカウントに 1 つしか
  作れない。廃止時点では KPS だけが使っていたので削除したが、再移築までに他プロジェクトが
  作成していた場合、Terraform の `aws_iam_openid_connect_provider` が
  `EntityAlreadyExists` で失敗する。その場合は `terraform import` で取り込む
- **IP 許可リスト**: `dev.tfvars` の `allowed_cidr_blocks` は当時の事務所・自宅 IP。
  接続できないときはまずここを疑う
