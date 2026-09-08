<?php
/**
 * Monitor database schema migration.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Activation;

use wpdb;

final class DatabaseMigrator {
	public const VERSION        = '1.0.0';
	public const VERSION_OPTION = 'odm_db_version';

	public function __construct( private readonly wpdb $database ) {
	}

	/**
	 * Create or update the Phase 1 tables.
	 */
	public function migrate(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate   = $this->database->get_charset_collate();
		$sites_table       = $this->database->prefix . 'odm_sites';
		$credentials_table = $this->database->prefix . 'odm_credentials';

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

		dbDelta( $sites_sql );
		dbDelta( $credentials_sql );
		update_option( self::VERSION_OPTION, self::VERSION );
	}
}
