# MVPリリース判定

## 判定

2026年9月11日時点のMVPリリース判定は **GO** です。本書の判定はソース、通信schema、テスト用WordPress、ローカルDocker／MySQLを対象とした技術的なrelease gateであり、個別ホスティング環境のSLAを保証するものではありません。

## 対応環境と互換性matrix

MonitorとAgentが公開する必要環境は、WordPress 6.8以上、PHP 8.1以上です。CIとrelease workflowでは、境界を代表する次の組み合わせで同じPHPCS・PHPUnitを実行します。

| WordPress | PHP | 目的 | 判定 |
| --- | --- | --- | --- |
| 6.8 | 8.1 | 最小対応WordPress／PHP | 合格 |
| 7.1（2026年9月11日の現行安定版） | 8.3.33 | 現行環境と将来互換性の早期検出 | 合格 |

ComposerはPHP 8.1をplatformとして依存解決し、プラグイン本体はPHP 8.1で解釈可能な構文に限定します。現行安定版の正確なWordPress patch versionとPHP versionはCIの「Record environment versions」stepへ毎回出力します。

## 統合シナリオ

`MvpWorkflowIntegrationTest` は次の一連の経路を実際のcompositionで検証します。外部通信とTLS証明書だけを決定的なtest doubleへ置き換えます。

1. 公開HTTPS URL、Agent username、Application Passwordでサイトを登録する。
2. `/ping` と `/status` を検証し、サイトと暗号化済みcredentialを保存する。
3. HTTP、Agent Ping、Agent Status、Updates、Site Health、SSLの6種類を実行する。
4. 結果をcheck履歴と現在状態へ保存し、意味のある遷移をeventへ変換する。
5. HTTP障害を発生させ、重複のない障害通知と通知結果を記録する。
6. Sitesとサイト詳細画面で、異常状態、直近event、直近checkが表示されることを確認する。

通信responseは共有fixtureとruntime validatorを通過します。security boundaryは[Security](security.md)とIssue #29をauthorityとし、このgateでもcredentialがDB平文、履歴、通知、HTMLへ現れないことを確認します。

## 性能プロファイル

再現可能なMVP負荷条件は次のとおりです。

- 有効サイト：100件
- check履歴：合計10,000件（各サイト100件）
- 定期処理batch：20サイト
- 詳細画面用の直近履歴取得：20件
- cleanup：1回300件
- 測定対象：全サイトのkeyset走査、直近履歴取得、Dashboard集計、期限切れ履歴cleanup
- 合格条件：測定部分10秒未満、追加peak memory 64 MiB未満、欠落・重複・処理上限超過なし

`MvpScaleIntegrationTest` は実際のWordPress DB tableとindexを使用し、各互換性環境で測定値をtest outputへ記録します。fixture投入時間は製品のrequest pathではないため測定から除外します。この条件は回帰検出用の検証プロファイルであり、100サイトを製品上限と定義せず、100サイト超や10,000件超を無条件に保証もしません。実運用ではremote endpointの応答時間、PHP worker、DB性能、WP-Cron実行頻度をDashboardとrunbookで監視します。

2026年9月11日のローカルDocker／MySQL測定では、WordPress 6.8／PHP 8.1.34とWordPress 7.1／PHP 8.3.33の両方で測定部分0.004秒、追加peak memory 0.0 MiB（PHPの測定粒度未満）でした。両環境とも344 tests、1,518 assertionsが成功しています。

## Release artifact smoke test

MonitorとAgentの両方をproduction dependencyだけでZIP化し、次を自動確認します。

- plugin slugを唯一のtop-level directoryとする
- main plugin fileと`vendor/autoload.php`を含む
- header version、version constant、release versionが一致する
- tests、PHPUnit、PHPCS、`vendor/bin`、Git metadataを含まない
- archive内の全PHP fileがsyntax checkを通る
- SHA-256 sidecarとarchiveのdigestが一致する

tag release workflowでも公開前に同じsmoke testを実行します。

## 文書整合

- [README](../README.md)：利用者向け機能、必要環境、導入、画面、定期監視
- [Architecture](architecture.md)：component、data flow、batch／retry／migration、検証境界
- [Protocol](protocol.md)：4つのAgent endpoint、schema、error分類
- [Runbook](runbook.md)：WP-Cron、retry、batch、cleanup、migration、復旧
- [Security](security.md)：認証、credential、SSRF、secret-safe observability
- [Release workflow](releases.md)：独立package、version、artifact、rollback
- [Backup and restore](backup-and-restore.md)：保持data、salt、復元順序

## 既知の制約

- WordPress標準WP-Cronはアクセスがなければ遅延する。必要に応じてsystem cronを設定する。
- private network、自己署名証明書、HTTP endpointは監視対象にできない。
- メール到達性はMonitorサイトの`wp_mail()`設定と外部mail transportに依存する。
- サイトの編集・削除を行う管理画面はない。
- Site Healthは固定allowlistとsoft execution budgetを使用し、すべてのCore診断を返すものではない。
- multisite network全体の一括登録・migrationは提供せず、各サイト単位で動作する。
- 性能プロファイルを超える規模では、実環境でbatch size、remote latency、DB容量を別途検証する。

## Release checklist

- [x] WordPress 6.8／PHP 8.1で全テスト合格
- [x] 現行WordPress／PHP 8.3で全テスト合格
- [x] MVP統合シナリオ合格
- [x] 100サイト／1万履歴の性能プロファイル合格
- [x] Monitor／Agent release artifact smoke test合格
- [x] PHPCS合格
- [x] security gate（Issue #29）合格
- [x] README・architecture・protocol・operations・release文書の整合確認
