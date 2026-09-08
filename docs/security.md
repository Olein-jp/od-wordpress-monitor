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

## Future considerations

Phase 2以降では、鍵のローテーション、外部KMS、credential再暗号化、監査ログ、SSRF対策の追加ポリシー、明示的な削除フローを検討します。Phase 1では破壊的uninstallを行いません。
