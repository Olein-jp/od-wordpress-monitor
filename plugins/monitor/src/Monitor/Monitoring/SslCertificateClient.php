<?php
/**
 * Native TLS certificate client.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Monitor\Monitoring;

use Olein\WordPressMonitor\Support\ErrorCode;
use WP_Error;

final class SslCertificateClient implements SslCertificateClientInterface {
	/**
	 * Inspect a certificate only after its trust chain and peer name are verified.
	 *
	 * @return array{valid_from:int,valid_to:int}|WP_Error
	 */
	public function inspect( string $host, int $port, int $timeout ): array|WP_Error {
		if ( ! extension_loaded( 'openssl' ) ) {
			return new WP_Error( ErrorCode::SSL_UNAVAILABLE );
		}

		$context = stream_context_create(
			array(
				'ssl' => array(
					'capture_peer_cert' => true,
					'peer_name'         => $host,
					'SNI_enabled'       => true,
					'verify_peer'       => true,
					'verify_peer_name'  => true,
				),
			)
		);
		$address = sprintf( 'tls://%s:%d', str_contains( $host, ':' ) ? '[' . $host . ']' : $host, $port );
		$errno   = 0;
		$errstr  = '';
		$warning = '';

		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- TLS warnings are converted to safe result codes.
			static function ( int $level, string $message ) use ( &$warning ): bool {
				unset( $level );
				$warning = $message;
				return true;
			}
		);

		try {
			$stream = stream_socket_client( $address, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context );
		} finally {
			restore_error_handler();
		}

		if ( false === $stream ) {
			return $this->connection_error( $errno, $errstr . ' ' . $warning );
		}

		$parameters  = stream_context_get_params( $stream );
		$certificate = $parameters['options']['ssl']['peer_certificate'] ?? null;
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- This is a network stream, not a filesystem operation.

		if ( null === $certificate ) {
			return new WP_Error( ErrorCode::INVALID_CERTIFICATE );
		}

		$parsed = openssl_x509_parse( $certificate );

		if ( ! is_array( $parsed ) || ! isset( $parsed['validFrom_time_t'], $parsed['validTo_time_t'] ) ) {
			return new WP_Error( ErrorCode::INVALID_CERTIFICATE );
		}

		return array(
			'valid_from' => (int) $parsed['validFrom_time_t'],
			'valid_to'   => (int) $parsed['validTo_time_t'],
		);
	}

	/**
	 * Classify a transport failure without exposing the raw OpenSSL error.
	 */
	private function connection_error( int $errno, string $details ): WP_Error {
		$details = strtolower( $details );

		if ( str_contains( $details, 'timed out' ) || str_contains( $details, 'timeout' ) ) {
			return new WP_Error( ErrorCode::TIMEOUT );
		}

		if ( str_contains( $details, 'certificate has expired' ) || str_contains( $details, 'certificate expired' ) ) {
			return new WP_Error( ErrorCode::CERTIFICATE_EXPIRED );
		}

		if (
			0 === $errno
			|| str_contains( $details, 'certificate' )
			|| str_contains( $details, 'crypto' )
			|| str_contains( $details, 'unknown ca' )
		) {
			return new WP_Error( ErrorCode::CERTIFICATE_VALIDATION_FAILED );
		}

		return new WP_Error( ErrorCode::CONNECTION_ERROR );
	}
}
