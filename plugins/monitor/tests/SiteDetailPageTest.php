<?php
/**
 * Monitored site detail page tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use DateTimeImmutable;
use Olein\WordPressMonitor\Activation\DatabaseMigrator;
use Olein\WordPressMonitor\Admin\SiteDetailPage;
use Olein\WordPressMonitor\Check\CheckRepository;
use Olein\WordPressMonitor\Credential\CredentialEncryptor;
use Olein\WordPressMonitor\Credential\CredentialRepository;
use Olein\WordPressMonitor\Credential\CredentialService;
use Olein\WordPressMonitor\Event\EventRepository;
use Olein\WordPressMonitor\Event\MonitoringEvent;
use Olein\WordPressMonitor\Http\AgentClient;
use Olein\WordPressMonitor\Http\HttpClient;
use Olein\WordPressMonitor\Monitor\CheckResult;
use Olein\WordPressMonitor\Protocol\ResponseValidator;
use Olein\WordPressMonitor\Site\Site;
use Olein\WordPressMonitor\Site\SiteRepository;
use Olein\WordPressMonitor\Site\SiteService;
use Olein\WordPressMonitor\Status\SiteStatus;
use Olein\WordPressMonitor\Status\SiteStatusRepository;
use Olein\WordPressMonitor\Support\UUID;

final class SiteDetailPageTest extends \WP_UnitTestCase {
	private SiteRepository $sites;
	private SiteStatusRepository $statuses;
	private CheckRepository $checks;
	private EventRepository $events;
	private SiteService $service;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;

		( new DatabaseMigrator( $wpdb ) )->migrate();
		$this->sites    = new SiteRepository( $wpdb );
		$this->statuses = new SiteStatusRepository( $wpdb );
		$this->checks   = new CheckRepository( $wpdb );
		$this->events   = new EventRepository( $wpdb );
		$this->service  = new SiteService(
			$this->sites,
			new CredentialService( new CredentialRepository( $wpdb ), new CredentialEncryptor() ),
			new AgentClient( new HttpClient(), new ResponseValidator() ),
			new UUID()
		);

		$wpdb->query( "DELETE FROM {$wpdb->prefix}odm_events" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}odm_checks" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}odm_site_status" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}odm_sites" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET = array();
	}

	public function tear_down(): void {
		$_GET = array();
		parent::tear_down();
	}

	public function test_renders_current_status_versions_and_check_specific_times(): void {
		$site_id = $this->create_site( 'Example Site' );
		$site    = $this->sites->find( $site_id );
		$this->assertTrue(
			$this->statuses->upsert(
				new SiteStatus(
					site_id: $site_id,
					overall_status: 'critical',
					http_status: 'healthy',
					http_checked_at: new DateTimeImmutable( '2026-09-10T10:01:00Z' ),
					agent_status: 'warning',
					agent_checked_at: new DateTimeImmutable( '2026-09-10T10:02:00Z' ),
					updates_status: 'critical',
					updates_checked_at: new DateTimeImmutable( '2026-09-10T10:03:00Z' ),
					site_health_status: 'unknown',
					site_health_checked_at: new DateTimeImmutable( '2026-09-10T10:04:00Z' ),
					ssl_status: 'healthy',
					ssl_checked_at: new DateTimeImmutable( '2026-09-10T10:05:00Z' ),
					last_checked_at: new DateTimeImmutable( '2026-09-10T10:06:00Z' )
				)
			)
		);
		set_transient(
			'odm_status_' . $site->uuid(),
			array(
				'wordpress' => array( 'version' => '6.9.1' ),
				'server'    => array( 'php_version' => '8.3.9' ),
			),
			DAY_IN_SECONDS
		);
		$_GET['site_id'] = (string) $site_id;

		$output = $this->render();

		$this->assertStringContainsString( '<h1>Example Site</h1>', $output );
		$this->assertStringContainsString( '>6.9.1</td>', $output );
		$this->assertStringContainsString( '>8.3.9</td>', $output );
		$this->assertMatchesRegularExpression( '/<th scope="row">HTTP<\/th>\s*<td>Healthy<\/td>\s*<td><time datetime="2026-09-10T10:01:00\+00:00">/s', $output );
		$this->assertMatchesRegularExpression( '/<th scope="row">Agent<\/th>\s*<td>Attention<\/td>\s*<td><time datetime="2026-09-10T10:02:00\+00:00">/s', $output );
		$this->assertMatchesRegularExpression( '/<th scope="row">WordPress<\/th>\s*<td>6\.9\.1<\/td>\s*<td><time datetime="2026-09-10T10:02:00\+00:00">/s', $output );
		$this->assertMatchesRegularExpression( '/<th scope="row">Updates<\/th>\s*<td>Problem<\/td>\s*<td><time datetime="2026-09-10T10:03:00\+00:00">/s', $output );
		$this->assertMatchesRegularExpression( '/<th scope="row">Site Health<\/th>\s*<td>Unknown<\/td>\s*<td><time datetime="2026-09-10T10:04:00\+00:00">/s', $output );
		$this->assertMatchesRegularExpression( '/<th scope="row">SSL<\/th>\s*<td>Healthy<\/td>\s*<td><time datetime="2026-09-10T10:05:00\+00:00">/s', $output );
		$this->assertMatchesRegularExpression( '/<th scope="row">Last checked<\/th>\s*<td>—<\/td>\s*<td><time datetime="2026-09-10T10:06:00\+00:00">/s', $output );
		$this->assertStringContainsString( 'Current monitoring status and check times', $output );
	}

	public function test_recent_events_and_checks_are_newest_first_and_limited_to_twenty(): void {
		$site_id = $this->create_site( 'History Site' );

		for ( $hour = 1; $hour <= 22; ++$hour ) {
			$time = new DateTimeImmutable( sprintf( '2026-09-10T%02d:00:00Z', $hour ) );
			$this->assertIsInt(
				$this->checks->create(
					new CheckResult( $site_id, 'http', 'healthy', null, sprintf( 'Check message %02d!', $hour ), $time, $time, $hour )
				)
			);
			$this->assertIsInt(
				$this->events->create(
					new MonitoringEvent( null, $site_id, 'RECOVERED', 'critical', 'healthy', null, sprintf( 'Event message %02d!', $hour ), $time )
				)
			);
		}
		$_GET['site_id'] = (string) $site_id;

		$output = $this->render();

		$this->assertSame( 20, substr_count( $output, 'Check message ' ) );
		$this->assertSame( 20, substr_count( $output, 'Event message ' ) );
		$this->assertStringNotContainsString( 'Check message 01!', $output );
		$this->assertStringNotContainsString( 'Check message 02!', $output );
		$this->assertStringNotContainsString( 'Event message 01!', $output );
		$this->assertStringNotContainsString( 'Event message 02!', $output );
		$this->assertLessThan( strpos( $output, 'Check message 03!' ), strpos( $output, 'Check message 22!' ) );
		$this->assertLessThan( strpos( $output, 'Event message 03!' ), strpos( $output, 'Event message 22!' ) );
	}

	public function test_renders_current_software_inventory_and_collection_time(): void {
		$site_id = $this->create_site( 'Inventory Site' );
		$this->assertTrue(
			$this->statuses->upsert(
				new SiteStatus(
					site_id: $site_id,
					metadata: array(
						'updates' => array(
							'software_inventory' => array(
								'theme'        => array(
									'id'               => 'snow-monkey',
									'name'             => 'Snow <script>Monkey</script>',
									'current_version'  => '31.0.2',
									'latest_version'   => '31.1.0',
									'update_available' => true,
								),
								'plugins'      => array(
									array(
										'id'               => 'alpha/alpha.php',
										'name'             => 'Alpha Plugin',
										'current_version'  => '1.2.3',
										'latest_version'   => '1.2.3',
										'update_available' => false,
									),
									array(
										'id'               => 'zulu/zulu.php',
										'name'             => 'Zulu Plugin',
										'current_version'  => '4.5.6',
										'update_available' => false,
									),
								),
								'collected_at' => '2026-09-10T09:30:00Z',
								'truncated'    => false,
							),
						),
					)
				)
			)
		);
		$_GET['site_id'] = (string) $site_id;

		$output   = $this->render();
		$software = substr( $output, strpos( $output, '<h2>Site Software</h2>' ), strpos( $output, '<h2>Recent Events</h2>' ) - strpos( $output, '<h2>Site Software</h2>' ) );

		$this->assertStringContainsString( '<h2>Site Software</h2>', $output );
		$this->assertStringNotContainsString( '>WordPress<', $software );
		$this->assertStringContainsString( '>Current version</th>', $software );
		$this->assertStringContainsString( '>Update status</th>', $software );
		$this->assertStringContainsString( '>Available version</th>', $software );
		$this->assertStringContainsString( 'Snow &lt;script&gt;Monkey&lt;/script&gt;', $output );
		$this->assertStringContainsString( '>31.0.2</td>', $output );
		$this->assertStringContainsString( '>Update available</td>', $output );
		$this->assertStringContainsString( '>31.1.0</td>', $output );
		$this->assertStringContainsString( '>Alpha Plugin</td>', $output );
		$this->assertStringContainsString( '>1.2.3</td>', $output );
		$this->assertStringContainsString( '>Latest</td>', $output );
		$this->assertStringContainsString( '>Zulu Plugin</td>', $output );
		$this->assertStringContainsString( '>Unknown</td>', $software );
		$this->assertMatchesRegularExpression( '/Last collected:\s*<time datetime="2026-09-10T09:30:00\+00:00">/s', $output );
		$this->assertLessThan( strpos( $output, 'Zulu Plugin' ), strpos( $output, 'Alpha Plugin' ) );
	}

	public function test_renders_legacy_software_versions_as_unknown_update_status(): void {
		$site_id = $this->create_site( 'Legacy Inventory Site' );
		$this->assertTrue(
			$this->statuses->upsert(
				new SiteStatus(
					site_id: $site_id,
					metadata: array(
						'updates' => array(
							'software_inventory' => array(
								'wordpress_version' => '7.1',
								'theme'             => array(
									'id'      => 'legacy-theme',
									'name'    => 'Legacy Theme',
									'version' => '2.0.0',
								),
								'plugins'           => array(),
								'collected_at'      => '2026-09-10T09:30:00Z',
							),
						),
					)
				)
			)
		);
		$_GET['site_id'] = (string) $site_id;

		$output   = $this->render();
		$software = substr( $output, strpos( $output, '<h2>Site Software</h2>' ), strpos( $output, '<h2>Recent Events</h2>' ) - strpos( $output, '<h2>Site Software</h2>' ) );

		$this->assertStringContainsString( '>Legacy Theme</td>', $software );
		$this->assertStringContainsString( '>2.0.0</td>', $software );
		$this->assertStringContainsString( '>Unknown</td>', $software );
		$this->assertStringNotContainsString( '>WordPress<', $software );
	}

	public function test_empty_status_and_history_are_shown_safely(): void {
		$_GET['site_id'] = (string) $this->create_site( 'Unchecked Site' );

		$output = $this->render();

		$this->assertStringContainsString( 'Unknown', $output );
		$this->assertStringContainsString( 'Software information has not yet been collected.', $output );
		$this->assertStringContainsString( 'No events have been recorded.', $output );
		$this->assertStringContainsString( 'No checks have been recorded.', $output );
	}

	/**
	 * @dataProvider invalid_site_id_provider
	 *
	 * @param mixed $site_id Invalid request value.
	 */
	public function test_invalid_or_deleted_site_is_handled_safely( $site_id ): void {
		$_GET['site_id'] = $site_id;

		$output = $this->render();

		$this->assertStringContainsString( 'The monitored site could not be found.', $output );
		$this->assertStringContainsString( 'Back to Sites', $output );
	}

	public function test_negative_id_does_not_resolve_to_an_existing_site(): void {
		$site_id         = $this->create_site( 'Existing Site' );
		$_GET['site_id'] = '-' . $site_id;

		$output = $this->render();

		$this->assertStringContainsString( 'The monitored site could not be found.', $output );
		$this->assertStringNotContainsString( '<h1>Existing Site</h1>', $output );
	}

	public function test_deleted_site_is_handled_safely(): void {
		$site_id = $this->create_site( 'Deleted Site' );
		$this->assertTrue( $this->sites->delete( $site_id ) );
		$_GET['site_id'] = (string) $site_id;

		$output = $this->render();

		$this->assertStringContainsString( 'The monitored site could not be found.', $output );
	}

	/**
	 * @return array<string,array{mixed}>
	 */
	public function invalid_site_id_provider(): array {
		return array(
			'missing'   => array( '' ),
			'text'      => array( 'not-an-id' ),
			'negative'  => array( '-5' ),
			'array'     => array( array( '5' ) ),
			'not found' => array( '999999' ),
		);
	}

	public function test_dynamic_content_is_escaped(): void {
		$site_id = $this->create_site( '<script>site()</script>' );
		$time    = new DateTimeImmutable( '2026-09-10T12:00:00Z' );
		$this->assertIsInt(
			$this->checks->create( new CheckResult( $site_id, 'http', 'critical', 'BAD_CODE', '<script>check()</script>', $time, $time, 20 ) )
		);
		$this->assertIsInt(
			$this->events->create( new MonitoringEvent( null, $site_id, 'SITE_DOWN', 'healthy', 'critical', 'BAD_CODE', '<script>event()</script>', $time ) )
		);
		$_GET['site_id'] = (string) $site_id;

		$output = $this->render();

		$this->assertStringNotContainsString( '<script>', $output );
		$this->assertStringContainsString( '&lt;script&gt;site()&lt;/script&gt;', $output );
		$this->assertStringContainsString( '&lt;script&gt;check()&lt;/script&gt;', $output );
		$this->assertStringContainsString( '&lt;script&gt;event()&lt;/script&gt;', $output );
	}

	public function test_user_without_permission_cannot_render_detail(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->expectException( \WPDieException::class );

		$this->page()->render();
	}

	private function render(): string {
		ob_start();
		$this->page()->render();

		return (string) ob_get_clean();
	}

	private function page(): SiteDetailPage {
		return new SiteDetailPage( $this->sites, $this->statuses, $this->checks, $this->events, $this->service );
	}

	private function create_site( string $name ): int {
		$id = $this->sites->create(
			new Site( null, wp_generate_uuid4(), $name, 'https://example.com', 'https://example.com/agent' )
		);
		$this->assertIsInt( $id );

		return $id;
	}
}
