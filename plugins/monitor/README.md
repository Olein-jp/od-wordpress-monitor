# OD WordPress Monitor

OD Monitor Agent を導入したWordPressサイトを登録し、接続状況と基本バージョン情報を確認する管理プラグインです。

## 必要環境

- WordPress 6.8 以上
- PHP 8.1 以上（libsodium必須）
- HTTPSで公開された監視対象サイト

## インストール

1. `plugins/monitor` で `composer install --no-dev` を実行します。
2. ディレクトリを `od-wordpress-monitor` としてMonitorサイトへ配置し、有効化します。
3. 管理画面の「WordPress Monitor」→「Add Site」を開きます。
4. Site Name、Site URL、Agent Username、Application Passwordを入力します。

登録前に `/ping` と `/status` の両方を検証します。Application Passwordはlibsodiumで暗号化して保存し、登録後の画面やHTMLへ再表示しません。「Test Connection」から接続確認を再実行できます。
