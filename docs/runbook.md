# OD WordPress Monitor 運用手順

## Scheduler の状態確認

WordPress 管理画面の「WordPress Monitor」→「Dashboard」にある「Scheduler Health」で、監視ジョブごとの状態を確認できます。

- `Healthy`: ジョブが登録され、最終完了または次回予定が想定範囲内です。
- `Stale`: ジョブの予定がないか、最終完了または実行中の開始時刻から想定間隔の2倍以上経過しています。
- `Running`: 実行開始を記録し、まだ完了を記録していません。長時間続いて `Stale` になった場合は、PHPエラーやプロセス停止を確認してください。
- `Succeeded` / `Failed`: 直近の実行が正常終了したか、Scheduler 内で失敗したかを示します。

表示される保存情報は、最終開始時刻、最終完了時刻、結果、処理件数だけです。認証情報、URL、レスポンス本文、例外メッセージは保存しません。

## WP-Cron の診断

WordPress の設置ディレクトリと対象URLを明示して、実行環境を取り違えないようにします。マルチサイトでは `--url` が必須です。

```bash
wp cron test --path=/var/www/example --url=https://monitor.example.com
wp cron event list --path=/var/www/example --url=https://monitor.example.com --fields=hook,next_run_gmt,recurrence
```

一覧に次のフックがない場合でも、プラグインが有効であれば通常の WordPress リクエスト時に再登録されます。再登録されない場合は、プラグインの有効状態と PHP エラーログを確認してください。

- `odm_run_scheduled_check`
- `odm_cleanup_checks`

## 手動実行

調査時は、すべての WordPress Cron を無条件に実行せず、このプラグインの期限到来済みフックだけを対象にします。

```bash
wp cron event run odm_run_scheduled_check odm_cleanup_checks --due-now --path=/var/www/example --url=https://monitor.example.com
```

コマンドの引数と `--due-now` の動作は、[WP-CLI の公式コマンドリファレンス](https://developer.wordpress.org/cli/commands/cron/event/run/)で確認できます。

実行後、Dashboard の最終開始・完了・結果・次回予定が更新されたことを確認します。

## WP-Cron を無効化する環境

アクセス数が少ない環境や、`DISABLE_WP_CRON` を `true` にしている環境では、OS の system cron から WP-CLI を5分ごとに実行します。パス、URL、WP-CLI の絶対パスは環境に合わせて変更してください。

```cron
*/5 * * * * cd /var/www/example && /usr/local/bin/wp cron event run odm_run_scheduled_check odm_cleanup_checks --due-now --path=/var/www/example --url=https://monitor.example.com --quiet
```

設定手順は次のとおりです。

1. `wp cron test` と手動実行が成功することを確認します。
2. system cron を登録し、実行ユーザーが WordPress ファイルとデータベースへ必要な権限を持つことを確認します。
3. system cron の実行ログと Dashboard の時刻更新を確認します。
4. 確認後にだけ `wp-config.php` へ `define( 'DISABLE_WP_CRON', true );` を設定します。

system cron が失敗した場合は、終了コード、PHP エラーログ、WP-CLI の出力を確認します。復旧後に上記の手動実行を行い、各ジョブが `Healthy` へ戻ることを確認してください。
