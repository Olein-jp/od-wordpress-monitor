<?php
/**
 * Monitor database schema migration.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Activation;

use Closure;
use Throwable;
use WP_Error;
use wpdb;

final class DatabaseMigrator {
	public const VERSION        = '2.0.0';
	public const VERSION_OPTION = 'odm_db_version';
	public const STATUS_OPTION  = 'odm_db_migration_status';
	public const LOCK_OPTION    = 'odm_db_migration_lock';

	private const LOCK_TTL = 300;

	/**
	 * @param Closure|null $schema_updater Optional schema updater for tests.
	 * @param Closure|null $clock          Optional Unix timestamp provider for tests.
	 */
	public function __construct(
		private readonly wpdb $database,
		private readonly ?Closure $schema_updater = null,
		private readonly ?Closure $clock = null
	) {
	}

	/**
	 * Create or update the monitor tables when the stored schema is outdated.
	 *
	 * The version is updated only after every table has been applied and verified.
	 *
	 * @return true|WP_Error
	 */
	public function migrate(): bool|WP_Error {
		$previous_version = $this->stored_version();

		if ( $this->is_current( $previous_version ) ) {
			return true;
		}

		$lock_token = $this->acquire_lock();

		if ( is_wp_error( $lock_token ) ) {
			return $lock_token;
		}

		if ( null === $lock_token ) {
			return new WP_Error(
				'odm_database_migration_in_progress',
				__( 'A database migration is already in progress.', 'od-wordpress-monitor' )
			);
		}

		try {
			$this->save_status( 'running', $previous_version );

			foreach ( $this->schema_definitions() as $definition ) {
				$result = $this->apply_schema( $definition['sql'], $definition['table'] );

				if ( is_wp_error( $result ) ) {
					return $this->fail( $result, $previous_version );
				}
			}

			$verification = $this->verify_schema();

			if ( is_wp_error( $verification ) ) {
				return $this->fail( $verification, $previous_version );
			}

			$version_updated = update_option( self::VERSION_OPTION, self::VERSION );

			if ( ! $version_updated && self::VERSION !== get_option( self::VERSION_OPTION ) ) {
				return $this->fail(
					new WP_Error(
						'odm_database_version_update_failed',
						__( 'The database schema version could not be saved.', 'od-wordpress-monitor' )
					),
					$previous_version
				);
			}

			$this->save_status( 'success', $previous_version, $this->now() );

			return true;
		} catch ( Throwable ) {
			return $this->fail(
				new WP_Error(
					'odm_database_migration_exception',
					__( 'The database migration stopped unexpectedly.', 'od-wordpress-monitor' )
				),
				$previous_version
			);
		} finally {
			$this->release_lock( $lock_token );
		}
	}

	/**
	 * Determine whether the stored schema is compatible with this plugin version.
	 */
	private function is_current( string $stored_version ): bool {
		return '' !== $stored_version && version_compare( $stored_version, self::VERSION, '>=' );
	}

	private function stored_version(): string {
		$version = get_option( self::VERSION_OPTION, '' );

		return is_string( $version ) ? $version : '';
	}

	/**
	 * Apply one schema definition.
	 *
	 * @return true|WP_Error
	 */
	private function apply_schema( string $sql, string $table ): bool|WP_Error {
		if ( null !== $this->schema_updater ) {
			$result = ( $this->schema_updater )( $sql, $table );

			return is_wp_error( $result ) ? $result : true;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$this->database->last_error = '';
		dbDelta( $sql );

		if ( '' !== $this->database->last_error ) {
			return new WP_Error(
				'odm_database_schema_update_failed',
				__( 'A monitor database table could not be updated.', 'od-wordpress-monitor' )
			);
		}

		return true;
	}

	/**
	 * Verify every required table, column, and index before recording the version.
	 *
	 * @return true|WP_Error
	 */
	private function verify_schema(): bool|WP_Error {
		foreach ( $this->schema_definitions() as $definition ) {
			$table = $definition['table'];
			$found = $this->database->get_var(
				$this->database->prepare( 'SHOW TABLES LIKE %s', $this->database->esc_like( $table ) )
			);

			if ( $table !== $found ) {
				return new WP_Error(
					'odm_database_table_missing',
					__( 'A required monitor database table is missing.', 'od-wordpress-monitor' )
				);
			}

			// Table names are generated exclusively from the trusted WordPress prefix.
			$columns = $this->database->get_col( "SHOW COLUMNS FROM `{$table}`", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$indexes = $this->database->get_col( "SHOW INDEX FROM `{$table}`", 2 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( array_diff( $definition['columns'], $columns ) || array_diff( $definition['indexes'], array_unique( $indexes ) ) ) {
				return new WP_Error(
					'odm_database_schema_incomplete',
					__( 'A monitor database table is incomplete.', 'od-wordpress-monitor' )
				);
			}
		}

		return true;
	}

	/**
	 * Get every current schema definition and its verification contract.
	 *
	 * @return array<int, array{table: string, sql: string, columns: array<int, string>, indexes: array<int, string>}>
	 */
	private function schema_definitions(): array {
		$charset_collate   = $this->database->get_charset_collate();
		$sites_table       = $this->database->prefix . 'odm_sites';
		$credentials_table = $this->database->prefix . 'odm_credentials';
		$site_status_table = $this->database->prefix . 'odm_site_status';
		$checks_table      = $this->database->prefix . 'odm_checks';
		$events_table      = $this->database->prefix . 'odm_events';

		return array(
			array(
				'table'   => $sites_table,
				'columns' => array( 'id', 'uuid', 'name', 'site_url', 'agent_url', 'enabled', 'created_at', 'updated_at' ),
				'indexes' => array( 'PRIMARY', 'uuid' ),
				'sql'     => "CREATE TABLE {$sites_table} (
					id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					uuid char(36) NOT NULL,
					name varchar(255) NOT NULL,
					site_url varchar(2048) NOT NULL,
					agent_url varchar(2048) NOT NULL,
					enabled tinyint(1) NOT NULL DEFAULT 1,
					created_at datetime NOT NULL,
					updated_at datetime NOT NULL,
					PRIMARY KEY  (id),
					UNIQUE KEY uuid (uuid)
				) {$charset_collate};",
			),
			array(
				'table'   => $credentials_table,
				'columns' => array( 'id', 'site_id', 'username', 'encrypted_password', 'created_at', 'updated_at' ),
				'indexes' => array( 'PRIMARY', 'site_id' ),
				'sql'     => "CREATE TABLE {$credentials_table} (
					id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					site_id bigint(20) unsigned NOT NULL,
					username varchar(255) NOT NULL,
					encrypted_password longtext NOT NULL,
					created_at datetime NOT NULL,
					updated_at datetime NOT NULL,
					PRIMARY KEY  (id),
					UNIQUE KEY site_id (site_id)
				) {$charset_collate};",
			),
			array(
				'table'   => $site_status_table,
				'columns' => array( 'site_id', 'overall_status', 'http_status', 'http_checked_at', 'agent_status', 'agent_checked_at', 'updates_status', 'updates_checked_at', 'site_health_status', 'site_health_checked_at', 'ssl_status', 'ssl_checked_at', 'last_checked_at', 'last_error_code', 'last_message', 'metadata', 'updated_at' ),
				'indexes' => array( 'PRIMARY', 'overall_status', 'last_checked_at' ),
				'sql'     => "CREATE TABLE {$site_status_table} (
					site_id bigint(20) unsigned NOT NULL,
					overall_status varchar(32) NOT NULL DEFAULT 'unknown',
					http_status varchar(32) NOT NULL DEFAULT 'unknown',
					http_checked_at datetime NULL,
					agent_status varchar(32) NOT NULL DEFAULT 'unknown',
					agent_checked_at datetime NULL,
					updates_status varchar(32) NOT NULL DEFAULT 'unknown',
					updates_checked_at datetime NULL,
					site_health_status varchar(32) NOT NULL DEFAULT 'unknown',
					site_health_checked_at datetime NULL,
					ssl_status varchar(32) NOT NULL DEFAULT 'unknown',
					ssl_checked_at datetime NULL,
					last_checked_at datetime NULL,
					last_error_code varchar(191) NULL,
					last_message text NOT NULL,
					metadata longtext NULL,
					updated_at datetime NOT NULL,
					PRIMARY KEY  (site_id),
					KEY overall_status (overall_status),
					KEY last_checked_at (last_checked_at)
				) {$charset_collate};",
			),
			array(
				'table'   => $checks_table,
				'columns' => array( 'id', 'site_id', 'check_type', 'status', 'error_code', 'message', 'duration_ms', 'metadata', 'started_at', 'finished_at', 'checked_at' ),
				'indexes' => array( 'PRIMARY', 'site_checked_at', 'site_type_checked_at' ),
				'sql'     => "CREATE TABLE {$checks_table} (
					id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					site_id bigint(20) unsigned NOT NULL,
					check_type varchar(64) NOT NULL,
					status varchar(32) NOT NULL,
					error_code varchar(191) NULL,
					message text NOT NULL,
					duration_ms bigint(20) unsigned NOT NULL DEFAULT 0,
					metadata longtext NULL,
					started_at datetime NOT NULL,
					finished_at datetime NOT NULL,
					checked_at datetime NOT NULL,
					PRIMARY KEY  (id),
					KEY site_checked_at (site_id,checked_at),
					KEY site_type_checked_at (site_id,check_type,checked_at)
				) {$charset_collate};",
			),
			array(
				'table'   => $events_table,
				'columns' => array( 'id', 'site_id', 'event_type', 'previous_status', 'current_status', 'error_code', 'message', 'metadata', 'occurred_at', 'created_at' ),
				'indexes' => array( 'PRIMARY', 'site_occurred_at', 'event_type' ),
				'sql'     => "CREATE TABLE {$events_table} (
					id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					site_id bigint(20) unsigned NOT NULL,
					event_type varchar(64) NOT NULL,
					previous_status varchar(32) NOT NULL,
					current_status varchar(32) NOT NULL,
					error_code varchar(191) NULL,
					message text NOT NULL,
					metadata longtext NULL,
					occurred_at datetime NOT NULL,
					created_at datetime NOT NULL,
					PRIMARY KEY  (id),
					KEY site_occurred_at (site_id,occurred_at),
					KEY event_type (event_type)
				) {$charset_collate};",
			),
		);
	}

	private function fail( WP_Error $error, string $previous_version ): WP_Error {
		$this->save_status( 'failed', $previous_version, null, $error->get_error_code() );

		return $error;
	}

	private function save_status( string $status, string $previous_version, ?int $completed_at = null, string $error_code = '' ): void {
		$value = array(
			'status'           => $status,
			'previous_version' => $previous_version,
			'target_version'   => self::VERSION,
			'attempted_at'     => $this->now(),
		);

		if ( null !== $completed_at ) {
			$value['completed_at'] = $completed_at;
		}

		if ( '' !== $error_code ) {
			$value['error_code'] = sanitize_key( $error_code );
		}

		update_option( self::STATUS_OPTION, $value, false );
	}

	/**
	 * Acquire the migration lock.
	 *
	 * @return string|null|WP_Error Lock token, null when another request owns it, or an error.
	 */
	private function acquire_lock(): string|null|WP_Error {
		$now   = $this->now();
		$token = wp_generate_uuid4();
		$value = ( $now + self::LOCK_TTL ) . ':' . $token;

		if ( add_option( self::LOCK_OPTION, $value, '', false ) ) {
			return $token;
		}

		$existing = get_option( self::LOCK_OPTION, '' );
		$parts    = is_string( $existing ) ? explode( ':', $existing, 2 ) : array();

		if ( ! is_string( $existing ) || '' === $existing ) {
			return new WP_Error(
				'odm_database_migration_lock_failed',
				__( 'The database migration lock could not be saved.', 'od-wordpress-monitor' )
			);
		}

		if ( 2 === count( $parts ) && (int) $parts[0] > $now ) {
			return null;
		}

		$this->delete_lock_value( $existing );

		return add_option( self::LOCK_OPTION, $value, '', false ) ? $token : null;
	}

	private function release_lock( string $token ): void {
		$existing = get_option( self::LOCK_OPTION, '' );
		$parts    = is_string( $existing ) ? explode( ':', $existing, 2 ) : array();

		if ( 2 === count( $parts ) && hash_equals( $parts[1], $token ) ) {
			$this->delete_lock_value( $existing );
		}
	}

	/**
	 * Delete only the lock value that was observed, preserving a replacement lock.
	 */
	private function delete_lock_value( string $value ): void {
		$this->database->delete(
			$this->database->options,
			array(
				'option_name'  => self::LOCK_OPTION,
				'option_value' => $value,
			),
			array( '%s', '%s' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		wp_cache_delete( self::LOCK_OPTION, 'options' );
	}

	private function now(): int {
		return null === $this->clock ? time() : (int) ( $this->clock )();
	}
}
