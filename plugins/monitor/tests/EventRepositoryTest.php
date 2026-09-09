<?php
/**
 * Event repository tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use DateTimeImmutable;
use Olein\WordPressMonitor\Activation\DatabaseMigrator;
use Olein\WordPressMonitor\Event\EventRepository;
use Olein\WordPressMonitor\Event\MonitoringEvent;

final class EventRepositoryTest extends \WP_UnitTestCase {
	private EventRepository $repository;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;

		( new DatabaseMigrator( $wpdb ) )->migrate();
		$this->repository = new EventRepository( $wpdb );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}odm_events" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	public function test_creates_and_returns_events_in_reverse_chronological_order(): void {
		$older_id = $this->repository->create( $this->event( 'SITE_DOWN', 'healthy', 'critical', '2026-09-09T00:00:00Z' ) );
		$newer_id = $this->repository->create( $this->event( 'RECOVERED', 'critical', 'healthy', '2026-09-09T01:00:00Z' ) );
		$events   = $this->repository->for_site( 9 );

		$this->assertIsInt( $older_id );
		$this->assertIsInt( $newer_id );
		$this->assertSame( array( $newer_id, $older_id ), array_map( static fn( $event ) => $event->id(), $events ) );
		$this->assertSame( 'RECOVERED', $this->repository->find( $newer_id )->type() );
		$this->assertSame( array( 'source' => 'http' ), $this->repository->find( $newer_id )->metadata() );
	}

	public function test_rejects_sensitive_event_metadata(): void {
		$event = new MonitoringEvent(
			null,
			9,
			'SITE_DOWN',
			'healthy',
			'critical',
			null,
			'Down.',
			new DateTimeImmutable( '2026-09-09T00:00:00Z' ),
			array( 'authorization' => 'Basic YWdlbnQ6c2VjcmV0' )
		);

		$this->assertWPError( $this->repository->create( $event ) );
	}

	private function event( string $type, string $previous_status, string $current_status, string $time ): MonitoringEvent {
		return new MonitoringEvent(
			null,
			9,
			$type,
			$previous_status,
			$current_status,
			null,
			'Changed.',
			new DateTimeImmutable( $time ),
			array( 'source' => 'http' )
		);
	}
}
