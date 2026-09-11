<?php
/**
 * 安全なアンインストール、再インストール、復元のテスト。
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use Olein\WordPressMonitor\Activation\Activator;
use Olein\WordPressMonitor\Activation\DatabaseMigrator;
use Olein\WordPressMonitor\Credential\Credential;
use Olein\WordPressMonitor\Credential\CredentialEncryptor;
use Olein\WordPressMonitor\Credential\CredentialRepository;
use Olein\WordPressMonitor\Credential\CredentialService;
use Olein\WordPressMonitor\Http\AgentClient;
use Olein\WordPressMonitor\Http\HttpClient;
use Olein\WordPressMonitor\Http\UrlValidator;
use Olein\WordPressMonitor\Notification\NotificationSettings;
use Olein\WordPressMonitor\Protocol\ResponseValidator;
use Olein\WordPressMonitor\Scheduler\Scheduler;
use Olein\WordPressMonitor\Scheduler\SchedulerHeartbeat;
use Olein\WordPressMonitor\Site\Site;
use Olein\WordPressMonitor\Site\SiteRepository;
use Olein\WordPressMonitor\Site\SiteService;
use Olein\WordPressMonitor\Support\UUID;

final class UninstallRetentionTest extends \WP_UnitTestCase {
	private const APPLICATION_PASSWORD = 'issue-28-application-password';
	private const BATCH_OPTION         = 'odm_batch_state_issue_28';
	private const LOCK_OPTION          = 'odm_lock_issue_28_http';
	private const UNRELATED_OPTION     = 'issue_28_unrelated_option';
	private const STATUS_TRANSIENT     = 'odm_status_issue_28';

	private int $site_id;
	private ?string $site_uuid = null;
	private int $unrelated_post_id;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;

		( new DatabaseMigrator( $wpdb ) )->migrate();
		$this->clear_test_data();

		$sites           = new SiteRepository( $wpdb );
		$this->site_uuid = wp_generate_uuid4();
		$site_id         = $sites->create(
			new Site(
				null,
				$this->site_uuid,
				'Restored site',
				'https://example.com',
				'https://example.com/wp-json/od-monitor-agent/v1'
			)
		);
		$this->assertIsInt( $site_id );
		$this->site_id = $site_id;

		$credentials = new CredentialService( new CredentialRepository( $wpdb ), new CredentialEncryptor() );
		$this->assertIsInt( $credentials->store( $this->site_id, new Credential( 'agent-user', self::APPLICATION_PASSWORD ) ) );

		$now = current_time( 'mysql', true );
		$this->assertNotFalse(
			$wpdb->insert(
				$wpdb->prefix . 'odm_site_status',
				array(
					'site_id'      => $this->site_id,
					'last_message' => 'Retained current status',
					'updated_at'   => $now,
				)
			)
		);
		$this->assertNotFalse(
			$wpdb->insert(
				$wpdb->prefix . 'odm_checks',
				array(
					'site_id'     => $this->site_id,
					'check_type'  => 'http',
					'status'      => 'healthy',
					'message'     => 'Retained check',
					'started_at'  => $now,
					'finished_at' => $now,
					'checked_at'  => $now,
				)
			)
		);
		$this->assertNotFalse(
			$wpdb->insert(
				$wpdb->prefix . 'odm_events',
				array(
					'site_id'         => $this->site_id,
					'event_type'      => 'recovery',
					'previous_status' => 'critical',
					'current_status'  => 'healthy',
					'message'         => 'Retained event',
					'occurred_at'     => $now,
					'created_at'      => $now,
				)
			)
		);

		update_option(
			NotificationSettings::OPTION,
			array(
				'enabled' => '1',
				'email'   => 'alerts@example.com',
			),
			false
		);
		update_option( SchedulerHeartbeat::OPTION, array( 'http' => array( 'result' => 'success' ) ), false );
		update_option( DatabaseMigrator::STATUS_OPTION, array( 'status' => 'success' ), false );
		update_option( self::BATCH_OPTION, array( 'cursor' => $this->site_id ), false );
		update_option( self::LOCK_OPTION, ( time() + MINUTE_IN_SECONDS ) . ':owner', false );
		update_option( self::UNRELATED_OPTION, 'must remain untouched', false );
		set_transient( self::STATUS_TRANSIENT, array( 'success' => true ), DAY_IN_SECONDS );
		$this->unrelated_post_id = self::factory()->post->create( array( 'post_title' => 'Unrelated content' ) );
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		remove_filter( 'cron_schedules', array( Scheduler::class, 'add_schedules' ) );
		Scheduler::clear_scheduled();
		$this->clear_test_data();
		parent::tear_down();
	}

	public function test_uninstall_makes_no_database_or_output_changes(): void {
		global $wpdb;

		$output = $this->run_uninstall();

		$this->assertSame( '', $output );
		$this->assertStringNotContainsString( self::APPLICATION_PASSWORD, $output );
		foreach ( $this->monitor_tables() as $table ) {
			$site_column = str_ends_with( $table, 'odm_sites' ) ? 'id' : 'site_id';
			$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE {$site_column} = %d", $this->site_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		$this->assertSame( DatabaseMigrator::VERSION, get_option( DatabaseMigrator::VERSION_OPTION ) );
		$this->assertSame( 'success', get_option( DatabaseMigrator::STATUS_OPTION )['status'] );
		$this->assertSame( 'alerts@example.com', get_option( NotificationSettings::OPTION )['email'] );
		$this->assertSame( 'success', get_option( SchedulerHeartbeat::OPTION )['http']['result'] );
		$this->assertSame( $this->site_id, get_option( self::BATCH_OPTION )['cursor'] );
		$this->assertStringEndsWith( ':owner', get_option( self::LOCK_OPTION ) );
		$this->assertSame( array( 'success' => true ), get_transient( self::STATUS_TRANSIENT ) );
		$this->assertSame( 'must remain untouched', get_option( self::UNRELATED_OPTION ) );
		$this->assertSame( 'Unrelated content', get_post( $this->unrelated_post_id )->post_title );

		$stored = ( new CredentialRepository( $wpdb ) )->find_by_site( $this->site_id );
		$this->assertIsArray( $stored );
		$this->assertStringNotContainsString( self::APPLICATION_PASSWORD, $stored['encrypted_password'] );
	}

	public function test_reinstall_after_retained_restore_migrates_and_can_test_connection(): void {
		global $wpdb;

		$this->assertSame( '', $this->run_uninstall() );
		Activator::deactivate();
		update_option( DatabaseMigrator::VERSION_OPTION, '1.0.0' );

		$this->assertTrue( Activator::activate() );
		$this->assertSame( DatabaseMigrator::VERSION, get_option( DatabaseMigrator::VERSION_OPTION ) );
		$this->assertNotNull( ( new SiteRepository( $wpdb ) )->find( $this->site_id ) );

		$request_count = 0;
		add_filter(
			'pre_http_request',
			function ( $preempt, array $arguments, string $url ) use ( &$request_count ): array {
				unset( $preempt, $arguments );
				++$request_count;
				$body = str_ends_with( $url, '/ping' ) ? $this->valid_ping() : $this->valid_status();

				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( $body ),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);

		$validator = new UrlValidator( static fn(): array => array( '93.184.216.34' ) );
		$service   = new SiteService(
			new SiteRepository( $wpdb ),
			new CredentialService( new CredentialRepository( $wpdb ), new CredentialEncryptor() ),
			new AgentClient( new HttpClient( $validator ), new ResponseValidator() ),
			new UUID(),
			$validator
		);
		$result    = $service->test_connection( $this->site_id );

		$this->assertIsArray( $result );
		$this->assertSame( '6.8', $result['wordpress']['version'] );
		$this->assertSame( 2, $request_count );
	}

	private function run_uninstall(): string {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'monitor/od-wordpress-monitor.php' );
		}

		ob_start();
		require dirname( __DIR__ ) . '/uninstall.php';

		return (string) ob_get_clean();
	}

	/**
	 * アンインストール後も保持し、バックアップを必要とする全テーブル。
	 *
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

	private function clear_test_data(): void {
		global $wpdb;

		foreach ( array_reverse( $this->monitor_tables() ) as $table ) {
			$wpdb->query( "DELETE FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		delete_option( NotificationSettings::OPTION );
		delete_option( SchedulerHeartbeat::OPTION );
		delete_option( DatabaseMigrator::STATUS_OPTION );
		delete_option( self::BATCH_OPTION );
		delete_option( self::LOCK_OPTION );
		delete_option( self::UNRELATED_OPTION );
		delete_transient( self::STATUS_TRANSIENT );
		if ( null !== $this->site_uuid ) {
			delete_transient( 'odm_status_' . $this->site_uuid );
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private function valid_ping(): array {
		return array(
			'schema_version' => '1.0',
			'success'        => true,
			'agent'          => array(
				'slug'    => 'od-monitor-agent',
				'version' => '1.0.1',
			),
			'timestamp'      => '2026-09-11T00:00:00Z',
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function valid_status(): array {
		return array(
			'schema_version' => '1.0',
			'site'           => array(
				'url'      => 'https://example.com',
				'home_url' => 'https://example.com',
				'name'     => 'Restored site',
			),
			'wordpress'      => array(
				'version'     => '6.8',
				'multisite'   => false,
				'environment' => 'production',
			),
			'server'         => array( 'php_version' => '8.1' ),
			'agent'          => array( 'version' => '1.0.1' ),
			'timestamp'      => '2026-09-11T00:00:00Z',
		);
	}
}
