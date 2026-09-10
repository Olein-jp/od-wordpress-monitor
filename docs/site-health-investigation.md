# Site Health API 安全利用調査

## 目的と結論

この文書は、Agent の `/site-health` を実装する前提として、WordPress 標準の Site Health API を安全に利用する境界を定めるものです。調査対象は、このプロジェクトがサポートする最小バージョン WordPress 6.8.0 と、2026-09-10 時点の最新安定版 WordPress 7.1.0 です。

初期実装では、`WP_Site_Health` の公開APIだけを利用し、13件の固定allowlistに含まれる同期テストだけを実行します。外部通信、loopback、ファイルシステム確認、大量データ走査を伴うテストは実行しません。WordPress Core の private method、Site Health 管理画面のHTML・JavaScript、Coreコードのコピーには依存しません。

## 根拠と利用する公開API

採用するAPIは次のとおりです。

- `WP_Site_Health::get_instance()`：公開singleton取得API。WordPress 5.4.0から存在する。
- `WP_Site_Health::get_tests()`：`direct` と `async` のテスト定義を返す公開static API。WordPress 5.2.0から存在する。
- `WP_Site_Health::get_test_*()`：各診断結果を返す公開メソッド。初期実装では、後述する固定対応表のメソッドだけを呼び出す。
- `site_status_tests`：テスト定義を追加・削除できる公開filter。`get_tests()`を通じて反映されるため、削除されたテストは実行しない。

`WP_Site_Health::perform_test()` はprivate methodなので使用しません。WordPress Coreの `wp-site-health/v1` REST controllerも公開されていますが、公開されているのは主に非同期テストごとのendpointであり、安全なテストを集約する契約ではありません。既存のAgent認証、専用capability、schemaを維持するため、Core REST endpointの代理呼び出しにも使用しません。

RESTリクエスト時は通常、WordPress Core自身が `WP_Site_Health` を読み込んでsingletonを作成しています。防御的に `class_exists( 'WP_Site_Health' )` を確認し、未読込の場合だけCoreと同じ `ABSPATH . 'wp-admin/includes/class-wp-site-health.php'` を `require_once` します。読込後もclassが存在しなければ、診断を推測せず「利用不可」のエラーにします。

## 6.8.0と7.1.0の互換性

両バージョンで、`get_tests()` の戻り値は `direct` と `async` の連想配列であり、テスト識別子、表示label、実行対象を持つ構造です。Coreの説明でも、時間を要するテストは管理画面の読み込みを遅延させないため `async` に置くことが推奨されています。

| 確認項目 | WordPress 6.8.0 | WordPress 7.1.0 | 判断 |
| --- | --- | --- | --- |
| `WP_Site_Health::get_instance()` | 利用可 | 利用可 | 採用 |
| `WP_Site_Health::get_tests()` | 利用可 | 利用可 | 採用 |
| `direct` / `async` 構造 | 同一 | 同一 | 構造確認に利用 |
| Core Site Health REST routes | 6種類の非同期診断 | 同じ6種類 | 初期実装では不採用 |
| 初期allowlistの13テスト | すべて存在 | すべて存在 | 採用 |
| 新しい直接テスト | なし | `search_engine_visibility`、`insecure_registration`、`opcode_cache` | 6.8非対応のため初期allowlist外 |

WordPress 7.1.0にある追加テストのうち、`search_engine_visibility` は6.9.0、`insecure_registration` と `opcode_cache` は7.0.0で導入されています。将来のCore追加を自動採用すると、最小バージョンとの応答差と実行コストを事前評価できなくなるため、自動採用はしません。

## 実行特性の分類

「Coreがdirectに分類していること」と「監視APIから無条件に実行して安全であること」は同義ではありません。たとえば `php_version` はdirectですが、キャッシュがなければ WordPress.org Serve Happy APIへHTTP requestを送ります。このため、Coreソースで実際の処理を確認して次のように分類します。複数の性質を持つテストは、より慎重な分類を優先します。

| 分類 | 代表例 | 実行特性 | 初期実装 |
| --- | --- | --- | --- |
| 安全な同期処理 | `php_extensions`、`php_default_timezone`、`php_sessions`、`sql_server`、`ssl_support`、`scheduled_events`、`http_requests`、`debug_enabled`、`file_uploads` | PHP設定、DB version、cron配列、定数・filterなどを同一request内で確認する | allowlist対象 |
| 外部request | `dotorg_communication`、`php_version` | WordPress.orgへHTTP requestを送る。`php_version` はsite transientがない場合だけ送信する | 除外 |
| loopback | `loopback_requests`、`rest_availability`、`authorization_header`、`page_cache` | 自サイトの管理画面、REST API、またはfront pageへHTTP requestを送る | 除外 |
| 高負荷になり得る処理 | `background_updates`、`update_temp_backup_writable`、`available_updates_disk_space`、`autoloaded_options`、`persistent_object_cache`、`page_cache` | updater・filesystem初期化、空き容量確認、autoload data走査、DB規模判定、front pageへの3回requestを行い得る | 除外 |

`wordpress_version`、`plugin_version`、`theme_version` は更新用site transientとインストール済み項目を読み取る同期処理であり、自身では更新APIを呼びません。多数のplugin/themeがあるサイトでは件数に比例しますが、Coreの通常のdirect診断範囲であり、既存のAgent `/updates` と同等の情報源なので初期allowlistに含めます。

## 初期allowlist

次の13件だけを、表にある公開メソッドへ固定対応させて実行します。

| responseの`id` | Core定義の`test` | 呼び出す公開メソッド |
| --- | --- | --- |
| `wordpress_version` | `wordpress_version` | `get_test_wordpress_version()` |
| `plugin_version` | `plugin_version` | `get_test_plugin_version()` |
| `theme_version` | `theme_version` | `get_test_theme_version()` |
| `php_extensions` | `php_extensions` | `get_test_php_extensions()` |
| `php_default_timezone` | `php_default_timezone` | `get_test_php_default_timezone()` |
| `php_sessions` | `php_sessions` | `get_test_php_sessions()` |
| `sql_server` | `sql_server` | `get_test_sql_server()` |
| `ssl_support` | `ssl_support` | `get_test_ssl_support()` |
| `scheduled_events` | `scheduled_events` | `get_test_scheduled_events()` |
| `http_requests` | `http_requests` | `get_test_http_requests()` |
| `debug_enabled` | `is_in_debug_mode` | `get_test_is_in_debug_mode()` |
| `file_uploads` | `file_uploads` | `get_test_file_uploads()` |
| `plugin_theme_auto_updates` | `plugin_theme_auto_updates` | `get_test_plugin_theme_auto_updates()` |

実行前に、`get_tests()['direct']` に同じ`id`があり、Core定義の`test`が固定対応表と完全一致し、対象メソッドがcallableであることを確認します。filterでcallbackへ差し替えられた定義、allowlist外の追加テスト、`async`に移動したテストは呼び出しません。これにより、第三者が登録した任意callbackや、未評価の将来テストをAgentが実行することを防ぎます。

## 明示的な除外

| テスト | 除外理由 |
| --- | --- |
| `php_version` | Serve Happyのsite transientがない場合に外部HTTP requestを行う |
| `rest_availability` | 自サイトREST APIへのloopback requestを行う |
| `dotorg_communication` | WordPress.orgへ最大10秒の外部requestを行う |
| `background_updates` | updaterとfilesystemの状態確認を行い、実行時間を予測しづらい |
| `loopback_requests` | 自サイトへのHTTP POSTを行う |
| `https_status` | Coreでasyncに分類され、HTTPS検出結果が別のHTTP検出処理に依存する |
| `authorization_header` | 特別なBasic認証headerを使うCore RESTの往復を前提とする |
| `page_cache` | front pageへ3回requestし、response timeとheaderを測定する |
| `update_temp_backup_writable` | filesystem APIを初期化し、directoryの作成可能性を確認する |
| `available_updates_disk_space` | filesystemの空き容量確認が遅延する環境がある |
| `autoloaded_options` | autoload data全体を読み、件数とbyte数を走査する |
| `persistent_object_cache` | DB規模やautoload dataなど複数のperformance条件を調べる |
| `search_engine_visibility`、`insecure_registration`、`opcode_cache` | 6.8.0には存在せず、最小対応版で同じ契約を提供できない |
| Core RESTの`directory-sizes` | directory全体のsize算出は高負荷で、Site Health summaryの対象でもない |

除外テストは「無効」なのではなく、15秒以内の監視requestで無条件に実行する対象として不適切という判断です。将来必要になった場合は、明示的なopt-in、別schedule、個別cache、同時実行制御を備えた別Issueで再評価します。

## response正規化

AgentはCoreの生の診断結果を返しません。`description` と `actions` はHTML、管理画面URL、環境情報を含み得るため破棄し、通信契約は次の情報だけに限定します。

```json
{
  "schema_version": "1.0",
  "summary": {
    "critical": 1,
    "recommended": 2,
    "good": 10
  },
  "tests": [
    {
      "id": "php_extensions",
      "status": "good",
      "label": "必要な PHP モジュールはすべて利用できます"
    }
  ],
  "timestamp": "2026-09-10T03:00:00+00:00"
}
```

正規化規則は次のとおりです。

1. `id` はCore結果内の値ではなく、allowlistのkeyを使用する。
2. `status` は `good`、`recommended`、`critical` だけを受け入れる。
3. `label` は文字列だけを受け入れ、HTMLを除去し、plain textへsanitizeする。
4. 戻り値が配列でない、必須fieldがない、statusが未知、例外が発生した場合は、そのテストだけをresponseから除外して残りを継続する。
5. `summary` は正規化に成功した`tests`だけを数え、3つのcountの合計を`tests`件数と一致させる。
6. allowlist全件が失敗した場合は、正常そうに見える空summaryを返さず、endpointを利用不可エラーにする。
7. `timestamp` は収集完了時刻をUTCのRFC 3339形式で生成する。cache hit時も元の収集時刻を保持する。
8. test順はallowlist順に固定し、Coreやfilterの連想配列順に依存しない。

この契約では、未対応・削除・不正なテストを「good」に丸めません。個別失敗を除外しつつ全件失敗をエラーにすることで、false positiveとendpoint全体の不要な失敗を両方避けます。

## cache、timeout、実行頻度

- Agent側の正規化済みresponseをsite transientへ15分間cacheする。cache keyにはcontract versionを含め、仕様変更時に旧形式を再利用しない。
- cacheするのは正規化を完了したresponseだけとし、認証・認可error、全件失敗、途中の生データはcacheしない。
- Collectorは10秒のsoft budgetを持ち、各テスト開始前に超過を確認する。超過後は未実行テストを呼ばず、個別失敗と同様に除外する。PHP上で実行中の単一メソッドを安全に中断できないため、これはhard timeoutではない。
- Monitorの既存Agent HTTP timeout 15秒をtransport上限として維持する。外部requestとloopbackをallowlistから除くことで、この上限内で完了しやすくする。
- 後続のMonitor統合はIssue #14の要件どおり60分間隔とする。15分cacheは手動再取得や重複requestを吸収しつつ、定期監視ごとに新しい診断を取得できる。
- 同時cache missによる重複実行は低コストallowlistでは許容する。運用計測で問題が確認された場合だけ短時間lockを追加する。

## 互換性とfallback

- 最小対応版はplugin headerと同じWordPress 6.8。実装・CIの下限確認には6.8.0を使い、最新確認にはその時点の最新安定版を使う。
- test ID、label、診断基準はCore versionによって変わり得る。IDとmethodの固定対応、厳格な正規化、allowlist外の無視により、未知の追加を自動実行しない。
- plugin/themeは `site_status_tests` でCoreテストを削除・変更できる。削除または対応不一致の場合は実行せず、残りのテストを返す。
- labelはWordPress localeに従うため翻訳後の文字列である。Monitorの判定はlabelではなくstatus/countだけを使う。
- multisiteでも現在のsite contextで実行する。network全体の集約とはみなさない。
- `WP_Site_Health` classまたは全allowlistテストが利用できない場合は、空のhealthy responseへfallbackせず、安定したAgent errorへ変換する。
- Coreのprivate method、private property、管理画面DOM、localized JavaScript objectには依存しない。公開メソッドの廃止やsignature変更が確認された場合は、allowlistを更新するまで該当テストを除外する。

## 推奨実装計画

Issue #13では、次の変更単位を推奨します。

1. `plugins/agent/src/Collector/SiteHealthCollector.php`を追加し、class確認、allowlist照合、公開メソッド実行、正規化、summary集計、cacheを担当させる。
2. `plugins/agent/src/Rest/SiteHealthController.php`を追加し、既存の`RestController`と同じnamespace、schema version、UTC timestamp、`od_monitor_read` capabilityを使う認証済みGET `/site-health`を提供する。
3. `plugins/agent/src/Plugin.php`でcontrollerを登録する。
4. `packages/protocol/schemas/site-health.schema.json`と対応fixtureを追加し、`summary`、`tests`、`timestamp`をschema 1.0の加算的endpointとして定義する。
5. `plugins/monitor/src/Http/AgentClient.php`と`ResponseValidator.php`へsite-health契約を追加する。ただし状態評価、schedule、event生成はIssue #14に残す。
6. `docs/protocol.md`へendpoint、認証、response、errorを追記する。

## 検証方針

Issue #13の実装時は、次を自動テストします。

- unit test：allowlist外、async、定義差し替え、method欠落、未知status、不正label、例外、部分失敗、全件失敗の正規化。
- cache test：cache missでは一度だけ収集し、hitではCore methodを再実行せず、元のtimestampを保持する。
- negative HTTP test：`pre_http_request`で予期しないHTTP requestを失敗させ、allowlist実行中に外部requestとloopbackが一度も発生しないことを確認する。
- REST test：未認証、専用capabilityなし、許可済みGET、schema一致、UTC timestampを確認する。
- protocol test：有効fixtureと、summary count不一致・未知status・HTML labelを含む無効fixtureを確認する。
- compatibility test：WordPress 6.8.0と最新安定版の両方で13件のID、Core `test`値、公開methodのcallableを確認する。最新Coreで差分が出た場合は自動採用せず、調査failureとしてreviewする。
- regression test：Agent REST/integration、AgentClient、ResponseValidatorの関連test後に、ルートの`composer lint`と`composer test`を実行する。

## 公式資料

- [WordPress Core version check API](https://api.wordpress.org/core/version-check/1.7/)
- [`WP_Site_Health` class reference](https://developer.wordpress.org/reference/classes/wp_site_health/)
- [`WP_Site_Health::get_tests()` reference](https://developer.wordpress.org/reference/classes/wp_site_health/get_tests/)
- [`site_status_tests` hook reference](https://developer.wordpress.org/reference/hooks/site_status_tests/)
- [WordPress 6.8.0 `WP_Site_Health` source](https://github.com/WordPress/wordpress-develop/blob/6.8.0/src/wp-admin/includes/class-wp-site-health.php)
- [WordPress 7.1.0 `WP_Site_Health` source](https://github.com/WordPress/wordpress-develop/blob/7.1.0/src/wp-admin/includes/class-wp-site-health.php)
- [WordPress 6.8.0 Site Health REST controller](https://github.com/WordPress/wordpress-develop/blob/6.8.0/src/wp-includes/rest-api/endpoints/class-wp-rest-site-health-controller.php)
- [WordPress 7.1.0 Site Health REST controller](https://github.com/WordPress/wordpress-develop/blob/7.1.0/src/wp-includes/rest-api/endpoints/class-wp-rest-site-health-controller.php)
