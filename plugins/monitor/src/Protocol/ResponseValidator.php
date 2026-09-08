<?php
/**
 * Agent protocol response validation.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Protocol;

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
			return new WP_Error( 'UNSUPPORTED_SCHEMA', __( 'The Agent protocol version is not supported.', 'od-wordpress-monitor' ) );
		}

		if ( ! is_string( $data['timestamp'] ) || false === strtotime( $data['timestamp'] ) ) {
			return $this->invalid_response();
		}

		return true;
	}

	private function invalid_response(): WP_Error {
		return new WP_Error( 'INVALID_RESPONSE', __( 'The Agent returned an unexpected response.', 'od-wordpress-monitor' ) );
	}
}
