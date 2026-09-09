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
                  ↓              ↓
            Site/Credential DB   Agent REST API

Agent WordPress
  Application Password auth → od_monitor_read → /ping, /status → Collectors
```

`HttpMonitor` は保存済み `Site` の公開HTTP URLだけをWordPressの安全なHTTP APIで取得し、2xx、非成功status、timeout、接続失敗を共通の `CheckResult` に正規化します。response bodyは保持せず、redirectは最大3回まで接続先を個別検証します。

`SslMonitor` は保存済み `Site` の公開HTTPS URLに対して、CA信頼チェーンとホスト名を検証したTLS接続を行い、証明書の有効期間を共通の `CheckResult` に正規化します。警告日数と失敗日数は生成時に設定でき、期限判定はWordPressのtimezoneに依存しないUTCの基準時刻で行います。

`AgentPingMonitor` は保存済みcredentialをリクエスト直前に復号して `/ping` を呼び、Agent API、認証、認可、schema互換性をまとめて確認します。成功、認証・権限エラー、timeout、通信エラー、不正応答は共通の `CheckResult` に正規化します。

`AgentStatusMonitor` は `/status` の検証済み応答を15分間隔の監視結果へ正規化し、site identityを除いたWordPress・PHP・Agentのversionと環境種別だけを保持します。

`UpdateMonitor` は `/updates` の検証済みsummaryを読み、更新がなければhealthy、1件以上あればwarningとして、WordPress本体・プラグイン・テーマ別の件数と対象種別だけを `CheckResult` に保持します。

`packages/protocol` は通信契約の文書、schema、fixtureのみを保持します。ルートのComposerとPHPUnitはmonorepo全体の開発・検証用であり、各プラグインの実行時依存ではありません。

## Data flow

サイト登録では、入力検証、`/ping`、`/status`、UUID生成、credential暗号化、site保存、credential保存の順に処理します。接続確認に成功しない限り永続化しません。credential保存が失敗した場合は、直前に作成したsite行を削除します。

`Scheduler` はcheck typeごとに5つのWP-Cron eventだけを管理します。HTTPとAgent Pingは5分、Agent Statusは15分、Updatesは60分、SSLは24時間です。eventとsystem cronからの直接起動は同じ `CheckRunner::run()` を通り、enabled siteだけを処理します。実行結果は呼び出し元へ返し、`odm_check_result` actionにも渡すため、Phase 3の履歴保存を後付けできます。

`CheckRunner` はsite UUIDとcheck typeの組み合わせで5分の期限付きlockを取得します。並行実行はskipし、処理終了時は所有tokenが一致するlockだけを解除します。異常終了はsecretを含まないunknown resultへ正規化し、他siteの処理を継続します。

Phase 1は手動登録と手動接続確認だけを提供します。スケジューラー、履歴、通知、更新操作は含みません。
