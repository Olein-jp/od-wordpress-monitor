# OD WordPress Monitor

OD WordPress Monitorは、複数のWordPressサイトを1か所から監視するためのシステムです。

- **OD WordPress Monitor**：監視をまとめるWordPressサイトにインストールします。
- **OD Monitor Agent**：監視される各WordPressサイトにインストールします。

MonitorとAgentは、HTTPS上のWordPress REST APIとApplication Passwordを使って通信します。Agentが提供するのは読み取り専用の情報だけで、サイトの設定変更やアップデートの実行は行いません。

## 現在のバージョン

- OD WordPress Monitor：1.0.6
- OD Monitor Agent：1.0.2
- 通信スキーマ：1.0

## 現在できること

- 監視対象サイトの手動登録
- Agentへの接続・認証確認
- WordPress、PHP、Agentのバージョン確認
- サイトのHTTP応答確認
- Agent APIの稼働確認
- WordPress本体、プラグイン、テーマの更新有無の確認
- WordPress Site Healthの安全な診断結果の確認
- SSL証明書の検証と有効期限確認
- WP-Cronによる定期実行
- 定期監視結果と現在状態の保存
- 稼働停止、復旧、更新あり、SSL警告などの状態変化イベントの保存
- 90日を超えたチェック履歴の定期削除
- 障害・復旧時のメール通知
- GitHub Releases経由でのプラグインアップデート

定期監視の結果は履歴と現在状態として保存されます。管理画面のDashboardとSitesでは全体の状態を確認でき、各サイトの詳細画面では現在状態、直近20件のイベント、直近20件の監視結果を確認できます。メール通知はMonitor全体で一つの通知先を設定でき、障害・復旧の状態変化だけを対象にします。

また、登録済みサイトの編集・削除は管理画面からは行えません。

## 必要環境

Monitor側とAgent側の両方で、次の環境が必要です。

- WordPress 6.8以上
- PHP 8.1以上

Agent側のサイトには、次の条件も必要です。

- HTTPSで公開され、Monitor側から接続できること
- WordPress REST APIを利用できること
- WordPress Application Passwordsを利用できること

Monitor側では、認証情報の暗号化にPHPのlibsodium拡張と、Agentサイトへの外向きHTTPS通信が必要です。また、定期監視を行うため、WP-Cronが正常に実行される環境が必要です。

## 全体の導入手順

導入は次の順番で進めます。

1. 監視対象サイトへAgentをインストールする
2. Agent専用ユーザーとApplication Passwordを作成する
3. 監視をまとめるサイトへMonitorをインストールする
4. Monitorへ監視対象サイトを登録する
5. 接続確認を行う

## 1. Agentを監視対象サイトへインストールする

1. [OD Monitor Agentの最新リリース](https://github.com/Olein-jp/od-monitor-agent-release/releases/latest)から `od-monitor-agent.zip` をダウンロードします。
2. 監視対象サイトの管理画面で「プラグイン」→「新規プラグインを追加」→「プラグインのアップロード」を開きます。
3. `od-monitor-agent.zip` をアップロードしてインストールします。
4. 「OD Monitor Agent」を有効化します。

Agentに独自の設定画面はありません。有効化すると、読み取り専用の `OD Monitor Agent` ユーザー権限グループと、Monitorが利用するREST APIが追加されます。

## 2. Agent専用ユーザーを作成する

監視には、管理者アカウントを使わず、Agent専用ユーザーを作成してください。

1. 監視対象サイトの管理画面で「ユーザー」→「新規ユーザーを追加」を開きます。
2. 任意のユーザー名とメールアドレスを設定します。
3. 権限グループに「OD Monitor Agent」を選択してユーザーを作成します。
4. 作成したユーザーのプロフィール画面を開きます。
5. 「Application Passwords（アプリケーションパスワード）」で、名前に `OD WordPress Monitor` など識別しやすい名称を入力します。
6. 新しいApplication Passwordを追加し、表示されたパスワードを安全な場所へ一時的に控えます。

Application Passwordは作成直後に一度だけ表示されます。Monitorへの登録後は手元に控え続ける必要はありませんが、監視に使用しているApplication Password自体はWordPress上から削除しないでください。チャットやメールなどでの共有も避けてください。

Agent専用ユーザーに必要な権限は、ログインに必要な `read` とAgent API用の `od_monitor_read` だけです。

## 3. Monitorを監視用サイトへインストールする

1. [OD WordPress Monitorの最新リリース](https://github.com/Olein-jp/od-wordpress-monitor-release/releases/latest)から `od-wordpress-monitor.zip` をダウンロードします。
2. 監視をまとめるWordPressサイトの管理画面で「プラグイン」→「新規プラグインを追加」→「プラグインのアップロード」を開きます。
3. `od-wordpress-monitor.zip` をアップロードしてインストールします。
4. 「OD WordPress Monitor」を有効化します。

有効化すると、管理画面に「WordPress Monitor」メニューが追加され、保存用のデータベーステーブルと定期監視スケジュールが作成されます。

## 4. 監視対象サイトを登録する

Monitorをインストールしたサイトで、次の操作を行います。

1. 管理画面の「WordPress Monitor」→「Add Site」を開きます。
2. 次の項目を入力します。

| 項目 | 入力内容 |
| --- | --- |
| Site Name | Monitor上で識別するための任意の名前 |
| Site URL | 監視対象WordPressのHTTPS URL。`/wp-json/...` は付けません |
| Agent Username | Agent専用ユーザーのユーザー名 |
| Application Password | 前の手順で発行したApplication Password |

3. 「Connect and Add Site」を押します。

登録前に、MonitorはAgentの `/ping` と `/status` の両方へ接続し、認証、権限、応答形式を確認します。確認に成功した場合だけサイトと認証情報が保存されます。

Application Passwordはlibsodiumで暗号化して保存され、登録後の画面やHTMLには再表示されません。暗号鍵はWordPressの認証用saltから生成されるため、Monitorサイトを移行・復元するときは、データベースと元の `wp-config.php` のsaltを一緒に引き継いでください。

## 5. 登録後に接続を確認する

「WordPress Monitor」→「Sites」には、登録したサイトと、最後に手動接続確認した時点の次の情報が表示されます。

- 接続状態
- WordPressバージョン
- PHPバージョン
- Agentバージョン

「Test Connection」を押すと、保存済みの認証情報を使って `/ping` と `/status` を再確認し、表示内容を更新します。

## 定期監視

有効な登録サイトに対し、WP-Cronから次の監視が実行されます。

| 監視内容 | 間隔 |
| --- | ---: |
| HTTP応答 | 5分 |
| Agent Ping | 5分 |
| Agent Status | 15分 |
| WordPress・プラグイン・テーマの更新有無 | 60分 |
| WordPress Site Health | 60分 |
| SSL証明書 | 24時間 |

Site Healthは安全な同期テストだけを対象とし、criticalがある場合は異常、recommendedのみの場合は警告として判定します。recommendedのみでは通知せず、criticalへの変化と復旧を重複なく通知します。SSL証明書は信頼チェーンとホスト名を検証し、有効期限まで30日以内になると警告として判定します。同一サイト・同一監視種別の重複実行は、期限付きロックで防止されます。

WP-Cronは通常、サイトへのアクセスをきっかけに実行されます。各ジョブの最終実行結果と次回予定はDashboardで確認できます。Monitorサイトへのアクセスが少ない場合やWP-Cronを無効化する場合は、[Schedulerの運用手順](docs/runbook.md)に従ってsystem cronを設定してください。

## Agentが公開する情報

Agentは認証済みリクエストに対して、次の読み取り専用エンドポイントを提供します。

- `GET /wp-json/od-monitor-agent/v1/ping`
- `GET /wp-json/od-monitor-agent/v1/status`
- `GET /wp-json/od-monitor-agent/v1/updates`
- `GET /wp-json/od-monitor-agent/v1/site-health`

応答にはWordPress、PHP、Agentのバージョン、更新情報、安全なSite Health診断の集計などが含まれます。データベースのパスワード、WordPressのsalt、APIキー、ユーザー一覧、投稿、注文、フォーム送信、各プラグインの設定は返しません。

## プラグインの更新

MonitorとAgentは、それぞれ専用の公開GitHub Releasesから更新を確認します。新しいバージョンが公開されると、通常のWordPressプラグインと同様に管理画面の更新画面へ表示されます。

Monitorの更新後は、次の通常リクエストで保存済みデータベーススキーマのバージョンを確認し、必要な変更だけを自動適用します。すでに最新版なら変更処理は再実行しません。処理が完了するまでは監視機能を開始せず、失敗時は旧バージョン情報を維持したまま次のリクエストで安全に再試行します。管理者には管理画面上で停止状態と対象バージョンを通知します。

- [Monitorのリリース一覧](https://github.com/Olein-jp/od-wordpress-monitor-release/releases)
- [Agentのリリース一覧](https://github.com/Olein-jp/od-monitor-agent-release/releases)

## 接続できない場合

次の項目を確認してください。

- Site URLが `https://` で始まる公開URLになっているか
- 監視対象サイトでOD Monitor Agentが有効になっているか
- Agent Usernameにメールアドレスではなく正しいユーザー名を入力しているか
- Application Passwordを正しく入力しているか
- Agent専用ユーザーの権限グループが「OD Monitor Agent」になっているか
- `https://example.com/wp-json/od-monitor-agent/v1/ping` にMonitor側から到達できるか
- Basic認証の `Authorization` ヘッダーがWebサーバーやプロキシで削除されていないか
- セキュリティプラグインやファイアウォールがREST APIを遮断していないか

## アンインストール時の注意

誤操作によるデータ消失を防ぐため、プラグインを削除しても次のデータを自動削除しません。

- Monitorに登録したサイト、暗号化済み認証情報、監視状態、履歴、イベント、設定
- Agentが作成した権限グループとユーザーとの関連付け

Monitorを削除・再インストールする前や、DBを復元するときは、[Monitorのアンインストール・バックアップ・復元](docs/backup-and-restore.md)を確認してください。暗号化済み認証情報の復元には、DBだけでなく元のauth saltも必要です。

## 開発者向け情報

### リポジトリ構成

- `plugins/monitor`：Monitorプラグイン
- `plugins/agent`：Agentプラグイン
- `packages/protocol`：JSON Schema、fixture、通信契約資料
- `docs`：設計、通信仕様、セキュリティ、リリース手順
- `tests/integration`：monorepo統合テスト

両プラグインは独立して配布でき、実行時の相互依存はHTTPS REST APIの通信契約だけです。

### 開発環境の準備

開発にはComposer、Node.js/npm、Dockerが必要です。

```sh
composer install
npm install
npm run env:start
npm run env:test:start
```

開発用WordPressは通常 `http://localhost:8888` で起動します。

### 品質確認

```sh
composer lint
npm run test:php
```

WordPress 6.8で互換性を確認する場合は、専用環境を使用します。

```sh
npm run env:test:wp68:start
npm run test:php:wp68
npm run env:test:wp68:stop
```

CIでは、最小対応環境のWordPress 6.8／PHP 8.1と、現行WordPress／PHP 8.3の両方で全テストを実行します。100サイト・1万件の監視履歴を使うMVP負荷プロファイルと、Monitor／Agent配布ZIPのsmoke testも全体テストに含まれます。検証条件、結果、既知の制約は[MVPリリース判定](docs/release-readiness.md)を参照してください。

詳しい仕様は、次の資料を参照してください。

- [アーキテクチャ](docs/architecture.md)
- [通信プロトコル](docs/protocol.md)
- [セキュリティ](docs/security.md)
- [アンインストール・バックアップ・復元](docs/backup-and-restore.md)
- [Site Health API 安全利用調査](docs/site-health-investigation.md)
- [リリース手順](docs/releases.md)
- [MVPリリース判定](docs/release-readiness.md)
- [Monitor固有のREADME](plugins/monitor/README.md)
- [Agent固有のREADME](plugins/agent/README.md)
