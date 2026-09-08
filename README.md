# OD WordPress Monitor

WordPressサイトに配置する読み取り専用Agentと、サイトを登録・接続確認するMonitorからなる外部監視システムです。Phase 1は基本通信、手動登録、認証情報保護、手動接続確認に限定しています。

## Repository layout

- `plugins/monitor`: Monitor Plugin
- `plugins/agent`: Agent Plugin
- `packages/protocol`: JSON Schema、fixture、通信契約資料
- `docs`: architecture、protocol、security
- `tests/integration`: monorepo統合テスト

両プラグインは独立して配布でき、相互依存はHTTPS REST APIの通信契約だけです。

## Requirements

- PHP 8.1以上
- WordPress 6.8以上
- Composer
- Node.js / npm
- Docker
- PHP libsodium extension

## Development setup

```sh
composer install
npm install
npm run env:start
npm run env:test:start
```

`composer install` はルートの検証ツールに加え、各プラグインの独立autoloadも生成します。開発WordPressは通常 `http://localhost:8888` で起動します。

## Quality commands

```sh
composer lint
npm run test:php
```

`npm run test:php` は `.wp-env.test.json` で分離したWordPress PHPUnit環境内でAgent、Monitor、repository、REST APIのテストを実行します。ビルド対象のJavaScript/CSSはPhase 1にないため、フロントエンドbuild commandはありません。

各プラグインの導入手順は [`plugins/monitor/README.md`](plugins/monitor/README.md) と [`plugins/agent/README.md`](plugins/agent/README.md) を参照してください。独立した配布ZIPとGitHub Releaseの運用方法は [`docs/releases.md`](docs/releases.md) にまとめています。
