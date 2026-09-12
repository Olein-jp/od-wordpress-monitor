<?php
/**
 * Secret-safe webhook HTTP delivery.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Notification;

final class WebhookClient {
	public function __construct( private readonly WebhookUrlValidator $validator ) {
	}

	/**
	 * @param array<string,mixed> $payload JSON payload.
	 */
	public function post( string $channel_id, string $url, array $payload ): NotificationChannelResult {
		$validated = $this->validator->validate( $channel_id, $url );
		if ( is_wp_error( $validated ) ) {
			return NotificationChannelResult::failed( $channel_id, 'WEBHOOK_URL_INVALID' );
		}

		if ( DiscordNotifier::CHANNEL_ID === $channel_id ) {
			$validated .= '?wait=true';
		}

		$body = wp_json_encode( $payload );
		if ( false === $body ) {
			return NotificationChannelResult::failed( $channel_id, 'PAYLOAD_ENCODING_FAILED' );
		}

		$response = wp_safe_remote_post(
			$validated,
			array(
				'body'                => $body,
				'headers'             => array(
					'Accept'       => 'application/json, text/plain',
					'Content-Type' => 'application/json; charset=utf-8',
				),
				'limit_response_size' => 4096,
				'redirection'         => 0,
				'reject_unsafe_urls'  => true,
				'timeout'             => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			$error = strtolower( $response->get_error_code() . ' ' . $response->get_error_message() );

			return NotificationChannelResult::failed(
				$channel_id,
				str_contains( $error, 'timeout' ) || str_contains( $error, 'timed out' ) ? 'TIMEOUT' : 'CONNECTION_ERROR'
			);
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( $status >= 200 && $status < 300 ) {
			return NotificationChannelResult::sent( $channel_id );
		}

		return NotificationChannelResult::failed(
			$channel_id,
			$status >= 100 && $status <= 599 ? 'HTTP_' . $status : 'HTTP_RESPONSE_INVALID'
		);
	}
}
