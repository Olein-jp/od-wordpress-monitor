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
- `REDIRECT_LIMIT`
- `UNSAFE_REDIRECT`
- `CONNECTION_ERROR`
- `TIMEOUT`
- `AGENT_NOT_FOUND`
- `AUTHENTICATION_FAILED`
- `PERMISSION_DENIED`
- `CREDENTIAL_NOT_FOUND`
- `CREDENTIAL_DECRYPTION_FAILED`
- `INVALID_JSON`
- `INVALID_RESPONSE`
- `UNSUPPORTED_SCHEMA`
- `AGENT_ERROR`

応答本文やログへAuthorization header、Application Password、cookieを含めません。

`AgentPingMonitor` は `/ping` の結果を `agent_ping` 種別の `CheckResult` に変換します。成功時のmetadataはendpoint、schema version、Agent versionだけです。失敗時は上記の正規化済みerror codeだけを使用し、Agentのrawエラー、username、Application Password、Authorization headerは保持しません。

`AgentStatusMonitor` は `/status` の結果を `agent_status` 種別の `CheckResult` に変換します。成功時はWordPress、PHP、Agentのversion、multisite、環境種別だけを現在状態のmetadataへ保持し、チェック履歴には複製しません。site identityや完全なAgent応答は保存しません。通信、認証、credential、schema検証の失敗は上記の固定error codeと固定messageへ正規化します。

`UpdateMonitor` は `/updates` の結果を `updates` 種別の `CheckResult` に変換します。更新がない場合はhealthy、1件以上ある場合はwarningです。チェック履歴とイベントのmetadataには合計・種別別の更新件数だけを含めます。現在状態には、サイト詳細表示に必要な有効テーマと有効プラグインの名称・識別子・現在version・最新版・更新有無・取得日時を、上限付きの正規化済みスナップショットとして保持します。通信、認証、schema検証に失敗した場合は直前の正常なスナップショットを維持し、チェック自体はcriticalとして上記の正規化済みerror codeを使用します。完全なAgent応答は保存しません。

`SiteHealthMonitor` は `/site-health` を60分ごとに取得し、criticalが1件以上ならcritical、criticalがなくrecommendedが1件以上ならwarning、両方なければhealthyとして履歴と現在状態へ保存します。永続化するmetadataは件数と代表testのid/statusだけです。criticalへの遷移は`SITE_HEALTH_CRITICAL`、criticalからwarningへの部分回復は`SITE_HEALTH_PARTIALLY_RECOVERED`、criticalからhealthyへの完全回復は`SITE_HEALTH_RECOVERED`として記録します。部分回復とrecommendedのみの初回状態では通知を生成しません。

## Compatibility policy

Monitor Phase 1が受け入れるschema versionは `1.0` のみです。互換性を壊す変更は新しいschema versionとAPI namespaceで提供し、実装、文書、JSON Schema、fixtureを同時に更新します。未知のschema versionは `UNSUPPORTED_SCHEMA` として拒否します。
