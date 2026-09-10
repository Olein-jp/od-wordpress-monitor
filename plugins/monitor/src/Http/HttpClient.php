<?php
/**
 * WordPress HTTP API adapter.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Http;

use Olein\WordPressMonitor\Support\ErrorCode;
use WP_Error;
use WP_Http;

final class HttpClient {
	public const MAX_REDIRECTS = 3;

	private readonly UrlValidator $validator;

	public function __construct( ?UrlValidator $validator = null ) {
		$this->validator = $validator ?? new UrlValidator();
	}

	/**
	 * Validate a request URL using the shared outbound policy.
	 *
	 * @return string|WP_Error
	 */
	public function validate_url( string $url ) {
		return $this->validator->validate( $url );
	}

	/**
	 * Make a safe GET request.
	 *
	 * @param array<string,mixed> $arguments Request arguments.
	 * @return array<string,mixed>|WP_Error
	 */
	public function get( string $url, array $arguments = array() ) {
		$validated = $this->validate_url( $url );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$max_redirects                   = min( self::MAX_REDIRECTS, max( 0, (int) ( $arguments['redirection'] ?? 0 ) ) );
		$arguments['redirection']        = 0;
		$arguments['reject_unsafe_urls'] = true;
		$current_url                     = $validated;
		$redirect_count                  = 0;

		while ( true ) {
			$response = wp_safe_remote_get( $current_url, $arguments );

			if ( is_wp_error( $response ) || 0 === $max_redirects ) {
				return $response;
			}

			$status = wp_remote_retrieve_response_code( $response );

			if ( $status < 300 || $status >= 400 ) {
				return $response;
			}

			$location = wp_remote_retrieve_header( $response, 'location' );

			if ( ! is_string( $location ) || '' === trim( $location ) ) {
				return $response;
			}

			if ( $redirect_count >= $max_redirects ) {
				return new WP_Error( ErrorCode::REDIRECT_LIMIT, __( 'The request exceeded the redirect limit.', 'od-wordpress-monitor' ) );
			}

			$redirect_url = WP_Http::make_absolute_url( trim( $location ), $current_url );
			$next_url     = $this->validate_url( $redirect_url );

			if ( is_wp_error( $next_url ) || ( $this->has_authorization( $arguments ) && ! $this->same_origin( $current_url, $next_url ) ) ) {
				return new WP_Error( ErrorCode::UNSAFE_REDIRECT, __( 'The request was redirected to an unsafe destination.', 'od-wordpress-monitor' ) );
			}

			$current_url = $next_url;
			++$redirect_count;
		}
	}

	/**
	 * @param array<string,mixed> $arguments Request arguments.
	 */
	private function has_authorization( array $arguments ): bool {
		$headers = $arguments['headers'] ?? array();

		if ( is_string( $headers ) ) {
			return 1 === preg_match( '/(?:^|\r?\n)Authorization\s*:/i', $headers );
		}

		if ( ! is_array( $headers ) ) {
			return false;
		}

		foreach ( array_keys( $headers ) as $name ) {
			if ( is_string( $name ) && 'authorization' === strtolower( $name ) ) {
				return true;
			}
		}

		return false;
	}

	private function same_origin( string $current_url, string $next_url ): bool {
		$current = wp_parse_url( $current_url );
		$next    = wp_parse_url( $next_url );

		if ( ! is_array( $current ) || ! is_array( $next ) ) {
			return false;
		}

		return strtolower( (string) ( $current['host'] ?? '' ) ) === strtolower( (string) ( $next['host'] ?? '' ) )
			&& (int) ( $current['port'] ?? 443 ) === (int) ( $next['port'] ?? 443 );
	}
}
