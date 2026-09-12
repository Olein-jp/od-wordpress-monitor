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
use Olein\WordPressMonitor\Scheduler\OptionCleanupRepository;
use Olein\WordPressMonitor\Status\SiteStatus;
use Olein\WordPressMonitor\Status\SiteStatusRepository;

final class CheckRetentionTest extends \WP_UnitTestCase {
	private CheckRepository $checks;
	private EventRepository $events;
	private OptionCleanupRepository $options;
	private SiteStatusRepository $statuses;
	private int $now;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;

		( new DatabaseMigrator( $wpdb ) )->migrate();
		$this->checks   = new CheckRepository( $wpdb );
		$this->events   = new EventRepository( $wpdb );
		$this->options  = new OptionCleanupRepository( $wpdb );
		$this->statuses = new SiteStatusRepository( $wpdb );
		$this->now      = time();
		$wpdb->query( "DELETE FROM {$wpdb->prefix}odm_checks" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}odm_events" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}odm_site_status" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->clear_test_options();
	}

	public function tear_down(): void {
		$this->clear_test_options();
		parent::tear_down();
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

	public function test_cleanup_is_bounded_and_resumes_oldest_checks(): void {
		$ids = array();

		for ( $index = 0; $index < 7; ++$index ) {
			$ids[] = $this->checks->create( $this->result( '2026-01-0' . ( $index + 1 ) . 'T00:00:00Z' ) );
		}

		$retention = new CheckRetention(
			$this->checks,
			static fn(): DateTimeImmutable => new DateTimeImmutable( '2026-09-10T00:00:00Z' ),
			null,
			3
		);

		$this->assertSame( 3, $retention->cleanup() );
		$this->assertNull( $this->checks->find( $ids[0] ) );
		$this->assertNull( $this->checks->find( $ids[1] ) );
		$this->assertNull( $this->checks->find( $ids[2] ) );
		$this->assertNotNull( $this->checks->find( $ids[3] ) );

		$this->assertSame( 3, $retention->cleanup() );
		$this->assertSame( 1, $retention->cleanup() );
		$this->assertSame( 0, $retention->cleanup() );
	}

	public function test_cleanup_collects_expired_locks_and_plugin_transients_but_keeps_active_data(): void {
		$expired_lock = 'odm_lock_expired_http';
		$active_lock  = 'odm_lock_active_http';
		update_option( $expired_lock, ( $this->now - 1 ) . ':old-owner', false );
		update_option( $active_lock, ( $this->now + 60 ) . ':current-owner', false );
		$this->set_test_transient( 'odm_status_expired', $this->now - 1 );
		$this->set_test_transient( 'odm_admin_notice_expired', $this->now - 1 );
		$this->set_test_transient( 'odm_status_active', $this->now + 60 );
		$this->set_test_transient( 'unrelated_expired', $this->now - 1 );
		$this->assertIsInt( $this->checks->create( $this->result( '2026-01-01T00:00:00Z' ) ) );
		$this->assertIsInt( $this->checks->create( $this->result( '2026-01-02T00:00:00Z' ) ) );

		$retention = new CheckRetention(
			$this->checks,
			fn(): DateTimeImmutable => ( new DateTimeImmutable( '@' . $this->now ) )->setTimezone( new \DateTimeZone( 'UTC' ) ),
			$this->options,
			6
		);

		$this->assertSame( 5, $retention->cleanup() );
		$this->assertFalse( get_option( $expired_lock, false ) );
		$this->assertSame( ( $this->now + 60 ) . ':current-owner', get_option( $active_lock ) );
		$this->assertFalse( get_option( '_transient_odm_status_expired', false ) );
		$this->assertFalse( get_option( '_transient_odm_admin_notice_expired', false ) );
		$this->assertSame( 'temporary', get_option( '_transient_odm_status_active' ) );
		$this->assertSame( 'temporary', get_option( '_transient_unrelated_expired' ) );
		$this->assertCount( 0, $this->checks->for_site( 21 ) );
	}

	public function test_option_cleanup_limit_allows_safe_resume(): void {
		update_option( 'odm_lock_expired_one_http', ( $this->now - 2 ) . ':one', false );
		update_option( 'odm_lock_expired_two_http', ( $this->now - 1 ) . ':two', false );

		$this->assertSame( 1, $this->options->delete_expired_locks( $this->now, 1 ) );
		$this->assertSame( 1, $this->options->delete_expired_locks( $this->now, 1 ) );
		$this->assertSame( 0, $this->options->delete_expired_locks( $this->now, 1 ) );
	}

	public function test_cleanup_removes_expired_notification_retry_claim(): void {
		$claim = 'odm_lock_notification_retry_7_slack';
		add_option( $claim, ( $this->now - 1 ) . ':claimed', '', false );
		$this->assertSame( 1, $this->options->delete_expired_locks( $this->now, 10 ) );
		$this->assertFalse( get_option( $claim, false ) );
	}

	public function test_cleanup_resumes_after_database_failure_without_reprocessing_deleted_checks(): void {
		global $wpdb;
		$this->assertIsInt( $this->checks->create( $this->result( '2026-01-01T00:00:00Z' ) ) );
		$this->assertIsInt( $this->checks->create( $this->result( '2026-01-02T00:00:00Z' ) ) );
		$retention      = new CheckRetention(
			$this->checks,
			fn(): DateTimeImmutable => ( new DateTimeImmutable( '@' . $this->now ) )->setTimezone( new \DateTimeZone( 'UTC' ) ),
			$this->options,
			3
		);
		$options_table  = $wpdb->options;
		$was_suppressed = $wpdb->suppress_errors();

		try {
			$wpdb->options = $wpdb->prefix . 'odm_missing_options';
			$failed        = $retention->cleanup();
		} finally {
			$wpdb->options = $options_table;
			$wpdb->suppress_errors( $was_suppressed );
		}

		$this->assertWPError( $failed );
		$this->assertSame( 'DATABASE_ERROR', $failed->get_error_code() );
		$this->assertCount( 1, $this->checks->for_site( 21 ) );
		$this->assertSame( 1, $retention->cleanup() );
		$this->assertCount( 0, $this->checks->for_site( 21 ) );
	}

	private function result( string $time ): CheckResult {
		$checked_at = new DateTimeImmutable( $time );

		return new CheckResult( 21, 'http', 'healthy', null, 'Checked.', $checked_at, $checked_at, 5, array( 'http_status' => 200 ) );
	}

	private function set_test_transient( string $key, int $expires ): void {
		set_transient( $key, 'temporary', HOUR_IN_SECONDS );
		update_option( '_transient_timeout_' . $key, $expires, false );
	}

	private function clear_test_options(): void {
		foreach (
			array(
				'odm_lock_expired_http',
				'odm_lock_active_http',
				'odm_lock_expired_one_http',
				'odm_lock_expired_two_http',
				'odm_lock_notification_retry_7_slack',
			) as $option
		) {
			delete_option( $option );
		}

		foreach ( array( 'odm_status_expired', 'odm_admin_notice_expired', 'odm_status_active', 'unrelated_expired' ) as $transient ) {
			delete_transient( $transient );
		}
	}
}
