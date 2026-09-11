<?php
/**
 * Reduces check data to the safe metadata allowed for persistence.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Monitor;

final class CheckMetadata {
	private const MAX_INVENTORY_PLUGINS = 100;
	private const MAX_IDENTIFIER_LENGTH = 191;
	private const MAX_NAME_LENGTH       = 160;
	private const MAX_VERSION_LENGTH    = 64;

	/**
	 * Return only the documented, bounded fields for one monitor type.
	 *
	 * @return array<string,mixed>
	 */
	public function for_result( CheckResult $result ): array {
		$data = $result->data();

		return match ( $result->type() ) {
			'http'        => $this->http( $data ),
			'updates'     => $this->updates( $data ),
			'site_health' => $this->site_health( $data ),
			'ssl'         => $this->ssl( $data ),
			default       => array(),
		};
	}

	/**
	 * Return bounded metadata for the single current-state row.
	 *
	 * Unlike check and event history, current status may retain the latest
	 * normalized software inventory without duplicating it for every run.
	 *
	 * @return array<string,mixed>
	 */
	public function for_status( CheckResult $result ): array {
		$metadata = $this->for_result( $result );
		$data     = $result->data();

		if ( 'agent_status' === $result->type() ) {
			return $this->agent_status( $data );
		}

		if ( 'updates' === $result->type() && isset( $data['software_inventory'] ) ) {
			$inventory = $this->software_inventory( $data['software_inventory'] );

			if ( null !== $inventory ) {
				$metadata['software_inventory'] = $inventory;
			}
		}

		return $metadata;
	}

	/**
	 * @param array<string|int,mixed> $data Check data.
	 * @return array<string,mixed>
	 */
	private function agent_status( array $data ): array {
		$metadata = array();

		foreach ( array( 'wordpress', 'php', 'agent_version', 'environment_type' ) as $key ) {
			$value = isset( $data[ $key ] ) ? $this->bounded_text( $data[ $key ], self::MAX_VERSION_LENGTH ) : null;

			if ( null !== $value ) {
				$metadata[ $key ] = $value;
			}
		}

		if ( isset( $data['is_multisite'] ) && is_bool( $data['is_multisite'] ) ) {
			$metadata['is_multisite'] = $data['is_multisite'];
		}

		return $metadata;
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
	 * @param mixed $inventory Untrusted current software inventory.
	 * @return array<string,mixed>|null
	 */
	private function software_inventory( $inventory ): ?array {
		if (
			! is_array( $inventory )
			|| ! isset( $inventory['wordpress_version'], $inventory['plugins'], $inventory['collected_at'] )
			|| ! is_array( $inventory['plugins'] )
			|| ! array_is_list( $inventory['plugins'] )
			|| ! is_string( $inventory['collected_at'] )
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $inventory['collected_at'] )
			|| false === strtotime( $inventory['collected_at'] )
		) {
			return null;
		}

		$wordpress_version = $this->bounded_text( $inventory['wordpress_version'], self::MAX_VERSION_LENGTH );
		$theme             = null;

		if ( null === $wordpress_version ) {
			return null;
		}

		if ( isset( $inventory['theme'] ) && is_array( $inventory['theme'] ) ) {
			$theme = $this->software_item( $inventory['theme'] );
		}

		$plugins = array();
		$seen    = array();

		foreach ( $inventory['plugins'] as $plugin ) {
			$item = is_array( $plugin ) ? $this->software_item( $plugin ) : null;

			if ( null === $item || isset( $seen[ $item['id'] ] ) ) {
				continue;
			}

			$seen[ $item['id'] ] = true;
			$plugins[]           = $item;
		}

		usort(
			$plugins,
			static fn( array $first, array $second ): int => array( strtolower( $first['name'] ), $first['id'] ) <=> array( strtolower( $second['name'] ), $second['id'] )
		);

		$plugins_truncated = count( $plugins ) > self::MAX_INVENTORY_PLUGINS;
		$plugins           = array_slice( $plugins, 0, self::MAX_INVENTORY_PLUGINS );

		return array(
			'wordpress_version' => $wordpress_version,
			'theme'             => $theme,
			'plugins'           => $plugins,
			'collected_at'      => $inventory['collected_at'],
			'truncated'         => $plugins_truncated,
		);
	}

	/**
	 * @param array<string|int,mixed> $item Untrusted software item.
	 * @return array{id:string,name:string,version:string}|null
	 */
	private function software_item( array $item ): ?array {
		$id      = isset( $item['id'] ) ? $this->bounded_text( $item['id'], self::MAX_IDENTIFIER_LENGTH ) : null;
		$name    = isset( $item['name'] ) ? $this->bounded_text( $item['name'], self::MAX_NAME_LENGTH ) : null;
		$version = isset( $item['version'] ) ? $this->bounded_text( $item['version'], self::MAX_VERSION_LENGTH, true ) : null;

		return null === $id || null === $name || null === $version
			? null
			: compact( 'id', 'name', 'version' );
	}

	/**
	 * @param mixed $value Untrusted text value.
	 */
	private function bounded_text( $value, int $max_length, bool $allow_empty = false ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = trim( sanitize_text_field( wp_strip_all_tags( $value, true ) ) );

		if ( '' === $value ) {
			return $allow_empty ? '' : null;
		}

		return wp_html_excerpt( $value, $max_length, '' );
	}

	/**
	 * @param array<string|int,mixed> $data Check data.
	 * @return array<string,mixed>
	 */
	private function site_health( array $data ): array {
		$metadata = array();

		foreach ( array( 'critical', 'recommended', 'good' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_int( $data[ $key ] ) && $data[ $key ] >= 0 ) {
				$metadata[ $key ] = $data[ $key ];
			}
		}

		if ( isset( $data['representative_test_id'] ) && is_string( $data['representative_test_id'] ) && 1 === preg_match( '/^[a-z0-9_]+$/', $data['representative_test_id'] ) ) {
			$metadata['representative_test_id'] = $data['representative_test_id'];
		}

		if ( isset( $data['representative_test_status'] ) && in_array( $data['representative_test_status'], array( 'critical', 'recommended', 'good' ), true ) ) {
			$metadata['representative_test_status'] = $data['representative_test_status'];
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
