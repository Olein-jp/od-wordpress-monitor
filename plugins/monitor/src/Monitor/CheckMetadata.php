<?php
/**
 * Reduces check data to the safe metadata allowed for persistence.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Monitor;

final class CheckMetadata {
	/**
	 * Return only the documented, bounded fields for one monitor type.
	 *
	 * @return array<string,mixed>
	 */
	public function for_result( CheckResult $result ): array {
		$data = $result->data();

		return match ( $result->type() ) {
			'http'    => $this->http( $data ),
			'updates' => $this->updates( $data ),
			'ssl'     => $this->ssl( $data ),
			default   => array(),
		};
	}

	/**
	 * @param array<string|int,mixed> $data Check data.
	 * @return array<string,mixed>
	 */
	private function http( array $data ): array {
		$metadata = array();

		if ( isset( $data['http_status'] ) && is_int( $data['http_status'] ) ) {
			$metadata['http_status'] = $data['http_status'];
		}

		if ( isset( $data['final_url'] ) && is_string( $data['final_url'] ) ) {
			$final_url = $this->public_url( $data['final_url'] );

			if ( null !== $final_url ) {
				$metadata['final_url'] = $final_url;
			}
		}

		return $metadata;
	}

	/**
	 * @param array<string|int,mixed> $data Check data.
	 * @return array<string,mixed>
	 */
	private function updates( array $data ): array {
		$metadata = array();

		foreach ( array( 'total_updates', 'wordpress_updates', 'plugin_updates', 'theme_updates' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_int( $data[ $key ] ) && $data[ $key ] >= 0 ) {
				$metadata[ $key ] = $data[ $key ];
			}
		}

		return $metadata;
	}

	/**
	 * @param array<string|int,mixed> $data Check data.
	 * @return array<string,mixed>
	 */
	private function ssl( array $data ): array {
		$metadata       = array();
		$expires_at     = $data['expires_at'] ?? $data['valid_to'] ?? null;
		$days_remaining = $data['days_remaining'] ?? $data['days_left'] ?? null;

		if ( is_string( $expires_at ) && strlen( $expires_at ) <= 64 ) {
			$metadata['expires_at'] = $expires_at;
		}

		if ( is_int( $days_remaining ) ) {
			$metadata['days_remaining'] = $days_remaining;
		}

		return $metadata;
	}

	private function public_url( string $url ): ?string {
		$parts = wp_parse_url( $url );

		if (
			! is_array( $parts )
			|| ! isset( $parts['scheme'], $parts['host'] )
			|| ! in_array( $parts['scheme'], array( 'http', 'https' ), true )
		) {
			return null;
		}

		$public_url = $parts['scheme'] . '://' . $parts['host'];

		if ( isset( $parts['port'] ) ) {
			$public_url .= ':' . (int) $parts['port'];
		}

		return $public_url . ( $parts['path'] ?? '' );
	}
}
