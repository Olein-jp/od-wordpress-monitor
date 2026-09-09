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

Application Password を Basic Authentication で送信してください。すべてのエンドポイントは `od_monitor_read` capability を必要とし、書き込み操作は提供しません。`/updates` はWordPress本体・プラグイン・テーマのキャッシュ済み更新情報を返します。認証情報は必ず HTTPS で送信してください。
