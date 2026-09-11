# Monitorのアンインストール・バックアップ・復元

## アンインストール方針

OD WordPress Monitorは、プラグインを削除しても保存済みデータを削除しません。`uninstall.php` は意図的に何も変更しない実装です。誤操作による監視履歴や資格情報の消失を避け、同じサイトへの再インストールや復元に利用できる状態を優先します。

保持対象は次のとおりです。

- 登録サイトと暗号化済みApplication Password
- 現在の監視状態、チェック履歴、状態変化イベント
- 通知設定、データベーススキーマバージョン、schedulerの状態
- 一時的なlock、batch cursor、status cacheなどのプラグイン固有option・transient

通常の無効化処理では、このプラグインが登録したWP-Cronイベントだけを解除します。Monitor以外のテーブル、option、ユーザー、投稿などは変更しません。現時点では保存データを消去する機能を提供していません。データ削除が必要な場合も、接頭辞を使った一括`DROP`や`DELETE`を実行せず、対象とバックアップを個別に確認する別作業として扱ってください。

Agent側の専用ユーザー、権限グループ、Application Passwordは、Monitor側プラグインの無効化・削除では変更されません。

## バックアップ対象

個別項目の取りこぼしを避けるため、Monitorを設置したWordPressのデータベース全体をバックアップする方法を推奨します。Monitor固有の必須テーブルは、WordPressのテーブル接頭辞を`wp_`とした場合、次の5つです。

- `wp_odm_sites`
- `wp_odm_credentials`
- `wp_odm_site_status`
- `wp_odm_checks`
- `wp_odm_events`

`wp_options`には、少なくとも次のMonitor固有データがあります。完全復元では`wp_options`を含むデータベース全体を復元してください。

- `odm_db_version`、`odm_db_migration_status`
- `odm_notification_settings`
- `odm_scheduler_heartbeat`
- `odm_batch_state_*`、`odm_lock_*`、`odm_db_migration_lock`
- `_transient_odm_status_*`、`_transient_odm_admin_notice_*`と対応するtimeout

lock、cursor、transientは復元に必須ではありませんが、ワイルドカードによる選択的な削除は対象外optionを巻き込む危険があるため行いません。期限切れデータは通常起動と定期cleanupで安全に処理され、WP-Cronイベントは再有効化時に再登録されます。

暗号化済みApplication Passwordを復号する鍵はDBへ保存されません。`CredentialEncryptor`は`wp_salt( 'auth' )`から鍵を導出するため、元環境で実際に使われているauth saltの供給元も必ずバックアップします。通常は`wp-config.php`の`AUTH_KEY`と`AUTH_SALT`ですが、環境変数やsecret managerから供給している場合は、その設定とsecretを同じ値で復元できるよう管理してください。saltを画面、コマンド出力、作業ログへ表示して記録してはいけません。

加えて、次の情報を保存します。

- 使用していたOD WordPress Monitorのバージョンと配布ZIP
- WordPress、PHP、データベースのバージョン
- WordPressのテーブル接頭辞と設置URL
- バックアップ日時、対象環境、ファイルのchecksum

## バックアップ手順

以下は単一サイトの例です。パス、URL、プラグインslugは実環境で確認してから置き換えます。

1. MonitorのDashboardとPHP・データベースログに未解決の失敗がないことを確認します。
2. メンテナンス時間を確保し、Monitorを無効化して新しい監視書き込みとWP-Cronイベントを止めます。
3. データベース全体をアクセス制限された保存先へexportします。
4. 有効なauth saltの供給元、プラグインZIP、環境情報を別の安全な経路で保管します。
5. exportの終了状態、ファイルサイズ、checksumを確認します。可能であれば隔離環境で復元テストを行います。

```bash
wp plugin get od-wordpress-monitor --fields=name,status,version
wp plugin deactivate od-wordpress-monitor
wp db export /secure-backups/monitor-YYYYMMDD.sql --single-transaction
sha256sum /secure-backups/monitor-YYYYMMDD.sql > /secure-backups/monitor-YYYYMMDD.sql.sha256
```

コマンドの引数は、実行環境のWP-CLIと[データベースexportの公式リファレンス](https://developer.wordpress.org/cli/commands/db/export/)で確認してください。`wp db export`は有効な`mysqldump`の追加引数を受け付けます。

バックアップ後も運用を続ける場合は、exportとchecksumの確認後にMonitorを再度有効化し、Dashboardの次回予定を確認します。プラグインを削除する場合は無効化した状態のまま削除します。どちらの場合も保存データはDBに残ります。

## バックアップの安全な取扱い

データベースバックアップには、サイトURL、監視履歴、通知先メールアドレス、ユーザー名、暗号化済みApplication Passwordが含まれます。DBとauth saltの両方を取得した者はApplication Passwordを復号できるため、両方を平文で同じ場所へ保管しないでください。

- 保存時と転送時に暗号化し、復元担当者だけへ最小権限でアクセスを許可する
- 公開URL、Issue、チャット、メールへ添付しない
- salt、SQL dump、Application Passwordをログやスクリーンショットへ出さない
- checksumは改ざん・破損確認に使い、secretそのものをchecksum名やメモへ含めない
- 組織の保持期間に従って期限切れバックアップを安全に破棄する

## 復元手順

1. 隔離環境またはメンテナンス状態のWordPressを用意し、元環境と同じテーブル接頭辞を設定します。
2. データベースを読み込む前に、元環境と同じauth saltが`wp_salt( 'auth' )`へ供給されるよう、`wp-config.php`、環境変数、secret managerを復元します。
3. checksumを照合したデータベースバックアップをimportします。URL変更を伴う移行はこの手順の対象外です。
4. バックアップ時と同じ版または互換性のある新しいMonitorを配置し、有効化します。有効化時に保存済みスキーマバージョンが確認され、必要なマイグレーションが完了した後だけ監視が開始されます。
5. 管理画面にデータベース更新失敗の通知がないこと、Dashboardに監視対象とschedulerの次回予定が表示されることを確認します。
6. 「WordPress Monitor」→「Sites」で各サイトの「Test Connection」を実行し、`/ping`と`/status`の両方が成功することを確認します。
7. 現在状態、直近のチェック履歴、イベント、通知設定を照合します。定期監視を1回実行し、新しい結果が保存されることを確認してからメンテナンス状態を解除します。

```bash
sha256sum -c /secure-backups/monitor-YYYYMMDD.sql.sha256
wp db import /secure-backups/monitor-YYYYMMDD.sql
wp plugin activate od-wordpress-monitor
wp option get odm_db_version
wp cron event list --hook=odm_run_scheduled_check
```

importとプラグイン操作の引数は、[データベースimport](https://developer.wordpress.org/cli/commands/db/import/)、[プラグイン有効化](https://developer.wordpress.org/cli/commands/plugin/activate/)の公式リファレンスで確認できます。

`CREDENTIAL_DECRYPTION_FAILED`になる場合は、復元したauth saltが元環境と同じか、別の供給元で上書きされていないかを確認します。正しいsaltを復元できない場合、既存の暗号化済みApplication Passwordは復号できません。`AUTHENTICATION_FAILED`になる場合は、Agent側でApplication Passwordが失効または削除されていないかを確認します。どちらの場合も、原因調査中にバックアップや暗号化済みcredentialを上書きしないでください。

この手順はMonitorの同一サイト復元を対象とします。汎用的なURL置換、マルチサイト移行、クラウドストレージ連携、独自バックアップサービスは対象外です。
