# Architecture

## Components

`plugins/monitor` と `plugins/agent` は、それぞれ独立したComposer autoloadとbootstrapを持つ、単独配布可能なWordPressプラグインです。実行時に互いのPHPコードや `packages/protocol` を読み込みません。

```text
Monitor WordPress
  Admin UI → SiteService → AgentClient → HTTPS + Application Password
                  ↓              ↓
            Site/Credential DB   Agent REST API

Agent WordPress
  Application Password auth → od_monitor_read → /ping, /status → Collectors
```

`packages/protocol` は通信契約の文書、schema、fixtureのみを保持します。ルートのComposerとPHPUnitはmonorepo全体の開発・検証用であり、各プラグインの実行時依存ではありません。

## Data flow

サイト登録では、入力検証、`/ping`、`/status`、UUID生成、credential暗号化、site保存、credential保存の順に処理します。接続確認に成功しない限り永続化しません。credential保存が失敗した場合は、直前に作成したsite行を削除します。

Phase 1は手動登録と手動接続確認だけを提供します。スケジューラー、履歴、通知、更新操作は含みません。
