<?php
/**
 * Monitor database schema migration.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Activation;

use wpdb;

final class DatabaseMigrator {
	public const VERSION        = '2.0.0';
	public const VERSION_OPTION = 'odm_db_version';

	public function __construct( private readonly wpdb $database ) {
	}

	/**
	 * Create or update the monitor tables.
	 */
	public function migrate(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate   = $this->database->get_charset_collate();
		$sites_table       = $this->database->prefix . 'odm_sites';
		$credentials_table = $this->database->prefix . 'odm_credentials';
		$site_status_table = $this->database->prefix . 'odm_site_status';
		$checks_table      = $this->database->prefix . 'odm_checks';
		$events_table      = $this->database->prefix . 'odm_events';

		$sites_sql = "CREATE TABLE {$sites_table} (
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
		) {$charset_collate};";

		$credentials_sql = "CREATE TABLE {$credentials_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			site_id bigint(20) unsigned NOT NULL,
			username varchar(255) NOT NULL,
			encrypted_password longtext NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY site_id (site_id)
		) {$charset_collate};";

		$site_status_sql = "CREATE TABLE {$site_status_table} (
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
		) {$charset_collate};";

		$checks_sql = "CREATE TABLE {$checks_table} (
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
		) {$charset_collate};";

		$events_sql = "CREATE TABLE {$events_table} (
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
		) {$charset_collate};";

		dbDelta( $sites_sql );
		dbDelta( $credentials_sql );
		dbDelta( $site_status_sql );
		dbDelta( $checks_sql );
		dbDelta( $events_sql );
		update_option( self::VERSION_OPTION, self::VERSION );
	}
}
