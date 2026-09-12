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
use Olein\WordPressMonitor\Notification\NotificationChannelResult;
use Olein\WordPressMonitor\Notification\NotificationDeliveryResult;

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

	public function test_records_only_channel_results_and_utc_timestamp(): void {
		$id = $this->repository->create( $this->event( 'SITE_DOWN', 'healthy', 'critical', '2026-09-09T00:00:00Z' ) );

		$this->assertIsInt( $id );
		$this->assertTrue(
			$this->repository->record_notification_result(
				$id,
				new NotificationDeliveryResult(
					array(
						NotificationChannelResult::sent( 'email' ),
						NotificationChannelResult::failed( 'slack', 'HTTP_503' ),
					)
				),
				new DateTimeImmutable( '2026-09-10T09:30:00+09:00' )
			)
		);
		$this->assertSame(
			array(
				'source'       => 'http',
				'notification' => array(
					'status'    => 'partial',
					'timestamp' => '2026-09-10T00:30:00Z',
					'channels'  => array(
						'email' => array(
							'status'   => 'sent',
							'attempts' => 1,
						),
						'slack' => array(
							'status'     => 'failed',
							'attempts'   => 1,
							'error_code' => 'HTTP_503',
						),
					),
				),
			),
			$this->repository->find( $id )->metadata()
		);
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
