# OD Monitor Agent

OD WordPress Monitor に、認証済みの読み取り専用サイト情報を提供するプラグインです。

## 必要環境

- WordPress 6.8 以上
- PHP 8.1 以上
- HTTPS

## インストールと設定

1. `plugins/agent` で `composer install --no-dev` を実行します。
2. ディレクトリを `od-monitor-agent` として監視対象サイトへ配置し、有効化します。
3. `OD Monitor Agent` role のユーザーを作成します。
4. そのユーザーのプロフィールで Application Password を発行します。

## API

- `GET /wp-json/od-monitor-agent/v1/ping`
- `GET /wp-json/od-monitor-agent/v1/status`
- `GET /wp-json/od-monitor-agent/v1/updates`
- `GET /wp-json/od-monitor-agent/v1/site-health`

Application Password を Basic Authentication で送信してください。すべてのエンドポイントは `od_monitor_read` capability を必要とし、書き込み操作は提供しません。`/updates` はWordPress本体・プラグイン・テーマのキャッシュ済み更新情報を返します。`/site-health` は外部通信やloopbackを行わない安全な同期テストだけを実行し、critical・recommended・goodの件数と各テストの識別子・状態・ラベルを返します。認証情報は必ず HTTPS で送信してください。

## 変更履歴

### 1.0.3

- RESTリクエストの状態に依存するPHPセッション診断をSite Health監視から除外し、管理画面との実行環境差によるcriticalの誤検知を防止しました。
- Site Healthのキャッシュキーを更新し、修正前のPHPセッション診断結果を再利用しないようにしました。

### 1.0.2

- 未認証のREST APIリクエストは401、権限不足のリクエストは403を返すようにしました。
- REST API内部で予期しないエラーが起きた場合、内部情報を含まない共通エラー応答を返すようにしました。

### 1.0.1

- 認証済みの読み取り専用 `/site-health` エンドポイントを追加しました。
- WordPress標準Site Healthのうち、安全性を確認した同期テストだけを実行するようにしました。
- 生の説明文、操作リンク、認証情報、環境詳細を応答へ含めず、結果を15分間キャッシュするようにしました。
