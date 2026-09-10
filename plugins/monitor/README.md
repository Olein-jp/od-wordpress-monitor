# OD WordPress Monitor

OD Monitor Agent を導入したWordPressサイトを登録し、接続状況、SSL証明書、基本バージョン情報、更新可否を定期確認する管理プラグインです。

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

## 定期監視

有効な登録サイトに対し、WordPressのWP-Cronから次の監視を実行します。

- HTTP稼働確認、Agent Ping：5分
- Agent Status：15分
- WordPress・プラグイン・テーマの更新確認：60分
- SSL証明書の検証と期限確認：24時間

同一サイト・監視種別の重複実行は期限付きlockで防止します。system cronからWordPress cronを起動する場合も同じ実行経路を使用します。

監視結果は履歴と現在状態として保存され、稼働停止、復旧、更新あり、SSL証明書の警告など、意味のある状態変化はイベントとして記録されます。チェック履歴は90日間保持され、それより古い履歴だけを日次で削除します。現在状態とイベント履歴はこの定期削除の対象外です。

## メール通知

管理画面の「WordPress Monitor」→「Notifications」で通知先メールアドレスを設定し、通知を有効化できます。正常または警告から異常へ変化したときに障害通知を送り、異常から正常へ戻ったときに復旧通知を送ります。同じ異常状態が続いている間は再送しません。

メール送信にはWordPress標準の `wp_mail()` を使用します。実際にメールを配送するには、Monitorサイト側でWordPressのメール送信環境が正しく設定されている必要があります。

## 変更履歴

### 1.0.2

- Monitor全体の通知ON/OFFと通知先メールアドレスを設定できるようにしました。
- 障害・復旧の状態変化をWordPress標準のメール機能で通知できるようにしました。
- 継続中の同一異常を再通知せず、送信成否を秘密情報なしでイベントへ記録するようにしました。

### 1.0.1

- 定期監視結果、サイトの現在状態、状態変化イベントの保存に対応しました。
- 90日を超えたチェック履歴を日次で削除する保持処理を追加しました。
- 保存する監視メタデータを必要最小限に制限しました。
