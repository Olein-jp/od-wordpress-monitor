<?php
/**
 * Public HTTPS URL validation with DNS and IP checks.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Http;

use Closure;
use Olein\WordPressMonitor\Support\ErrorCode;
use Throwable;
use WP_Error;

final class UrlValidator {
	private readonly Closure $resolver;

	/**
	 * @param null|Closure(string):array<int,string>|WP_Error $resolver Hostname resolver.
	 */
	public function __construct( ?Closure $resolver = null ) {
		$this->resolver = $resolver ?? $this->default_resolver( ... );
	}

	/**
	 * Validate and normalize a public HTTPS URL.
	 *
	 * @return string|WP_Error
	 */
	public function validate( string $url ) {
		$url = trim( $url );

		if ( '' === $url || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) {
			return $this->invalid_url();
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return $this->invalid_url();
		}

		if ( 'https' !== strtolower( (string) $parts['scheme'] ) ) {
			return new WP_Error( ErrorCode::HTTPS_REQUIRED, __( 'Only public HTTPS URLs are allowed.', 'od-wordpress-monitor' ) );
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return $this->invalid_url();
		}

		$host = strtolower( trim( (string) $parts['host'], '[] .' ) );

		if ( '' === $host || $this->is_blocked_hostname( $host ) ) {
			return $this->invalid_url();
		}

		$is_ip = false !== filter_var( $host, FILTER_VALIDATE_IP );

		if ( ! $is_ip && false === filter_var( $host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME ) ) {
			return $this->invalid_url();
		}

		$port = isset( $parts['port'] ) ? (int) $parts['port'] : 443;

		if ( ! in_array( $port, array( 443, 8080 ), true ) ) {
			return $this->invalid_url();
		}

		$addresses = $is_ip ? array( $host ) : $this->resolve( $host );

		if ( is_wp_error( $addresses ) || array() === $addresses ) {
			return $this->invalid_url();
		}

		foreach ( $addresses as $address ) {
			if ( ! $this->is_public_ip( $address ) ) {
				return $this->invalid_url();
			}
		}

		$normalized_host = str_contains( $host, ':' ) ? '[' . $host . ']' : $host;
		$normalized      = 'https://' . $normalized_host;

		if ( 443 !== $port ) {
			$normalized .= ':' . $port;
		}

		$normalized .= $parts['path'] ?? '';

		if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
			$normalized .= '?' . $parts['query'];
		}

		return $normalized;
	}

	/**
	 * Resolve a hostname and normalize resolver failures.
	 *
	 * @return array<int,string>|WP_Error
	 */
	private function resolve( string $host ) {
		try {
			$addresses = ( $this->resolver )( $host );
		} catch ( Throwable $exception ) {
			unset( $exception );
			return $this->invalid_url();
		}

		if ( is_wp_error( $addresses ) || ! is_array( $addresses ) ) {
			return $this->invalid_url();
		}

		return array_values( array_unique( array_filter( $addresses, 'is_string' ) ) );
	}

	/**
	 * Resolve every IPv4 and IPv6 address for a hostname.
	 *
	 * @return array<int,string>|WP_Error
	 */
	private function default_resolver( string $host ) {
		$records = dns_get_record( $host, DNS_A | DNS_AAAA );

		if ( false === $records ) {
			return $this->invalid_url();
		}

		$addresses = array();

		foreach ( $records as $record ) {
			if ( isset( $record['ip'] ) && is_string( $record['ip'] ) ) {
				$addresses[] = $record['ip'];
			}

			if ( isset( $record['ipv6'] ) && is_string( $record['ipv6'] ) ) {
				$addresses[] = $record['ipv6'];
			}
		}

		return $addresses;
	}

	private function is_blocked_hostname( string $host ): bool {
		return 'localhost' === $host
			|| 'metadata.google.internal' === $host
			|| str_ends_with( $host, '.localhost' )
			|| str_ends_with( $host, '.local' )
			|| str_ends_with( $host, '.internal' );
	}

	private function is_public_ip( string $address ): bool {
		$packed = inet_pton( $address );

		if ( false === $packed ) {
			return false;
		}

		if ( 16 === strlen( $packed ) && str_starts_with( $packed, str_repeat( "\0", 10 ) . "\xff\xff" ) ) {
			$address = inet_ntop( substr( $packed, 12 ) );
		}

		return false !== filter_var(
			$address,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}

	private function invalid_url(): WP_Error {
		return new WP_Error( ErrorCode::INVALID_URL, __( 'The URL must resolve only to public network addresses.', 'od-wordpress-monitor' ) );
	}
}
