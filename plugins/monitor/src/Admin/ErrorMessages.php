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
			ErrorCode::INVALID_URL           => __( 'Enter a valid public site URL.', 'od-wordpress-monitor' ),
			ErrorCode::HTTPS_REQUIRED        => __( 'The site URL must use HTTPS.', 'od-wordpress-monitor' ),
			ErrorCode::TIMEOUT               => __( 'The connection to the Agent timed out.', 'od-wordpress-monitor' ),
			ErrorCode::AGENT_NOT_FOUND       => __( 'The Agent API was not found. Make sure the plugin is active.', 'od-wordpress-monitor' ),
			ErrorCode::AUTHENTICATION_FAILED => __( 'Agent authentication failed. Check the username and Application Password.', 'od-wordpress-monitor' ),
			ErrorCode::PERMISSION_DENIED     => __( 'The Agent user does not have the required permission.', 'od-wordpress-monitor' ),
			ErrorCode::INVALID_JSON          => __( 'The Agent did not return a valid JSON response.', 'od-wordpress-monitor' ),
			ErrorCode::INVALID_RESPONSE      => __( 'The Agent response format is invalid.', 'od-wordpress-monitor' ),
			ErrorCode::UNSUPPORTED_SCHEMA    => __( 'The Agent API version is not supported.', 'od-wordpress-monitor' ),
			default                          => __( 'Could not connect to the Agent.', 'od-wordpress-monitor' ),
		};
	}
}
