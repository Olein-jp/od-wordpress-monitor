# Protocol

## Version and namespace

- API namespace: `od-monitor-agent/v1`
- schema version: `1.0`
- transport: HTTPSのみ
- authentication: WordPress Application PasswordsによるBasic Authentication
- authorization: `od_monitor_read` capability

## Endpoints

### `GET /wp-json/od-monitor-agent/v1/ping`

Agent、REST API、認証、認可、schema互換性を確認します。成功時はHTTP 200で `schema_version`、`success`、Agent slug/version、UTCのISO 8601 timestampを返します。

### `GET /wp-json/od-monitor-agent/v1/status`

成功時はHTTP 200で、site URL、home URL、site name、WordPress version、multisite、environment type、PHP version、Agent version、UTC timestampを返します。credential、ユーザー一覧、設定、コンテンツ、DB情報は返しません。

### `GET /wp-json/od-monitor-agent/v1/updates`

成功時はHTTP 200で、WordPress本体、インストール済みプラグイン、インストール済みテーマの現在版、最新版、更新有無を返します。プラグインとテーマには識別子、表示名、active状態も含み、`summary` はWordPress本体・プラグイン・テーマ別および合計の更新件数を示します。

更新情報はWordPress Coreが保持する `update_core`、`update_plugins`、`update_themes` site transientを使用します。このリクエスト自体は外部への更新確認を開始しません。transientが未作成、空、古い、または不完全な場合、確認できない更新は現在版を最新版として安全に正規化します。

### `GET /wp-json/od-monitor-agent/v1/site-health`

成功時はHTTP 200で、WordPress Site Healthの安全な同期テストだけを実行し、`summary`、`tests`、UTC timestampを返します。`summary` は `critical`、`recommended`、`good` の件数、各testは `id`、`status`、plain textの`label`だけを含みます。

Agentは固定allowlistとWordPress Coreの公開メソッドを照合し、外部通信、loopback、高負荷になり得るテスト、第三者が差し替えたcallbackを実行しません。Coreの生の`description`、`actions`、管理画面URL、環境詳細は返しません。正規化済み結果は15分間cacheされ、個別テストの不正値や例外はその項目だけを除外します。全テストが利用できない場合はHTTP 503を返します。採用・除外の根拠は [Site Health API 安全利用調査](site-health-investigation.md) を参照してください。

正式な成功応答は `packages/protocol/schemas`、例は `packages/protocol/fixtures` にあります。

## Error behavior

WordPressのApplication Password認証失敗は401、capability不足は403、ルートまたはAgent不在は404としてMonitorが分類します。Monitor側の正規化コードは次のとおりです。

- `INVALID_URL`
- `HTTPS_REQUIRED`
- `CONNECTION_ERROR`
- `TIMEOUT`
- `AGENT_NOT_FOUND`
- `AUTHENTICATION_FAILED`
- `PERMISSION_DENIED`
- `INVALID_JSON`
- `INVALID_RESPONSE`
- `UNSUPPORTED_SCHEMA`

応答本文やログへAuthorization header、Application Password、cookieを含めません。

`AgentPingMonitor` は `/ping` の結果を `agent_ping` 種別の `CheckResult` に変換します。成功時のmetadataはendpoint、schema version、Agent versionだけです。失敗時は上記の正規化済みerror codeだけを使用し、Agentのrawエラー、username、Application Password、Authorization headerは保持しません。

`UpdateMonitor` は `/updates` の結果を `updates` 種別の `CheckResult` に変換します。更新がない場合はhealthy、1件以上ある場合はwarningです。metadataにはendpoint、schema version、合計・種別別の更新件数、更新対象種別だけを含め、個別項目や完全なAgent応答は保持しません。通信、認証、schema検証の失敗はcriticalとし、上記の正規化済みerror codeを使用します。

Monitorの`AgentClient`は `/site-health` を取得して通信契約を検証できます。状態評価、履歴保存、schedule、event生成は後続Issueで追加します。

## Compatibility policy

Monitor Phase 1が受け入れるschema versionは `1.0` のみです。互換性を壊す変更は新しいschema versionとAPI namespaceで提供し、実装、文書、JSON Schema、fixtureを同時に更新します。未知のschema versionは `UNSUPPORTED_SCHEMA` として拒否します。
