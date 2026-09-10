<?php
/**
 * Monitoring status overview tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use Olein\WordPressMonitor\Activation\DatabaseMigrator;
use Olein\WordPressMonitor\Admin\StatusLabel;
use Olein\WordPressMonitor\Admin\StatusOverview;
use Olein\WordPressMonitor\Site\Site;
use Olein\WordPressMonitor\Site\SiteRepository;
use Olein\WordPressMonitor\Status\SiteStatus;
use Olein\WordPressMonitor\Status\SiteStatusRepository;

final class StatusOverviewTest extends \WP_UnitTestCase {
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
	}

	public function test_summary_counts_saved_and_missing_statuses(): void {
		$this->create_site( 'Healthy Site', 'healthy' );
		$this->create_site( 'Attention Site', 'warning' );
		$this->create_site( 'Problem Site', 'critical' );
		$this->create_site( 'Unchecked Site' );

		$summary = $this->overview->snapshot()['summary'];

		$this->assertSame( 4, $summary['total'] );
		$this->assertSame( 1, $summary['healthy'] );
		$this->assertSame( 1, $summary['warning'] );
		$this->assertSame( 1, $summary['critical'] );
		$this->assertSame( 1, $summary['unknown'] );
	}

	public function test_rows_are_sorted_by_severity_then_name(): void {
		$this->create_site( 'Healthy Site', 'healthy' );
		$this->create_site( 'Unknown Site' );
		$this->create_site( 'Zulu Attention', 'warning' );
		$this->create_site( 'Problem Site', 'critical' );
		$this->create_site( 'Alpha Attention', 'warning' );

		$names = array_map(
			static fn ( array $row ): string => $row['site']->name(),
			$this->overview->snapshot()['rows']
		);

		$this->assertSame(
			array( 'Problem Site', 'Alpha Attention', 'Zulu Attention', 'Unknown Site', 'Healthy Site' ),
			$names
		);
	}

	/**
	 * @dataProvider filter_provider
	 *
	 * @param string       $filter   Requested filter.
	 * @param list<string> $expected Expected site names.
	 */
	public function test_filters_return_only_matching_sites( string $filter, array $expected ): void {
		$this->create_site( 'Healthy Site', 'healthy' );
		$this->create_site( 'Attention Site', 'warning' );
		$this->create_site( 'Problem Site', 'critical' );
		$this->create_site( 'Unknown Site' );

		$names = array_map(
			static fn ( array $row ): string => $row['site']->name(),
			$this->overview->snapshot( $filter )['rows']
		);

		$this->assertSame( $expected, $names );
	}

	/**
	 * @return array<string,array{string,list<string>}>
	 */
	public function filter_provider(): array {
		return array(
			'problem'   => array( 'critical', array( 'Problem Site' ) ),
			'attention' => array( 'warning', array( 'Attention Site' ) ),
			'healthy'   => array( 'healthy', array( 'Healthy Site' ) ),
			'unknown'   => array( 'unknown', array( 'Unknown Site' ) ),
		);
	}

	public function test_unknown_values_are_safely_presented_as_unknown(): void {
		$this->create_site( 'Unexpected Site', 'future-status' );

		$snapshot = $this->overview->snapshot( 'future-status' );

		$this->assertSame( 'all', $snapshot['filter'] );
		$this->assertSame( 'unknown', $snapshot['rows'][0]['overall'] );
		$this->assertSame( 'Unknown', StatusLabel::for_status( 'future-status' ) );
	}

	private function create_site( string $name, ?string $overall = null ): int {
		$id = $this->sites->create(
			new Site(
				null,
				wp_generate_uuid4(),
				$name,
				'https://' . sanitize_title( $name ) . '.example.com',
				'https://' . sanitize_title( $name ) . '.example.com/agent'
			)
		);
		$this->assertIsInt( $id );

		if ( null !== $overall ) {
			$this->assertTrue( $this->statuses->upsert( new SiteStatus( site_id: $id, overall_status: $overall ) ) );
		}

		return $id;
	}
}
