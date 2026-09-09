# Security

## Threat model

Phase 1では、Monitor DBの読み取り、ネットワーク盗聴、Agent credentialの過剰権限、偽Agent応答、管理画面CSRF、応答改ざんを主な脅威として扱います。MonitorまたはWordPress saltsとDBの両方を取得された場合は、credential復号が可能になる点は残存リスクです。

## Credential storage

Application Passwordは平文保存しません。`CredentialEncryptor` はlibsodium `secretbox` を使用し、暗号化ごとにランダムnonceを生成します。ciphertextには認証タグが含まれ、改ざん時は復号に失敗します。暗号鍵はDBへ保存せず、WordPressのauth saltからHKDF-SHA-256で導出します。

バックアップ移行時は、Monitor DBだけでなく元のWordPress saltsも安全に引き継ぐ必要があります。saltを変更すると既存credentialは復号できなくなります。

## Agent permissions and transport

Agent専用roleはログインに必要な `read` とAPI用の `od_monitor_read` だけを持ち、管理者権限を持ちません。`/ping` と `/status` は `permission_callback` でこのcapabilityを確認します。MonitorはHTTPS URLのみ受け入れ、WordPressの安全なHTTP APIで通信します。

## Logging and exposure

ログへ記録可能なのはsite UUID、endpoint、HTTP status、正規化済みerror code、durationです。Application Password、Authorization header、cookie、復号後credentialは記録禁止です。AgentはDB password、salts、API key、ユーザー一覧、投稿、フォーム送信、注文、プラグイン設定を返しません。

HTTP稼働監視は登録済みsite URLだけを対象とし、WordPressの安全なHTTP APIで各接続先を検証します。redirectは自動追跡せず最大3回に制限し、private・loopbackなどの安全でない接続先を拒否します。結果にはresponse bodyを含めず、最終URLからquery、fragment、userinfoを除外します。

SSL証明書監視も登録済みの公開HTTPS URLだけを対象とし、private・loopback URLとuserinfoを拒否します。TLS接続ではCA信頼チェーンとホスト名検証を有効にし、結果には公開host、port、有効期間、残存日数、適用した閾値だけを保持します。OpenSSLのrawエラーや証明書のsubject情報は保持しません。

Agent到達性監視は保存済みcredentialを `/ping` の送信直前にだけ復号します。`CheckResult` には成功時のschema versionとAgent version、または正規化済みerror codeだけを含め、username、Application Password、Authorization header、rawエラーメッセージを含めません。

更新可否監視も保存済みcredentialを `/updates` の送信直前にだけ復号します。`CheckResult` には検証済みsummaryから得た種別別件数と対象種別だけを含め、個別プラグイン・テーマ情報、完全なAgent応答、credential、Authorization header、rawエラーを含めません。

定期実行lockは `odm_lock_{site_uuid}_{check_type}` 形式のautoload無効optionとして保存し、期限とランダムな所有tokenだけを含めます。期限切れlockの置換と解除は観測した値が一致する場合だけ行い、古い実行が新しいlockを解除しないようにします。monitor例外のrawメッセージは結果へ保存しません。

## Future considerations

Phase 2以降では、鍵のローテーション、外部KMS、credential再暗号化、監査ログ、SSRF対策の追加ポリシー、明示的な削除フローを検討します。Phase 1では破壊的uninstallを行いません。
