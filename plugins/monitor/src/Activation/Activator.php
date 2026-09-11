<?php
/**
 * Monitor activation.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Activation;

use Olein\WordPressMonitor\Scheduler\Scheduler;

final class Activator {
	/**
	 * Install the database schema.
	 */
	public static function activate(): bool {
		global $wpdb;

		$result = ( new DatabaseMigrator( $wpdb ) )->migrate();

		if ( is_wp_error( $result ) ) {
			return false;
		}

		add_filter( 'cron_schedules', array( Scheduler::class, 'add_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- Intervals are defined by Scheduler::add_schedules().
		Scheduler::ensure_scheduled();

		return true;
	}

	/**
	 * Remove the plugin-owned schedules.
	 */
	public static function deactivate(): void {
		Scheduler::clear_scheduled();
	}
}
