<?php
/**
 * Check history retention tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use DateTimeImmutable;
use Olein\WordPressMonitor\Activation\DatabaseMigrator;
use Olein\WordPressMonitor\Check\CheckRepository;
use Olein\WordPressMonitor\Event\EventRepository;
use Olein\WordPressMonitor\Event\MonitoringEvent;
use Olein\WordPressMonitor\Monitor\CheckResult;
use Olein\WordPressMonitor\Scheduler\CheckRetention;
use Olein\WordPressMonitor\Status\SiteStatus;
use Olein\WordPressMonitor\Status\SiteStatusRepository;

final class CheckRetentionTest extends \WP_UnitTestCase {
	private CheckRepository $checks;
	private EventRepository $events;
	private SiteStatusRepository $statuses;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;

		( new DatabaseMigrator( $wpdb ) )->migrate();
		$this->checks   = new CheckRepository( $wpdb );
		$this->events   = new EventRepository( $wpdb );
		$this->statuses = new SiteStatusRepository( $wpdb );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}odm_checks" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}odm_events" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}odm_site_status" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	public function test_cleanup_deletes_only_checks_older_than_utc_boundary(): void {
		$old_id      = $this->checks->create( $this->result( '2026-06-11T23:59:59Z' ) );
		$boundary_id = $this->checks->create( $this->result( '2026-06-12T00:00:00Z' ) );
		$new_id      = $this->checks->create( $this->result( '2026-06-12T00:00:01Z' ) );
		$retention   = new CheckRetention(
			$this->checks,
			static fn(): DateTimeImmutable => new DateTimeImmutable( '2026-09-10T09:00:00+09:00' )
		);

		$this->assertSame( 1, $retention->cleanup() );
		$this->assertNull( $this->checks->find( $old_id ) );
		$this->assertNotNull( $this->checks->find( $boundary_id ) );
		$this->assertNotNull( $this->checks->find( $new_id ) );
	}

	public function test_cleanup_does_not_change_events_or_current_status(): void {
		$time = new DateTimeImmutable( '2026-01-01T00:00:00Z' );
		$this->assertIsInt( $this->checks->create( $this->result( '2026-01-01T00:00:00Z' ) ) );
		$this->assertIsInt(
			$this->events->create(
				new MonitoringEvent( null, 21, 'SITE_DOWN', 'healthy', 'critical', null, 'Down.', $time )
			)
		);
		$this->assertTrue( $this->statuses->upsert( new SiteStatus( site_id: 21, overall_status: 'critical' ) ) );

		$retention = new CheckRetention(
			$this->checks,
			static fn(): DateTimeImmutable => new DateTimeImmutable( '2026-09-10T00:00:00Z' )
		);

		$this->assertSame( 1, $retention->cleanup() );
		$this->assertCount( 0, $this->checks->for_site( 21 ) );
		$this->assertCount( 1, $this->events->for_site( 21 ) );
		$this->assertSame( 'critical', $this->statuses->find( 21 )->overall_status() );
	}

	private function result( string $time ): CheckResult {
		$checked_at = new DateTimeImmutable( $time );

		return new CheckResult( 21, 'http', 'healthy', null, 'Checked.', $checked_at, $checked_at, 5, array( 'http_status' => 200 ) );
	}
}
