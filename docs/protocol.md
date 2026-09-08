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

## Compatibility policy

Monitor Phase 1が受け入れるschema versionは `1.0` のみです。互換性を壊す変更は新しいschema versionとAPI namespaceで提供し、実装、文書、JSON Schema、fixtureを同時に更新します。未知のschema versionは `UNSUPPORTED_SCHEMA` として拒否します。
