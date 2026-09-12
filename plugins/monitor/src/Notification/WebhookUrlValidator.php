<?php
/**
 * Strict validation for supported incoming webhook URLs.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Notification;

use WP_Error;

final class WebhookUrlValidator {
	/**
	 * Validate and normalize one supported webhook URL.
	 *
	 * @return string|WP_Error
	 */
	public function validate( string $channel_id, string $url ) {
		$url = trim( wp_unslash( $url ) );

		if ( '' === $url || preg_match( '/[\x00-\x20\\\\]/', $url ) ) {
			return $this->error();
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) ) {
			return $this->error();
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return $this->error();
		}

		if ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) {
			return $this->error();
		}

		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		$path = (string) ( $parts['path'] ?? '' );
		if ( SlackNotifier::CHANNEL_ID === $channel_id ) {
			$valid = 'hooks.slack.com' === $host
				&& 1 === preg_match( '#^/services/[A-Za-z0-9]+/[A-Za-z0-9]+/[A-Za-z0-9_-]+$#', $path );
		} elseif ( DiscordNotifier::CHANNEL_ID === $channel_id ) {
			$valid = 'discord.com' === $host
				&& 1 === preg_match( '#^/api/webhooks/[0-9]+/[A-Za-z0-9._-]+$#', $path );
		} else {
			$valid = false;
		}

		if ( ! $valid ) {
			return $this->error();
		}

		return 'https://' . $host . $path;
	}

	private function error(): WP_Error {
		return new WP_Error( 'INVALID_WEBHOOK_URL', __( 'Enter a valid webhook URL for this service.', 'od-wordpress-monitor' ) );
	}
}
