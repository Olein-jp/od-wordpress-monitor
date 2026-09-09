<?php
/**
 * OD Monitor Agent API client.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Http;

use Olein\WordPressMonitor\Credential\Credential;
use Olein\WordPressMonitor\Protocol\ResponseValidator;
use Olein\WordPressMonitor\Site\Site;
use Olein\WordPressMonitor\Support\ErrorCode;
use WP_Error;

final class AgentClient {
	public const TIMEOUT = 15;

	public function __construct(
		private readonly HttpClient $http_client,
		private readonly ResponseValidator $validator
	) {
	}

	/**
	 * Call the Agent ping endpoint.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function ping( Site $site, Credential $credential ) {
		return $this->request( $site, $credential, 'ping' );
	}

	/**
	 * Call the Agent status endpoint.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function status( Site $site, Credential $credential ) {
		return $this->request( $site, $credential, 'status' );
	}

	/**
	 * Call the Agent updates endpoint.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function updates( Site $site, Credential $credential ) {
		return $this->request( $site, $credential, 'updates' );
	}

	/**
	 * Make and normalize an Agent request.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	private function request( Site $site, Credential $credential, string $endpoint ) {
		if ( 'https' !== wp_parse_url( $site->agent_url(), PHP_URL_SCHEME ) ) {
			return new WP_Error( ErrorCode::HTTPS_REQUIRED, __( 'Agent connections require HTTPS.', 'od-wordpress-monitor' ) );
		}

		$response = $this->http_client->get(
			trailingslashit( $site->agent_url() ) . $endpoint,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 3,
				'headers'     => array(
					'Accept'        => 'application/json',
					'Authorization' => 'Basic ' . base64_encode( $credential->username() . ':' . $credential->password() ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$message = strtolower( $response->get_error_message() );
			$code    = str_contains( $message, 'timed out' ) || str_contains( $message, 'timeout' ) ? ErrorCode::TIMEOUT : ErrorCode::CONNECTION_ERROR;

			return new WP_Error( $code, __( 'The Agent could not be reached.', 'od-wordpress-monitor' ) );
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status_code ) {
			return $this->http_error( $status_code );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			return new WP_Error( ErrorCode::INVALID_JSON, __( 'The Agent returned invalid JSON.', 'od-wordpress-monitor' ) );
		}

		$valid = match ( $endpoint ) {
			'ping'    => $this->validator->validate_ping( $data ),
			'status'  => $this->validator->validate_status( $data ),
			'updates' => $this->validator->validate_updates( $data ),
		};

		return is_wp_error( $valid ) ? $valid : $data;
	}

	private function http_error( int $status_code ): WP_Error {
		$code = match ( $status_code ) {
			401     => ErrorCode::AUTHENTICATION_FAILED,
			403     => ErrorCode::PERMISSION_DENIED,
			404     => ErrorCode::AGENT_NOT_FOUND,
			default => ErrorCode::CONNECTION_ERROR,
		};

		return new WP_Error(
			$code,
			__( 'The Agent request failed.', 'od-wordpress-monitor' ),
			array( 'status' => $status_code )
		);
	}
}
