# Architecture

## Components

`plugins/monitor` と `plugins/agent` は、それぞれ独立したComposer autoloadとbootstrapを持つ、単独配布可能なWordPressプラグインです。実行時に互いのPHPコードや `packages/protocol` を読み込みません。

```text
Monitor WordPress
  Admin UI → SiteService → AgentClient → HTTPS + Application Password
  Scheduler/runner → HttpMonitor → registered site URL
  Scheduler/runner → SslMonitor → verified TLS connection / certificate
  Scheduler/runner → AgentPingMonitor → CredentialService + AgentClient /ping
  Scheduler/runner → AgentStatusMonitor → CredentialService + AgentClient /status
  Scheduler/runner → UpdateMonitor → CredentialService + AgentClient /updates
  Scheduler/runner → SiteHealthMonitor → CredentialService + AgentClient /site-health
                  ↓              ↓
            Site/Credential DB   Agent REST API

Agent WordPress
  Application Password auth → od_monitor_read → /ping, /status, /updates, /site-health → Collectors
```

`HttpMonitor` は保存済み `Site` の公開HTTPS URLだけを共通のoutbound URL policyとWordPressの安全なHTTP APIで取得し、2xx、非成功status、timeout、接続失敗を共通の `CheckResult` に正規化します。response bodyは保持せず、redirectは最大3回まで接続先と解決後のIPを個別検証します。

`SslMonitor` は保存済み `Site` の公開HTTPS URLに対して、CA信頼チェーンとホスト名を検証したTLS接続を行い、証明書の有効期間を共通の `CheckResult` に正規化します。警告日数と失敗日数は生成時に設定でき、期限判定はWordPressのtimezoneに依存しないUTCの基準時刻で行います。

`AgentPingMonitor` は保存済みcredentialをリクエスト直前に復号して `/ping` を呼び、Agent API、認証、認可、schema互換性をまとめて確認します。成功、認証・権限エラー、timeout、通信エラー、不正応答は共通の `CheckResult` に正規化します。

`AgentStatusMonitor` は `/status` の検証済み応答を15分間隔の監視結果へ正規化し、site identityを除いたWordPress・PHP・Agentのversionと環境種別だけを保持します。

`UpdateMonitor` は `/updates` の検証済みsummaryを読み、更新がなければhealthy、1件以上あればwarningとして、WordPress本体・プラグイン・テーマ別の件数と対象種別だけを `CheckResult` に保持します。

`SiteHealthMonitor` は `/site-health` の検証済みsummaryを読み、critical・recommended・goodを監視状態へ正規化します。永続化するのは件数と代表テストの識別子・状態だけで、診断本文や完全なAgent応答は保持しません。

`packages/protocol` は通信契約の文書、schema、fixtureのみを保持します。ルートのComposerとPHPUnitはmonorepo全体の開発・検証用であり、各プラグインの実行時依存ではありません。

## Data flow

サイト登録では、入力検証、`/ping`、`/status`、UUID生成、credential暗号化、site保存、credential保存の順に処理します。接続確認に成功しない限り永続化しません。credential保存が失敗した場合は、直前に作成したsite行を削除します。

`Scheduler` はcheck typeごとに6つのWP-Cron event、単発のbatch continuationとretry event、日次cleanup eventを管理します。HTTPとAgent Pingは5分、Agent Statusは15分、UpdatesとSite Healthは60分、SSLは24時間です。eventとsystem cronからの直接起動は同じ `CheckRunner` を通り、enabled siteだけを処理します。

`CheckRunner` はsite UUIDとcheck typeの組み合わせで5分の期限付きlockを取得します。並行実行はskipし、処理終了時は所有tokenが一致するlockだけを解除します。異常終了はsecretを含まないunknown resultへ正規化し、他siteの処理を継続します。

`BatchScheduler` はcheck typeごとの世代付きkeyset cursorと期限付きlockを管理し、有効なサイトをデフォルト20件ずつsite ID順で処理します。カーソルは各サイトの処理後に進み、残件は単発WP-Cron eventへ引き継ぎます。中断時は保存済みカーソルから再開し、古い世代のeventは無効化します。実行直前にsite IDとUUIDを再確認するため、処理中の追加・削除・無効化でも対象の取り違えを防ぎます。

`RetryScheduler` はtimeout、接続失敗、一時的なHTTP statusだけを60秒・300秒の間隔で最大3回まで実行します。再試行待ちはsite ID、site UUID、check type、試行回数だけを単発WP-Cron eventへ保存します。中間失敗は永続化せず、通常scheduleは保留中の同一チェックをskipし、retryも共通lockを必ず経由します。成功、対象外エラー、上限到達の結果だけが履歴・状態遷移・通知へ渡ります。

日次cleanupは1回の処理件数を制限し、90日を超えたcheck履歴、期限切れexecution lock、期限切れのプラグイン固有transientを段階的に削除します。eventsとsite statusは保持し、現在有効なlockや実行中に更新されたoptionは観測済みの値との一致確認で保護します。

Monitorのデータベースマイグレーションは、有効化時だけでなく通常のWordPress起動時にも保存済みスキーマバージョンを比較します。期限付きlockで同時実行を直列化し、冪等なテーブル定義を適用した後に必須テーブル・カラム・インデックスを検証し、すべて成功した場合だけバージョンoptionを更新します。失敗中はrepositoryとschedulerを組み立てず、旧バージョンと秘密情報を含まない状態コードを保持して次回起動で再試行します。大量データ変換はこの同期経路へ追加せず、上限付きのバックグラウンド処理へ分離します。

## Release verification boundaries

通常のCIはWordPress 6.8／PHP 8.1と現行WordPress／PHP 8.3の両方で同じ統合テストを実行します。登録、Agent通信、6種類の監視、状態評価、履歴、イベント、通知、管理画面表示を1つのシナリオで接続し、個別unit testだけでは検出できないcomposition上の不整合を確認します。

性能確認は100サイト・1万件のcheck履歴をMVP検証プロファイルとし、20件単位のkeyset batch走査、直近履歴取得、Dashboard集計、300件単位のcleanupを実DB上で実行します。このプロファイルは再現可能な回帰検出用であり、ホスティング環境ごとの最大収容数やSLAを保証するものではありません。条件と最新の判定は[リリース判定](release-readiness.md)に記録します。
