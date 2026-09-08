<?php
/**
 * Monitor activation.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Activation;

final class Activator {
	/**
	 * Install the database schema.
	 */
	public static function activate(): void {
		global $wpdb;

		( new DatabaseMigrator( $wpdb ) )->migrate();
	}
}
