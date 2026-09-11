# Security

## Threat model

Phase 1では、Monitor DBの読み取り、ネットワーク盗聴、Agent credentialの過剰権限、偽Agent応答、管理画面CSRF、応答改ざんを主な脅威として扱います。MonitorまたはWordPress saltsとDBの両方を取得された場合は、credential復号が可能になる点は残存リスクです。

## Credential storage

Application Passwordは平文保存しません。`CredentialEncryptor` はlibsodium `secretbox` を使用し、暗号化ごとにランダムnonceを生成します。ciphertextには認証タグが含まれ、改ざん時は復号に失敗します。暗号鍵はDBへ保存せず、WordPressのauth saltからHKDF-SHA-256で導出します。

バックアップ移行時は、Monitor DBだけでなく元のWordPress saltsも安全に引き継ぐ必要があります。saltを変更すると既存credentialは復号できなくなります。

DBバックアップには暗号化済みApplication Passwordに加え、サイトURL、監視履歴、通知先メールアドレスが含まれます。DBとauth saltの両方を取得されるとcredentialを復号できるため、保存・転送時に暗号化し、別々のアクセス制御下で管理します。Issue、チャット、メール、コマンド出力、作業ログへSQL dump、salt、Application Passwordを載せません。具体的な対象と復元順序は[Monitorのアンインストール・バックアップ・復元](backup-and-restore.md)に定義します。

## Agent permissions and transport

Agent専用roleはログインに必要な `read` とAPI用の `od_monitor_read` だけを持ち、管理者権限を持ちません。`/ping`、`/status`、`/updates`、`/site-health` の全routeは `permission_callback` でこのcapabilityを確認します。未認証requestは401、ログイン済みでcapabilityがないrequestは403として拒否します。MonitorはHTTPS URLのみ受け入れ、WordPressの安全なHTTP APIで通信します。

## Logging and exposure

ログへ記録可能なのはsite UUID、endpoint、HTTP status、正規化済みerror code、durationです。Application Password、Authorization header、cookie、復号後credentialは記録禁止です。AgentはDB password、salts、API key、ユーザー一覧、投稿、フォーム送信、注文、プラグイン設定を返しません。収集中の想定外例外は固定codeと固定messageのREST errorへ変換し、例外message、file path、stack traceを応答へ含めません。MonitorもHTTP APIのraw error messageを分類にだけ使用し、保存・表示するerrorには引き継ぎません。

## MVP security review gate

MVPのsecurity boundaryは、次の自動テストをrelease前gateとして扱います。

| 境界 | 期待する挙動 |
| --- | --- |
| Agent REST API | 全4 endpointで未認証を401、capability不足を403として拒否し、`od_monitor_read` 保有者だけに成功応答を返す |
| Credential | Application Passwordをlibsodiumで暗号化してDBへ保存し、Agent requestの直前だけ復号する。保存後の管理画面には平文・暗号文とも再表示しない |
| Error・metadata | raw HTTP error、例外message、Authorization header、credentialをREST応答、`CheckResult`、永続metadataへ渡さない |
| Outbound request | 登録時と実行時にHTTPS・public IPを検証し、DNS再解決、private/reserved address、危険なredirect、認証付きcross-origin redirectを拒否する |

`composer test` は、全REST routeの未認証・権限不足・正常系、credentialの暗号化と復号境界、secretを含むraw errorの秘匿化、内部IP・DNS変更・redirectの拒否を統合して検証します。`composer run lint` と合わせて成功することをrelease条件とします。

## Outbound URL・SSRF対策

HTTP稼働監視、Agent API、SSL証明書確認は、すべて同じoutbound URL policyを使用します。サイト登録時に検証したURLであっても、監視実行の直前に再検証します。管理者による登録やWordPress filterを理由に、この検証を省略する例外は設けません。

許可条件は次のとおりです。

- `https` のみを許可し、schemeとhostを小文字化し、標準の443番portとfragmentを除いたURLへ正規化する
- userinfoを含むURL、不正なhostname、443・8080以外のportを拒否する
- `localhost`、`.localhost`、`.local`、`.internal`と代表的なmetadata hostnameを拒否する
- IPv4・IPv6のliteral addressと、DNSで得たすべてのA・AAAA addressを検証する
- loopback、private、link-local、reserved addressを1つでも含む場合は拒否する
- DNS解決に失敗した場合や、解決結果が空・不正な場合は接続しない

HTTP requestはWordPressの `wp_safe_remote_get()` と `reject_unsafe_urls` も併用します。独自検証とWordPress側の検証を実行直前に重ねることで、保存後のDNS変更や再解決時の変化を検出します。ただしDNSと接続先IPを固定する機能ではないため、信頼できるDNS resolver、egress firewall、network policyも併用してください。

redirectは自動追跡せず、共通HTTP層で最大3回まで処理します。各hopを同じ基準で再検証し、禁止先、DNS検証失敗、上限超過を安全な固定error codeへ正規化します。Authorization headerを伴うAgent requestは、別originへのredirectを拒否し、credentialを転送しません。

監視結果にはresponse body、Authorization header、credential、DNS解決結果、内部IPの詳細を保存しません。HTTP監視の最終URLを保存する場合もquery、fragment、userinfoを除外します。SSL監視はCA信頼チェーンとホスト名検証を有効にし、公開host、port、有効期間、残存日数、適用した閾値だけを保持します。

運用上、private network内のWordPressや自己署名証明書へ接続する例外設定はありません。監視対象はpublic DNSと信頼可能なTLS証明書を持つHTTPS endpointとして公開し、接続元制限が必要な場合はMonitor serverの固定egress IPを許可してください。

Agent到達性監視は保存済みcredentialを `/ping` の送信直前にだけ復号します。`CheckResult` には成功時のschema versionとAgent version、または正規化済みerror codeだけを含め、username、Application Password、Authorization header、rawエラーメッセージを含めません。

更新可否監視も保存済みcredentialを `/updates` の送信直前にだけ復号します。`CheckResult` には検証済みsummaryから得た種別別件数と対象種別だけを含め、個別プラグイン・テーマ情報、完全なAgent応答、credential、Authorization header、rawエラーを含めません。

定期実行lockは `odm_lock_{site_uuid}_{check_type}` 形式のautoload無効optionとして保存し、期限とランダムな所有tokenだけを含めます。期限切れlockの置換と解除は観測した値が一致する場合だけ行い、古い実行が新しいlockを解除しないようにします。monitor例外のrawメッセージは結果へ保存しません。

## Future considerations

Phase 2以降では、鍵のローテーション、外部KMS、credential再暗号化、監査ログ、接続先IP固定を含む追加のegress policy、明示的な削除フローを検討します。Phase 1では破壊的uninstallを行いません。
