<?php
/**
 * Monitoring administration page tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use Olein\WordPressMonitor\Activation\DatabaseMigrator;
use Olein\WordPressMonitor\Admin\DashboardPage;
use Olein\WordPressMonitor\Admin\SitesPage;
use Olein\WordPressMonitor\Admin\StatusOverview;
use Olein\WordPressMonitor\Credential\CredentialEncryptor;
use Olein\WordPressMonitor\Credential\CredentialRepository;
use Olein\WordPressMonitor\Credential\CredentialService;
use Olein\WordPressMonitor\Http\AgentClient;
use Olein\WordPressMonitor\Http\HttpClient;
use Olein\WordPressMonitor\Protocol\ResponseValidator;
use Olein\WordPressMonitor\Site\Site;
use Olein\WordPressMonitor\Site\SiteRepository;
use Olein\WordPressMonitor\Site\SiteService;
use Olein\WordPressMonitor\Status\SiteStatus;
use Olein\WordPressMonitor\Status\SiteStatusRepository;
use Olein\WordPressMonitor\Support\UUID;

final class AdminStatusPagesTest extends \WP_UnitTestCase {
	private SiteRepository $sites;
	private SiteStatusRepository $statuses;
	private StatusOverview $overview;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;

		( new DatabaseMigrator( $wpdb ) )->migrate();
		$this->sites    = new SiteRepository( $wpdb );
		$this->statuses = new SiteStatusRepository( $wpdb );
		$this->overview = new StatusOverview( $this->sites, $this->statuses );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}odm_site_status" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}odm_sites" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET = array();
	}

	public function tear_down(): void {
		$_GET = array();
		parent::tear_down();
	}

	public function test_dashboard_renders_saved_summary_counts_and_accessible_headers(): void {
		$this->create_site( 'Problem Site', 'critical' );
		$this->create_site( 'Unknown Site' );

		$output = $this->render( new DashboardPage( $this->overview ) );

		$this->assertStringContainsString( '<caption class="screen-reader-text">Monitoring status summary</caption>', $output );
		$this->assertSame( 5, substr_count( $output, '<th scope="col">' ) );
		$this->assertMatchesRegularExpression( '/>\s*2\s*<span class="screen-reader-text">Sites<\/span>/', $output );
		$this->assertStringContainsString( 'status=critical', $output );
	}

	public function test_sites_page_renders_required_columns_text_status_and_problem_first(): void {
		$this->create_site( 'Healthy Site', 'healthy' );
		$this->create_site( 'Problem Site', 'critical' );

		$output = $this->render( $this->sites_page() );

		foreach ( array( 'Site', 'Overall', 'HTTP', 'Agent', 'Updates', 'Site Health', 'SSL', 'Last Check' ) as $heading ) {
			$this->assertStringContainsString( '<th scope="col">' . $heading . '</th>', $output );
		}
		$this->assertLessThan( strpos( $output, 'Healthy Site' ), strpos( $output, 'Problem Site' ) );
		$this->assertStringContainsString( '<strong>Problem</strong>', $output );
		$this->assertStringContainsString( 'page=od-wordpress-monitor-site&#038;site_id=', $output );
		$this->assertStringContainsString( 'aria-current="page"', $output );
		$this->assertStringContainsString( 'Filter sites by status', $output );
	}

	public function test_sites_page_filters_and_escapes_saved_site_content(): void {
		$this->create_site( '<script>alert("problem")</script>', 'critical' );
		$this->create_site( 'Healthy Site', 'healthy' );
		$_GET['status'] = 'critical';

		$output = $this->render( $this->sites_page() );

		$this->assertStringContainsString( '&lt;script&gt;alert(&quot;problem&quot;)&lt;/script&gt;', $output );
		$this->assertStringNotContainsString( '<script>', $output );
		$this->assertStringNotContainsString( 'Healthy Site', $output );
		$this->assertStringContainsString( 'class="current" aria-current="page"', $output );
	}

	public function test_sites_page_has_a_clear_empty_state(): void {
		$output = $this->render( $this->sites_page() );

		$this->assertStringContainsString( 'No sites have been added.', $output );
	}

	/**
	 * @dataProvider restricted_page_provider
	 */
	public function test_users_without_permission_cannot_render_pages( string $page ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->expectException( \WPDieException::class );

		if ( 'dashboard' === $page ) {
			( new DashboardPage( $this->overview ) )->render();
			return;
		}

		$this->sites_page()->render();
	}

	/**
	 * @return array<string,array{string}>
	 */
	public function restricted_page_provider(): array {
		return array(
			'dashboard' => array( 'dashboard' ),
			'sites'     => array( 'sites' ),
		);
	}

	private function sites_page(): SitesPage {
		global $wpdb;

		$service = new SiteService(
			$this->sites,
			new CredentialService( new CredentialRepository( $wpdb ), new CredentialEncryptor() ),
			new AgentClient( new HttpClient(), new ResponseValidator() ),
			new UUID()
		);

		return new SitesPage( $this->overview, $service );
	}

	/**
	 * @param DashboardPage|SitesPage $page Administration page.
	 */
	private function render( $page ): string {
		ob_start();
		$page->render();

		return (string) ob_get_clean();
	}

	private function create_site( string $name, ?string $overall = null ): int {
		$id = $this->sites->create(
			new Site( null, wp_generate_uuid4(), $name, 'https://example.com', 'https://example.com/agent' )
		);
		$this->assertIsInt( $id );

		if ( null !== $overall ) {
			$this->assertTrue( $this->statuses->upsert( new SiteStatus( site_id: $id, overall_status: $overall ) ) );
		}

		return $id;
	}
}
