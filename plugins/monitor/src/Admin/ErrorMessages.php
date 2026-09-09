<?php
/**
 * User-facing connection error messages.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Admin;

use Olein\WordPressMonitor\Support\ErrorCode;

final class ErrorMessages {
	public static function for_code( string $code ): string {
		return match ( $code ) {
			ErrorCode::INVALID_URL           => __( '有効な公開サイトURLを入力してください。', 'od-wordpress-monitor' ),
			ErrorCode::HTTPS_REQUIRED        => __( 'Site URLにはHTTPSが必要です。', 'od-wordpress-monitor' ),
			ErrorCode::TIMEOUT               => __( 'Agentへの接続がタイムアウトしました。', 'od-wordpress-monitor' ),
			ErrorCode::AGENT_NOT_FOUND       => __( 'Agent APIが見つかりません。プラグインが有効か確認してください。', 'od-wordpress-monitor' ),
			ErrorCode::AUTHENTICATION_FAILED => __( 'Agentへの認証に失敗しました。ユーザー名とApplication Passwordを確認してください。', 'od-wordpress-monitor' ),
			ErrorCode::PERMISSION_DENIED     => __( 'Agentユーザーに必要な権限がありません。', 'od-wordpress-monitor' ),
			ErrorCode::INVALID_JSON          => __( 'Agentから有効なJSON応答を取得できませんでした。', 'od-wordpress-monitor' ),
			ErrorCode::INVALID_RESPONSE      => __( 'Agentの応答形式が正しくありません。', 'od-wordpress-monitor' ),
			ErrorCode::UNSUPPORTED_SCHEMA    => __( 'AgentのAPIバージョンに対応していません。', 'od-wordpress-monitor' ),
			default                          => __( 'Agentへ接続できませんでした。', 'od-wordpress-monitor' ),
		};
	}
}
