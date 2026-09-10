# OD WordPress Monitor Protocol

Monitor Plugin と Agent Plugin の通信契約を、実行時共有ライブラリではなく文書・JSON Schema・fixtureとして管理します。

- `schemas/ping.schema.json`: `/ping` 成功応答
- `schemas/status.schema.json`: `/status` 成功応答
- `schemas/updates.schema.json`: `/updates` 成功応答
- `schemas/site-health.schema.json`: `/site-health` 成功応答
- `fixtures/`: schemaに対応する固定サンプル

互換性方針とエラーの扱いは [`docs/protocol.md`](../../docs/protocol.md) を参照してください。両プラグインはこのディレクトリのPHPコードを実行時に読み込みません。
