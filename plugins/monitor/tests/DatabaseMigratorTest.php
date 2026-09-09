<?php
/**
 * Database migration tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use Olein\WordPressMonitor\Activation\DatabaseMigrator;
use Olein\WordPressMonitor\Plugin;

final class DatabaseMigratorTest extends \WP_UnitTestCase {
	public function test_migrates_phase_one_schema_and_preserves_existing_data(): void {
		global $wpdb;

		$migrator = new DatabaseMigrator( $wpdb );
		$migrator->migrate();
		$result = $wpdb->insert(
			$wpdb->prefix . 'odm_sites',
			array(
				'uuid'       => wp_generate_uuid4(),
				'name'       => 'Existing site',
				'site_url'   => 'https://example.com',
				'agent_url'  => 'https://example.com/agent',
				'enabled'    => 1,
				'created_at' => current_time( 'mysql', true ),
				'updated_at' => current_time( 'mysql', true ),
			)
		);
		$this->assertNotFalse( $result );
		$site_id = (int) $wpdb->insert_id;

		foreach ( array( 'odm_site_status', 'odm_checks', 'odm_events' ) as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
		}
		update_option( DatabaseMigrator::VERSION_OPTION, '1.0.0' );

		$migrator->migrate();

		$this->assertSame( DatabaseMigrator::VERSION, get_option( DatabaseMigrator::VERSION_OPTION ) );
		$this->assertSame( 'Existing site', $wpdb->get_var( "SELECT name FROM {$wpdb->prefix}odm_sites ORDER BY id DESC LIMIT 1" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( array( 'odm_site_status', 'odm_checks', 'odm_events' ) as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		$migrator->migrate();
		$this->assertSame( 'Existing site', $wpdb->get_var( "SELECT name FROM {$wpdb->prefix}odm_sites ORDER BY id DESC LIMIT 1" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->prefix . 'odm_sites', array( 'id' => $site_id ), array( '%d' ) );
	}

	public function test_admin_upgrade_path_runs_the_current_migration(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'odm_events';
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
		update_option( DatabaseMigrator::VERSION_OPTION, '1.0.0' );

		( new Plugin() )->maybe_upgrade_database();

		$this->assertSame( DatabaseMigrator::VERSION, get_option( DatabaseMigrator::VERSION_OPTION ) );
		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
