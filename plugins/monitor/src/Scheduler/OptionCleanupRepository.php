<?php
/**
 * Bounded cleanup for expired lock and transient options.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Scheduler;

use WP_Error;
use wpdb;

final class OptionCleanupRepository {
	private const LOCK_PREFIX = 'odm_lock_';

	private const TRANSIENT_PREFIXES = array(
		'odm_status_',
		'odm_admin_notice_',
	);

	public function __construct( private readonly wpdb $database ) {
	}

	/**
	 * Delete expired or malformed execution locks without touching replacements.
	 *
	 * @return int|WP_Error Number of deleted locks or a safe database failure.
	 */
	public function delete_expired_locks( int $now, int $limit ): int|WP_Error {
		$like = $this->database->esc_like( self::LOCK_PREFIX ) . '%';
		$sql  = $this->database->prepare(
			"SELECT option_name, option_value FROM {$this->database->options} WHERE option_name LIKE %s AND CAST(SUBSTRING_INDEX(option_value, ':', 1) AS UNSIGNED) <= %d ORDER BY option_id ASC LIMIT %d",
			$like,
			$now,
			max( 1, $limit )
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The options table name comes from wpdb.
		$rows = $this->database->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! is_array( $rows ) || '' !== $this->database->last_error ) {
			return $this->database_error();
		}

		$deleted = 0;

		foreach ( $rows as $row ) {
			$result = $this->database->delete(
				$this->database->options,
				array(
					'option_name'  => (string) $row['option_name'],
					'option_value' => (string) $row['option_value'],
				),
				array( '%s', '%s' )
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

			if ( false === $result ) {
				return $this->database_error();
			}

			if ( 1 === $result ) {
				++$deleted;
				wp_cache_delete( (string) $row['option_name'], 'options' );
			}
		}

		return $deleted;
	}

	/**
	 * Delete expired plugin-owned transient pairs without touching replacements.
	 *
	 * @return int|WP_Error Number of deleted transient pairs or a safe database failure.
	 */
	public function delete_expired_transients( int $now, int $limit ): int|WP_Error {
		$timeout_prefix = '_transient_timeout_';
		$patterns       = array_map(
			fn( string $prefix ): string => $this->database->esc_like( $timeout_prefix . $prefix ) . '%',
			self::TRANSIENT_PREFIXES
		);
		$sql            = $this->database->prepare(
			"SELECT option_name, option_value FROM {$this->database->options} WHERE (option_name LIKE %s OR option_name LIKE %s) AND CAST(option_value AS UNSIGNED) < %d ORDER BY option_id ASC LIMIT %d",
			$patterns[0],
			$patterns[1],
			$now,
			max( 1, $limit )
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The options table name comes from wpdb.
		$rows           = $this->database->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! is_array( $rows ) || '' !== $this->database->last_error ) {
			return $this->database_error();
		}

		$deleted = 0;

		foreach ( $rows as $row ) {
			$timeout_name = (string) $row['option_name'];
			$value_name   = '_transient_' . substr( $timeout_name, strlen( $timeout_prefix ) );
			$value        = $this->raw_option_value( $value_name );

			if ( is_wp_error( $value ) ) {
				return $value;
			}

			$result = $this->database->delete(
				$this->database->options,
				array(
					'option_name'  => $timeout_name,
					'option_value' => (string) $row['option_value'],
				),
				array( '%s', '%s' )
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

			if ( false === $result ) {
				return $this->database_error();
			}

			if ( 1 !== $result ) {
				continue;
			}

			if ( null !== $value ) {
				$value_result = $this->database->delete(
					$this->database->options,
					array(
						'option_name'  => $value_name,
						'option_value' => $value,
					),
					array( '%s', '%s' )
				); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

				if ( false === $value_result ) {
					return $this->database_error();
				}
			}

			++$deleted;
			wp_cache_delete( $timeout_name, 'options' );
			wp_cache_delete( $value_name, 'options' );
		}

		return $deleted;
	}

	/**
	 * @return string|WP_Error|null
	 */
	private function raw_option_value( string $option_name ) {
		$sql   = $this->database->prepare( "SELECT option_value FROM {$this->database->options} WHERE option_name = %s", $option_name ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The options table name comes from wpdb.
		$value = $this->database->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( '' !== $this->database->last_error ) {
			return $this->database_error();
		}

		return null === $value ? null : (string) $value;
	}

	private function database_error(): WP_Error {
		return new WP_Error( 'DATABASE_ERROR', __( 'Expired temporary data could not be deleted.', 'od-wordpress-monitor' ) );
	}
}
