<?php
/**
 * Secret-safe webhook HTTP delivery.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Notification;

final class WebhookClient {
	private const CHATWORK_ENDPOINT = 'https://api.chatwork.com/v2/rooms/%s/messages';

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

		return $this->request(
			$channel_id,
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
	}

	public function post_chatwork( string $room_id, string $api_token, string $body ): NotificationChannelResult {
		if ( 1 !== preg_match( '/^[1-9][0-9]{0,19}$/', $room_id ) || 1 !== preg_match( '/^[A-Za-z0-9._~-]{1,255}$/', $api_token ) ) {
			return NotificationChannelResult::failed( ChatworkNotifier::CHANNEL_ID, 'CHATWORK_SETTINGS_INVALID' );
		}

		return $this->request(
			ChatworkNotifier::CHANNEL_ID,
			sprintf( self::CHATWORK_ENDPOINT, $room_id ),
			array(
				'body'                => array( 'body' => $body ),
				'headers'             => array(
					'Accept'          => 'application/json',
					'x-chatworktoken' => $api_token,
				),
				'limit_response_size' => 4096,
				'redirection'         => 0,
				'reject_unsafe_urls'  => true,
				'timeout'             => 10,
			)
		);
	}

	/**
	 * @param array<string,mixed> $arguments WordPress HTTP API arguments.
	 */
	private function request( string $channel_id, string $url, array $arguments ): NotificationChannelResult {
		$response = wp_safe_remote_post( $url, $arguments );

		if ( is_wp_error( $response ) ) {
			$error = strtolower( $response->get_error_code() . ' ' . $response->get_error_message() );
			if ( str_contains( $error, 'certificate' ) || str_contains( $error, 'ssl' ) ) {
				return NotificationChannelResult::failed( $channel_id, 'TLS_ERROR' );
			}

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
			$status >= 100 && $status <= 599 ? 'HTTP_' . $status : 'HTTP_RESPONSE_INVALID',
			1,
			$this->retry_after( $response, $status )
		);
	}

	/**
	 * Accept only a short, positive delay from transient HTTP responses.
	 *
	 * @param array<string,mixed> $response WordPress HTTP response.
	 */
	private function retry_after( array $response, int $status ): ?int {
		if ( ! in_array( $status, array( 408, 429 ), true ) && ( $status < 500 || $status > 599 ) ) {
			return null;
		}

		$header = wp_remote_retrieve_header( $response, 'retry-after' );
		if ( ! is_string( $header ) || '' === $header || strlen( $header ) > 80 || preg_match( '/[\r\n]/', $header ) ) {
			return null;
		}

		if ( 1 === preg_match( '/^[0-9]{1,4}$/', $header ) ) {
			$seconds = (int) $header;
		} else {
			$timestamp = strtotime( $header );
			$seconds   = false === $timestamp ? 0 : $timestamp - time();
		}

		return $seconds >= 1 && $seconds <= 900 ? $seconds : null;
	}
}
