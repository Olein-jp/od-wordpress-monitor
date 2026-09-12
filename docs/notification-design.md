# 複数チャネル通知の設計方針

Issue #38 の調査結果として、Email、Slack、Discord、Chatworkへの通知を段階的に実装する方針を定める。

この文書は実装前の設計判断を記録するものであり、現行バージョンの機能仕様ではない。

## 結論

最初の実装では、既存の障害・復旧判定を変えずに、同じ通知を複数チャネルへ配送できるようにする。通知ルール、通知内容、配送処理、秘密情報の保存を別の責務として扱い、1チャネルの失敗が他チャネルを妨げない構成にする。

warning通知、日次まとめ、継続障害のリマインド、Schedulerの外部監視は、複数チャネル配送の完成後に個別のIssueで追加する。これにより、既存メール通知との後方互換性を保ちながら段階的に検証できる。

## 現状と変更理由

現在の通知処理は次の流れになっている。

1. `CheckRunner` が一時的な通信失敗を最大3回まで再試行する
2. 最終結果だけを `CheckResultRecorder` が保存する
3. `StateTransition` が監視種別ごとの状態変化をイベントへ変換する
4. `NotificationRule` が `healthy|warning -> critical` を障害、`critical -> healthy` を復旧と判定する
5. `NotificationManager` が単一のメール送信先へ配送する
6. `EventRepository` がイベントへ送信成否を1件だけ記録する

この構成には次の制約がある。

- `NotificationSenderInterface` がメールアドレスを表す文字列を直接受け取るため、送信先の種類を増やしにくい
- `NotificationManager` が単一senderを前提としている
- 送信結果をチャネル別に記録できない
- 同じstatusのまま内容だけが変わった場合はイベントが作られない
- Schedulerのstaleは参照用の状態であり、通知イベントではない

再試行後にだけ結果を確定する仕組みと、状態変化時だけ通知する仕組みは誤通知防止に有効なため維持する。

## 通知条件と初期値

### 即時通知

| 事象 | 初期値 | 判定 |
| --- | --- | --- |
| HTTP接続失敗、timeout、非成功status | ON | 再試行対象は最大3回の試行終了後、`critical`への変化時 |
| SSL失効、未発効、検証失敗、取得失敗 | ON | `critical`への変化時 |
| Agent接続、認証、権限、応答形式の異常 | ON | `critical`への変化時 |
| Site Healthのcritical | ON | `critical`への変化時 |
| 上記の異常からの復旧 | ON | `critical -> healthy`への変化時 |
| 初回監視で検出したcritical | OFF | 現行の`unknown -> critical`抑止を維持 |

初回criticalを既定で抑止するのは、機能更新や再構築後に既存サイトから一斉通知される危険を避けるためである。新規サイトは登録時の接続確認を通過するため、通常の接続障害は次回以降の状態変化で検知できる。初回critical通知を将来選択可能にする場合は、移行時の一斉通知を防ぐ条件も同時に設計する。

### warningと情報通知

| 事象 | 初期値 | 推奨方式 |
| --- | --- | --- |
| SSL期限が30日以内 | ON | `healthy -> warning`時に1回即時通知 |
| 更新あり | OFF | 1日1回のまとめ通知 |
| Site Healthのrecommended | OFF | 1日1回のまとめ通知 |
| 継続中の同一障害 | OFF | 任意のリマインド。初期候補は24時間ごと |
| Site Healthの`critical -> warning` | OFF | 部分復旧として任意通知 |

SSL期限接近は対応期限が明確で、毎日通知する必要がないため状態変化時の1回通知とする。更新情報とrecommendedは緊急性が一定ではなく件数も多いため、即時通知ではなくまとめ通知を推奨する。

現状では`warning -> warning`の内容変化でイベントが生成されない。更新項目の追加やSite Healthの診断内容変更を検出する場合は、現在のmetadataを正規化して署名を作り、前回署名と異なる場合だけ「内容変更」として扱う。配列順や取得時刻を署名から除外し、更新対象の識別子・現在版・更新可能版、またはSite HealthテストID・statusだけを比較対象にする。これは状態遷移とは別のイベント生成規則として実装する。

### 重複とflappingへの対応

- 同じ状態が続く間は再通知しない現行動作を維持する
- 配送の重複防止単位は、`event_id + channel_id`とする
- 通知配送の失敗を監視障害の新しいイベントにはしない。再帰的な通知を避ける
- Agent PingとAgent Statusが同じagent statusを更新するため、交互に成功・失敗する場合のflappingを関連テストで確認する
- 継続障害リマインドを追加するまでは、配送失敗の自動再送だけを限定的に扱う

## 内部設計

### 責務の分離

次の構成を推奨する。クラス名は実装時に既存命名へ合わせて調整してよい。

```text
MonitoringEvent
  -> NotificationRule             通知種別と配送可否を判定
  -> NotificationMessageFactory  秘密情報を除いた共通メッセージを生成
  -> NotificationManager         有効なチャネルを列挙して独立配送
       -> EmailNotifier
       -> SlackNotifier
       -> DiscordNotifier
       -> ChatworkNotifier
  -> DeliveryResult[]             チャネル別の成否だけを記録
```

senderへ任意のrecipient文字列を渡す現在のinterfaceは廃止し、各senderが自分の設定型を受け取る、または設定済みチャネルを表す値オブジェクトを受け取る。`NotificationManager`はsenderの例外をチャネル単位で捕捉し、残りの配送を継続する。

共通メッセージには次だけを含める。

- 通知種別
- サイト名
- queryとfragmentとuserinfoを除いた公開URL
- イベント種別
- 直前と現在のstatus
- 検出時刻
- 安全なerror code
- sanitizeとredactを通したメッセージ
- Monitor内のサイト詳細画面へのURL

サービス固有senderは共通メッセージをpayloadへ変換するだけとし、監視ルールを持たない。Discordでは意図しないmentionを無効化し、各サービスの文字数上限に合わせて末尾を切り詰める。

### 配送先

- Slack: `https://hooks.slack.com/services/...` へのJSON POST
- Discord: `https://discord.com/api/webhooks/...` へのJSON POST。配送成否を確実に判定するため`wait=true`を使用
- Chatwork: 固定endpoint `https://api.chatwork.com/v2/rooms/{room_id}/messages` へのform POST。APIトークンは`x-chatworktoken` headerで送る
- Email: 現在の`wp_mail()`を維持

SlackとDiscordのWebhook URLには秘密情報が含まれる。Chatwork APIトークンはアカウント権限を持つため、いずれも平文のログ、イベント、HTML、URL queryへ出してはならない。

### HTTPと再試行

WordPress HTTP APIを使用し、timeout、redirect、response sizeを明示する。入力されたURLへ無条件にPOSTせず、HTTPS、host、port、path形式をサービス別に検証する。

| 応答 | 扱い |
| --- | --- |
| 2xx | 成功。必要な場合はresponse bodyも検証 |
| 400、401、403、404 | 設定または権限の恒久的失敗。自動再試行しない |
| 408、429 | 一時的失敗。`Retry-After`が安全な上限内なら尊重 |
| 5xx、timeout、接続失敗 | 一時的失敗。回数制限付きで再試行 |
| redirect | 原則失敗。秘密を別hostへ転送しない |

監視処理全体を配送待ちで長時間止めないよう、初期実装では短いtimeoutと最大1回の遅延再送を推奨する。再送ジョブへ秘密情報を引数として保存せず、`event_id`と`channel_id`から実行時に設定を再取得する。すでに成功した`event_id + channel_id`は再送しない。

## 設定と秘密情報

### 保存方針

既存の`odm_notification_settings`は変更せず、メールの`enabled`と`email`をそのまま読み続ける。これにより既存利用者の設定移行と一斉通知の危険を避ける。

新しいチャネル設定は別optionへ保存し、autoloadを無効にする。概念上は次の情報を持つ。

```text
channels
  slack
    enabled
    encrypted_webhook_url
  discord
    enabled
    encrypted_webhook_url
  chatwork
    enabled
    room_id
    encrypted_api_token
rules
  ssl_warning
  updates_digest
  site_health_recommended_digest
  ongoing_incident_reminder
```

秘密情報はlibsodiumのauthenticated encryptionで暗号化する。Application Passwordと同じ原理を再利用するが、鍵導出contextは通知専用に分離する。復号失敗は安全なエラーコードへ正規化し、ciphertextや例外本文を表示・記録しない。

空欄で保存された秘密入力は既存値を維持する。「置き換える」と「削除する」を明示的に分け、削除には確認可能な専用操作を用意する。保存済みの秘密は伏字を含めて値そのものをinputへ戻さず、「設定済み」とだけ表示する。

アンインストール時は現行方針に合わせて新optionも保持する。バックアップ・復元文書では、新optionと元環境のauth saltが必要であることを追加する。

### 管理画面

通知設定画面を次の順で構成する。

1. 通知ルール
2. Email
3. Slack
4. Discord
5. Chatwork

各チャネルには有効化、必要な接続情報、設定状態、保存、テスト送信を用意する。テスト送信は通常の設定保存と別actionにし、`manage_options`、専用nonce、POSTを必須とする。テスト結果は管理画面noticeへ出し、外部APIの生responseや秘密情報は表示しない。

初期実装はMonitor全体の設定だけを対象とする。サイト別設定は設定量と配送組み合わせを大きく増やすため対象外とし、必要性が確認できた場合に別途設計する。

すべてのlabel、description、noticeを翻訳可能にし、fieldsetとlegend、labelの関連付け、キーボード操作、エラーのテキスト表現を維持する。

## 送信結果の記録

イベントmetadataには秘密情報、送信先URL、メールアドレス、room ID、外部response bodyを保存しない。次のような最小情報だけを記録する。

```json
{
  "notification": {
    "status": "partial",
    "attempted_at": "2026-09-12T00:00:00Z",
    "channels": {
      "email": { "status": "sent", "attempts": 1 },
      "slack": { "status": "failed", "attempts": 1, "error_code": "HTTP_401" },
      "discord": { "status": "sent", "attempts": 1 }
    }
  }
}
```

集約statusは、全成功を`sent`、一部成功を`partial`、全失敗を`failed`とする。未設定または無効なチャネルは記録対象に含めない。既存の`notification.status`と`notification.timestamp`を読むコードがある場合に備え、移行期間は後方互換形式を維持するか、参照箇所を同じ変更内で更新する。

## Scheduler停止の扱い

`SchedulerHeartbeat`はWP-Cronジョブのstaleを判定できるが、WP-Cron自体が停止した場合はプラグイン内の通知処理も起動しない。そのため、プラグイン単体の通知をScheduler停止検知の主経路にはしない。

推奨順は次のとおり。

1. system cronからWordPress cronを定期起動し、そのsystem cronをホスティング側で監視する
2. 認証付きまたは推測困難なread-only heartbeat endpointを用意し、外部監視サービスから最終成功時刻を確認する
3. 管理画面や通常リクエスト時のstale検知は補助表示として維持する

外部heartbeat endpointは情報公開、認証、cache、rate limitを別途検討する必要があるため、複数チャネル通知の初期実装には含めない。

## 変更予定ファイル

### 既存ファイル

- `plugins/monitor/src/Notification/NotificationManager.php`
- `plugins/monitor/src/Notification/NotificationRule.php`
- `plugins/monitor/src/Notification/NotificationSettings.php`
- `plugins/monitor/src/Notification/NotificationSenderInterface.php`
- `plugins/monitor/src/Notification/EmailNotifier.php`
- `plugins/monitor/src/Admin/NotificationSettingsPage.php`
- `plugins/monitor/src/Evaluation/CheckResultRecorder.php`
- `plugins/monitor/src/Event/EventRepository.php`
- `plugins/monitor/src/Plugin.php`
- 対応する既存テスト、翻訳、README、バックアップ文書

### 追加候補

- 共通通知メッセージとfactory
- チャネル設定と秘密情報store
- 通知用encryptor
- Slack、Discord、Chatwork sender
- サービス別endpoint validator
- チャネル別配送結果
- テスト送信action
- 通知再送scheduler
- 各クラスのunit/integration test

DBスキーマ変更は初期実装では不要とする。optionと既存イベントmetadataで要件を満たせないことが実装時に判明した場合だけ、理由と移行方法を提示して再検討する。

## テストと検証

最低限、次を自動テストする。

- 既存メール設定と障害・復旧通知の後方互換性
- 複数チャネルの全成功、一部失敗、全失敗、sender例外
- 1チャネルの失敗後も残りのsenderが呼ばれること
- Webhook URL、room ID、enabled、rule設定のsanitize
- 設定済み秘密情報を空欄保存した場合の維持と、明示削除
- 暗号化、復号、改ざん、salt不一致、復号失敗時の秘密非露出
- Slack、Discord、Chatworkのpayload、header、成功status、恒久的失敗、一時的失敗
- host、scheme、port、path、redirectの拒否とSSRF防止
- Discord mention抑止、文字数上限、改行やheader様文字列のsanitize
- `event_id + channel_id`単位の重複防止と再送
- チャネル別配送結果に送信先やresponse bodyが含まれないこと
- 設定画面とテスト送信actionの権限、nonce、escape、i18n
- warning内容署名を追加する段階では、順序差の無視と実質変更の検出

まず関連テストを個別に実行し、通知managerやイベントmetadataのような共有処理を変更した段階でMonitor全体のPHPUnitとPHPCSを実行する。

```bash
composer test -- --filter 'Notification|CheckResultRecorder|EventRepository'
composer lint -- plugins/monitor/src/Notification plugins/monitor/src/Admin/NotificationSettingsPage.php
composer test
composer lint
```

外部サービスへの実送信は自動テストに含めず、WordPress HTTP APIのpreempt filterでrequestとresponseを模擬する。実アカウントを使う確認は、秘密をCIへ常設せず、リリース前の手動テストとして行う。

## 実装Issue案

実装は次の5件以内に分割する。

1. **通知配送コアを複数チャネル対応へ変更する**
   共通メッセージ、複数sender、チャネル別結果、既存Emailの互換性を実装する。通知条件は現行の障害・復旧だけを維持する。
2. **Slack・Discord通知と暗号化設定UIを追加する**
   endpoint制限、秘密保存、payload、テスト送信、失敗処理を実装する。
3. **Chatwork通知と暗号化設定UIを追加する**
   固定API endpoint、room ID、APIトークンheader、テスト送信、rate limit処理を実装する。
4. **warning通知と日次まとめ通知を追加する**
   SSL期限接近、更新情報、Site Health recommended、内容署名、通知時刻を実装する。
5. **通知再送と外部Scheduler監視を設計・実装する**
   配送失敗の限定再送を先に扱い、外部heartbeatはセキュリティ設計を承認してから別コミットで実装する。

各Issueは前段の公開interfaceと保存形式を前提にし、後続Issueの機能を先取りしない。

## 未決事項とリスク

- 初回criticalを通知可能にするか。既定はOFFとする
- 継続障害のリマインド間隔。候補は24時間だが運用実績から決める
- 日次まとめの送信時刻とタイムゾーン。WordPressサイトのtimezoneを候補とする
- Chatworkは個人APIトークン方式を初期対象とするか、OAuthを将来対象にするか。初期実装は個人APIトークン方式とする
- サイト別の通知先・通知ルールが必要か。初期実装は全体設定のみとする
- 大量サイトで同時障害が起きた場合のrate limitと通知集約
- Agent PingとAgent Statusが交互に状態を更新した場合のflapping
- auth salt変更後は通知用秘密も復号不能になるため、復元手順と再設定案内が必要

## 参考仕様

- [Slack Incoming Webhooks](https://api.slack.com/messaging/webhooks)
- [Slack rate limits](https://api.slack.com/apis/rate-limits)
- [Discord Webhooks](https://docs.discord.com/developers/platform/webhooks)
- [Discord Webhook Resource](https://docs.discord.com/developers/resources/webhook)
- [Chatwork API endpoint](https://developer.chatwork.com/docs/endpoints)
- [Chatwork メッセージ投稿API](https://developer.chatwork.com/reference/post-rooms-room_id-messages)
