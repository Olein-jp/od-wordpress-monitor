<?php
/**
 * Database migration tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use Olein\WordPressMonitor\Activation\DatabaseMigrator;
use Olein\WordPressMonitor\Plugin;
use WP_Error;

final class DatabaseMigratorTest extends \WP_UnitTestCase {
	public function tear_down(): void {
		global $wpdb;

		delete_option( DatabaseMigrator::VERSION_OPTION );
		delete_option( DatabaseMigrator::STATUS_OPTION );
		delete_option( DatabaseMigrator::LOCK_OPTION );
		( new DatabaseMigrator( $wpdb ) )->migrate();
		delete_option( DatabaseMigrator::STATUS_OPTION );
		delete_option( DatabaseMigrator::LOCK_OPTION );

		parent::tear_down();
	}

	public function test_new_install_creates_and_verifies_every_table_before_recording_version(): void {
		global $wpdb;

		$this->drop_monitor_tables();
		delete_option( DatabaseMigrator::VERSION_OPTION );

		$result = ( new DatabaseMigrator( $wpdb ) )->migrate();
		$status = get_option( DatabaseMigrator::STATUS_OPTION );

		$this->assertTrue( $result );
		$this->assertSame( DatabaseMigrator::VERSION, get_option( DatabaseMigrator::VERSION_OPTION ) );
		$this->assertIsArray( $status );
		$this->assertSame( 'success', $status['status'] );
		$this->assertSame( DatabaseMigrator::VERSION, $status['target_version'] );
		$this->assertFalse( get_option( DatabaseMigrator::LOCK_OPTION, false ) );

		foreach ( $this->monitor_tables() as $table ) {
			$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}
	}

	public function test_migrates_old_schema_and_preserves_existing_data(): void {
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

		$this->assertTrue( $migrator->migrate() );
		$this->assertSame( DatabaseMigrator::VERSION, get_option( DatabaseMigrator::VERSION_OPTION ) );
		$this->assertSame( 'Existing site', $wpdb->get_var( "SELECT name FROM {$wpdb->prefix}odm_sites ORDER BY id DESC LIMIT 1" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( array( 'odm_site_status', 'odm_checks', 'odm_events' ) as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		$wpdb->delete( $wpdb->prefix . 'odm_sites', array( 'id' => $site_id ), array( '%d' ) );
	}

	public function test_current_schema_does_not_run_database_updates_again(): void {
		global $wpdb;

		delete_option( DatabaseMigrator::VERSION_OPTION );
		$this->assertTrue( ( new DatabaseMigrator( $wpdb ) )->migrate() );

		$update_count = 0;
		$migrator     = new DatabaseMigrator(
			$wpdb,
			static function () use ( &$update_count ): WP_Error {
				++$update_count;

				return new WP_Error( 'unexpected_schema_update' );
			}
		);

		$this->assertTrue( $migrator->migrate() );
		$this->assertSame( 0, $update_count );
	}

	public function test_partial_failure_keeps_old_version_and_can_be_retried_safely(): void {
		global $wpdb;

		update_option( DatabaseMigrator::VERSION_OPTION, '1.0.0' );
		$update_count = 0;
		$migrator     = new DatabaseMigrator(
			$wpdb,
			static function () use ( &$update_count ): bool|WP_Error {
				++$update_count;

				if ( 3 === $update_count ) {
					return new WP_Error( 'simulated_schema_failure', 'Sensitive database detail.' );
				}

				return true;
			}
		);

		$result = $migrator->migrate();
		$status = get_option( DatabaseMigrator::STATUS_OPTION );

		$this->assertWPError( $result );
		$this->assertSame( '1.0.0', get_option( DatabaseMigrator::VERSION_OPTION ) );
		$this->assertIsArray( $status );
		$this->assertSame( 'failed', $status['status'] );
		$this->assertSame( 'simulated_schema_failure', $status['error_code'] );
		$this->assertFalse( get_option( DatabaseMigrator::LOCK_OPTION, false ) );

		$this->assertTrue( ( new DatabaseMigrator( $wpdb ) )->migrate() );
		$this->assertSame( DatabaseMigrator::VERSION, get_option( DatabaseMigrator::VERSION_OPTION ) );
		$this->assertSame( 'success', get_option( DatabaseMigrator::STATUS_OPTION )['status'] );
	}

	public function test_verification_failure_does_not_record_the_target_version(): void {
		global $wpdb;

		$database         = clone $wpdb;
		$database->prefix = $wpdb->prefix . 'missing_';
		update_option( DatabaseMigrator::VERSION_OPTION, '1.0.0' );
		$migrator = new DatabaseMigrator(
			$database,
			static fn (): bool => true
		);

		$result = $migrator->migrate();

		$this->assertWPError( $result );
		$this->assertSame( 'odm_database_table_missing', $result->get_error_code() );
		$this->assertSame( '1.0.0', get_option( DatabaseMigrator::VERSION_OPTION ) );
	}

	public function test_active_lock_pauses_this_request_without_overwriting_version(): void {
		global $wpdb;

		update_option( DatabaseMigrator::VERSION_OPTION, '1.0.0' );
		$lock = ( time() + 300 ) . ':another-request';
		add_option( DatabaseMigrator::LOCK_OPTION, $lock, '', false );

		$result = ( new DatabaseMigrator( $wpdb ) )->migrate();

		$this->assertWPError( $result );
		$this->assertSame( 'odm_database_migration_in_progress', $result->get_error_code() );
		$this->assertSame( '1.0.0', get_option( DatabaseMigrator::VERSION_OPTION ) );
		$this->assertSame( $lock, get_option( DatabaseMigrator::LOCK_OPTION ) );
	}

	public function test_expired_lock_is_replaced_and_migration_completes(): void {
		global $wpdb;

		update_option( DatabaseMigrator::VERSION_OPTION, '1.0.0' );
		add_option( DatabaseMigrator::LOCK_OPTION, '900:expired', '', false );
		$migrator = new DatabaseMigrator( $wpdb, null, static fn (): int => 1000 );

		$this->assertTrue( $migrator->migrate() );
		$this->assertSame( DatabaseMigrator::VERSION, get_option( DatabaseMigrator::VERSION_OPTION ) );
		$this->assertFalse( get_option( DatabaseMigrator::LOCK_OPTION, false ) );
	}

	public function test_normal_boot_hook_runs_the_current_migration(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'odm_events';
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
		update_option( DatabaseMigrator::VERSION_OPTION, '1.0.0' );
		$plugin = new Plugin();
		$plugin->register_hooks();

		$this->assertSame( 0, has_action( 'plugins_loaded', array( $plugin, 'maybe_upgrade_database' ) ) );
		$this->assertFalse( has_action( 'admin_init', array( $plugin, 'maybe_upgrade_database' ) ) );
		$this->assertTrue( $plugin->maybe_upgrade_database() );
		$this->assertSame( DatabaseMigrator::VERSION, get_option( DatabaseMigrator::VERSION_OPTION ) );
		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		remove_filter( 'cron_schedules', array( \Olein\WordPressMonitor\Scheduler\Scheduler::class, 'add_schedules' ) );
		remove_action( 'plugins_loaded', array( $plugin, 'maybe_upgrade_database' ), 0 );
		remove_action( 'init', array( $plugin, 'register_runtime_hooks' ), 0 );
	}

	public function test_failure_registers_a_safe_admin_notice_and_retry_succeeds(): void {
		global $wpdb;

		update_option( DatabaseMigrator::VERSION_OPTION, '1.0.0' );
		$should_fail = true;
		$plugin      = new Plugin(
			static function ( $database ) use ( &$should_fail ): DatabaseMigrator {
				return new DatabaseMigrator(
					$database,
					static function () use ( &$should_fail ): bool|WP_Error {
						if ( $should_fail ) {
							$should_fail = false;

							return new WP_Error( 'simulated_schema_failure', 'password=secret' );
						}

						return true;
					}
				);
			}
		);

		$this->assertFalse( $plugin->maybe_upgrade_database() );
		$this->assertSame( 10, has_action( 'admin_notices', array( $plugin, 'database_migration_notice' ) ) );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		ob_start();
		$plugin->database_migration_notice();
		$notice = (string) ob_get_clean();

		$this->assertStringContainsString( 'Monitoring is paused', $notice );
		$this->assertStringNotContainsString( 'password=secret', $notice );
		$this->assertTrue( $plugin->maybe_upgrade_database() );
		$this->assertSame( DatabaseMigrator::VERSION, get_option( DatabaseMigrator::VERSION_OPTION ) );
		$this->assertFalse( has_action( 'admin_notices', array( $plugin, 'database_migration_notice' ) ) );

		remove_action( 'admin_notices', array( $plugin, 'database_migration_notice' ) );
	}

	/**
	 * @return array<int, string>
	 */
	private function monitor_tables(): array {
		global $wpdb;

		return array(
			$wpdb->prefix . 'odm_sites',
			$wpdb->prefix . 'odm_credentials',
			$wpdb->prefix . 'odm_site_status',
			$wpdb->prefix . 'odm_checks',
			$wpdb->prefix . 'odm_events',
		);
	}

	private function drop_monitor_tables(): void {
		global $wpdb;

		foreach ( $this->monitor_tables() as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
		}
	}
}
