<?php
/**
 * Safe JSON metadata encoding and decoding.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Support;

use WP_Error;

final class MetadataCodec {
	private const MAX_BYTES = 65535;

	private const SENSITIVE_KEYS = array(
		'api_key',
		'application_password',
		'authorization',
		'cookie',
		'cookies',
		'credential',
		'credentials',
		'password',
		'salt',
		'sensitive',
		'secret',
		'token',
	);

	/**
	 * Encode bounded, non-sensitive metadata for persistence.
	 *
	 * @param array<string|int,mixed> $metadata Metadata to encode.
	 * @return string|WP_Error
	 */
	public function encode( array $metadata ) {
		$encoded = wp_json_encode( $metadata, JSON_UNESCAPED_SLASHES );

		if ( false === $encoded ) {
			return new WP_Error( 'INVALID_METADATA', __( 'Metadata could not be encoded.', 'od-wordpress-monitor' ) );
		}

		if ( ! $this->is_safe( $metadata ) ) {
			return new WP_Error( 'UNSAFE_METADATA', __( 'Metadata contains unsupported or sensitive data.', 'od-wordpress-monitor' ) );
		}

		if ( self::MAX_BYTES < strlen( $encoded ) ) {
			return new WP_Error( 'METADATA_TOO_LARGE', __( 'Metadata is too large to persist.', 'od-wordpress-monitor' ) );
		}

		return $encoded;
	}

	/**
	 * Decode persisted metadata, falling back safely for malformed values.
	 *
	 * @return array<string|int,mixed>
	 */
	public function decode( ?string $metadata ): array {
		if ( null === $metadata || '' === $metadata || self::MAX_BYTES < strlen( $metadata ) ) {
			return array();
		}

		$decoded = json_decode( $metadata, true );

		return is_array( $decoded ) && JSON_ERROR_NONE === json_last_error() && $this->is_safe( $decoded ) ? $decoded : array();
	}

	/**
	 * Validate metadata recursively before encoding.
	 *
	 * @param array<string|int,mixed> $metadata Metadata to validate.
	 */
	private function is_safe( array $metadata ): bool {
		foreach ( $metadata as $key => $value ) {
			if ( is_string( $key ) ) {
				$normalized_key = strtolower( str_replace( '-', '_', $key ) );

				if ( in_array( $normalized_key, self::SENSITIVE_KEYS, true ) || 1 === preg_match( '/(?:^|_)(?:api_key|application_password|authorization|cookies?|credentials?|password|salt|secret|token)(?:_|$)/', $normalized_key ) ) {
					return false;
				}
			}

			if ( is_array( $value ) ) {
				if ( ! $this->is_safe( $value ) ) {
					return false;
				}
			} elseif ( is_string( $value ) ) {
				if ( 1 === preg_match( '/(?:^|\s)authorization\s*:|\b(?:basic|bearer)\s+[a-z0-9+\/=._-]{8,}/i', $value ) ) {
					return false;
				}
			} elseif ( is_float( $value ) && ! is_finite( $value ) ) {
				return false;
			} elseif ( null !== $value && ! is_int( $value ) && ! is_float( $value ) && ! is_bool( $value ) ) {
				return false;
			}
		}

		return true;
	}
}
