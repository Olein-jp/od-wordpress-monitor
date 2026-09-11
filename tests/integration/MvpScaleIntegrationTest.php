<?php
/**
 * Reproducible MVP scale profile.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\IntegrationTests;

use DateTimeImmutable;
use Olein\WordPressMonitor\Activation\DatabaseMigrator;
use Olein\WordPressMonitor\Admin\StatusOverview;
use Olein\WordPressMonitor\Check\CheckRepository;
use Olein\WordPressMonitor\Scheduler\CheckRetention;
use Olein\WordPressMonitor\Site\Site;
use Olein\WordPressMonitor\Site\SiteRepository;
use Olein\WordPressMonitor\Status\SiteStatusRepository;

final class MvpScaleIntegrationTest extends \WP_UnitTestCase {
	private const SITE_COUNT    = 100;
	private const HISTORY_COUNT = 10000;
	private const TIME_BUDGET   = 10.0;
	private const MEMORY_BUDGET = 64 * MB_IN_BYTES;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;

		( new DatabaseMigrator( $wpdb ) )->migrate();
		$this->clear_data();
	}

	public function tear_down(): void {
		$this->clear_data();
		parent::tear_down();
	}

	public function test_mvp_site_and_history_profile_stays_within_bounded_resources(): void {
		global $wpdb;

		$sites    = new SiteRepository( $wpdb );
		$site_ids = array();

		for ( $index = 1; $index <= self::SITE_COUNT; ++$index ) {
			$site_ids[] = $sites->create(
				new Site(
					null,
					wp_generate_uuid4(),
					sprintf( 'Scale Site %03d', $index ),
					sprintf( 'https://site-%03d.example.com', $index ),
					sprintf( 'https://site-%03d.example.com/wp-json/od-monitor-agent/v1', $index )
				)
			);
		}

		$this->assertNotContains( false, array_map( 'is_int', $site_ids ) );
		$this->seed_history( $site_ids );
		$started = microtime( true );
		$memory  = memory_get_usage( true );
		$cursor  = 0;
		$seen    = array();
		$count   = 0;

		do {
			$page  = $sites->enabled_after( $cursor, 20 );
			$count = count( $page );

			foreach ( $page as $site ) {
				$seen[] = $site->id();
				$cursor = (int) $site->id();
			}
		} while ( 20 === $count );

		$checks   = new CheckRepository( $wpdb );
		$recent   = $checks->for_site( (int) $site_ids[0], 20 );
		$snapshot = ( new StatusOverview( $sites, new SiteStatusRepository( $wpdb ) ) )->snapshot();
		$deleted  = ( new CheckRetention(
			$checks,
			static fn(): DateTimeImmutable => new DateTimeImmutable( '2026-09-11T00:00:00Z' ),
			null,
			300
		) )->cleanup();
		$elapsed  = microtime( true ) - $started;
		$used     = max( 0, memory_get_peak_usage( true ) - $memory );

		$this->assertCount( self::SITE_COUNT, array_unique( $seen ) );
		$this->assertCount( 20, $recent );
		$this->assertSame( self::SITE_COUNT, $snapshot['summary']['total'] );
		$this->assertSame( 300, $deleted );
		$this->assertLessThan( self::TIME_BUDGET, $elapsed );
		$this->assertLessThan( self::MEMORY_BUDGET, $used );

		fwrite( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- PHPUnit performance result output.
			STDOUT,
			sprintf(
				"\nMVP scale profile: %d sites, %d checks, %.3f seconds, %.1f MiB additional peak memory\n",
				self::SITE_COUNT,
				self::HISTORY_COUNT,
				$elapsed,
				$used / MB_IN_BYTES
			)
		);
	}

	/**
	 * @param list<int> $site_ids Persisted site IDs.
	 */
	private function seed_history( array $site_ids ): void {
		global $wpdb;

		$table  = $wpdb->prefix . 'odm_checks';
		$values = array();

		for ( $index = 0; $index < self::HISTORY_COUNT; ++$index ) {
			$site_id   = $site_ids[ $index % count( $site_ids ) ];
			$timestamp = gmdate( 'Y-m-d H:i:s', strtotime( '2026-01-01 00:00:00 UTC' ) + $index );
			$values[]  = $wpdb->prepare(
				"(%d, 'http', 'healthy', NULL, 'Checked.', 5, '{}', %s, %s, %s)",
				$site_id,
				$timestamp,
				$timestamp,
				$timestamp
			);

			if ( 500 === count( $values ) || self::HISTORY_COUNT - 1 === $index ) {
				$sql = "INSERT INTO {$table} (site_id, check_type, status, error_code, message, duration_ms, metadata, started_at, finished_at, checked_at) VALUES " . implode( ', ', $values ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->assertNotFalse( $wpdb->query( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$values = array();
			}
		}
	}

	private function clear_data(): void {
		global $wpdb;

		foreach ( array( 'odm_events', 'odm_checks', 'odm_site_status', 'odm_credentials', 'odm_sites' ) as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}
	}
}
