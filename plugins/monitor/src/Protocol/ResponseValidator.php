<?php
/**
 * Agent protocol response validation.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Protocol;

use Olein\WordPressMonitor\Support\ErrorCode;
use WP_Error;

final class ResponseValidator {
	public const SUPPORTED_SCHEMA_VERSION = '1.0';

	/**
	 * Validate a ping response.
	 *
	 * @param mixed $data Decoded response data.
	 * @return true|WP_Error
	 */
	public function validate_ping( $data ) {
		$base = $this->validate_base( $data );

		if ( is_wp_error( $base ) ) {
			return $base;
		}

		if (
			! isset( $data['success'], $data['agent']['slug'], $data['agent']['version'] ) ||
			true !== $data['success'] ||
			'od-monitor-agent' !== $data['agent']['slug'] ||
			! is_string( $data['agent']['version'] )
		) {
			return $this->invalid_response();
		}

		return true;
	}

	/**
	 * Validate a status response.
	 *
	 * @param mixed $data Decoded response data.
	 * @return true|WP_Error
	 */
	public function validate_status( $data ) {
		$base = $this->validate_base( $data );

		if ( is_wp_error( $base ) ) {
			return $base;
		}

		$valid = isset(
			$data['site']['url'],
			$data['site']['home_url'],
			$data['site']['name'],
			$data['wordpress']['version'],
			$data['wordpress']['multisite'],
			$data['wordpress']['environment'],
			$data['server']['php_version'],
			$data['agent']['version']
		);

		if ( ! $valid ) {
			return $this->invalid_response();
		}

		if (
			! is_string( $data['site']['url'] ) ||
			! is_string( $data['site']['home_url'] ) ||
			! is_string( $data['site']['name'] ) ||
			! is_string( $data['wordpress']['version'] ) ||
			! is_bool( $data['wordpress']['multisite'] ) ||
			! is_string( $data['wordpress']['environment'] ) ||
			! is_string( $data['server']['php_version'] ) ||
			! is_string( $data['agent']['version'] )
		) {
			return $this->invalid_response();
		}

		return true;
	}

	/**
	 * Validate an updates response.
	 *
	 * @param mixed $data Decoded response data.
	 * @return true|WP_Error
	 */
	public function validate_updates( $data ) {
		$base = $this->validate_base( $data );

		if ( is_wp_error( $base ) ) {
			return $base;
		}

		if (
			! isset( $data['wordpress'], $data['plugins'], $data['themes'], $data['summary'] ) ||
			! $this->has_only_keys( $data, array( 'schema_version', 'wordpress', 'plugins', 'themes', 'summary', 'timestamp' ) ) ||
			! $this->valid_update_item( $data['wordpress'], null, true ) ||
			! $this->has_only_keys( $data['wordpress'], array( 'current_version', 'latest_version', 'update_available' ) ) ||
			! is_array( $data['plugins'] ) ||
			! array_is_list( $data['plugins'] ) ||
			! is_array( $data['themes'] ) ||
			! array_is_list( $data['themes'] )
		) {
			return $this->invalid_response();
		}

		foreach ( $data['plugins'] as $plugin ) {
			if ( ! $this->valid_update_item( $plugin, 'file' ) || ! $this->has_only_keys( $plugin, array( 'file', 'name', 'current_version', 'latest_version', 'update_available', 'active' ) ) || ! isset( $plugin['name'], $plugin['active'] ) || ! is_string( $plugin['name'] ) || ! is_bool( $plugin['active'] ) ) {
				return $this->invalid_response();
			}
		}

		foreach ( $data['themes'] as $theme ) {
			if ( ! $this->valid_update_item( $theme, 'stylesheet' ) || ! $this->has_only_keys( $theme, array( 'stylesheet', 'name', 'current_version', 'latest_version', 'update_available', 'active' ) ) || ! isset( $theme['name'], $theme['active'] ) || ! is_string( $theme['name'] ) || ! is_bool( $theme['active'] ) ) {
				return $this->invalid_response();
			}
		}

		if (
			! isset( $data['summary']['wordpress'], $data['summary']['plugins'], $data['summary']['themes'], $data['summary']['total'] ) ||
			! $this->has_only_keys( $data['summary'], array( 'wordpress', 'plugins', 'themes', 'total' ) ) ||
			! is_int( $data['summary']['wordpress'] ) ||
			! is_int( $data['summary']['plugins'] ) ||
			! is_int( $data['summary']['themes'] ) ||
			! is_int( $data['summary']['total'] ) ||
			$data['summary']['wordpress'] < 0 ||
			$data['summary']['wordpress'] > 1 ||
			$data['summary']['plugins'] < 0 ||
			$data['summary']['themes'] < 0 ||
			$data['summary']['total'] < 0 ||
			( $data['wordpress']['update_available'] ? 1 : 0 ) !== $data['summary']['wordpress'] ||
			count( array_filter( $data['plugins'], static fn( array $plugin ): bool => $plugin['update_available'] ) ) !== $data['summary']['plugins'] ||
			count( array_filter( $data['themes'], static fn( array $theme ): bool => $theme['update_available'] ) ) !== $data['summary']['themes'] ||
			array_sum( array_intersect_key( $data['summary'], array_flip( array( 'wordpress', 'plugins', 'themes' ) ) ) ) !== $data['summary']['total']
		) {
			return $this->invalid_response();
		}

		return true;
	}

	/**
	 * Validate fields shared by core, plugin, and theme update items.
	 *
	 * @param mixed       $item           Update item.
	 * @param string|null $identifier_key Optional identifier key.
	 */
	private function valid_update_item( $item, ?string $identifier_key, bool $require_version = false ): bool {
		if (
			! is_array( $item ) ||
			! isset( $item['current_version'], $item['latest_version'], $item['update_available'] ) ||
			! is_string( $item['current_version'] ) ||
			! is_string( $item['latest_version'] ) ||
			( $require_version && ( '' === $item['current_version'] || '' === $item['latest_version'] ) ) ||
			! is_bool( $item['update_available'] ) ||
			( version_compare( $item['latest_version'], $item['current_version'], '>' ) !== $item['update_available'] )
		) {
			return false;
		}

		return null === $identifier_key || ( isset( $item[ $identifier_key ] ) && is_string( $item[ $identifier_key ] ) && '' !== $item[ $identifier_key ] );
	}

	/**
	 * Determine whether an object contains no fields outside the contract.
	 *
	 * @param array<string,mixed> $data         Response object.
	 * @param list<string>        $allowed_keys Allowed keys.
	 */
	private function has_only_keys( array $data, array $allowed_keys ): bool {
		return array() === array_diff( array_keys( $data ), $allowed_keys );
	}

	/**
	 * Validate fields common to all responses.
	 *
	 * @param mixed $data Decoded response data.
	 * @return true|WP_Error
	 */
	private function validate_base( $data ) {
		if ( ! is_array( $data ) || ! isset( $data['schema_version'], $data['timestamp'] ) ) {
			return $this->invalid_response();
		}

		if ( ! is_string( $data['schema_version'] ) ) {
			return $this->invalid_response();
		}

		if ( self::SUPPORTED_SCHEMA_VERSION !== $data['schema_version'] ) {
			return new WP_Error( ErrorCode::UNSUPPORTED_SCHEMA, __( 'The Agent protocol version is not supported.', 'od-wordpress-monitor' ) );
		}

		if ( ! is_string( $data['timestamp'] ) || false === strtotime( $data['timestamp'] ) ) {
			return $this->invalid_response();
		}

		return true;
	}

	private function invalid_response(): WP_Error {
		return new WP_Error( ErrorCode::INVALID_RESPONSE, __( 'The Agent returned an unexpected response.', 'od-wordpress-monitor' ) );
	}
}
