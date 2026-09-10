<?php
/**
 * Check result recorder tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use DateTimeImmutable;
use Olein\WordPressMonitor\Activation\DatabaseMigrator;
use Olein\WordPressMonitor\Check\CheckRepository;
use Olein\WordPressMonitor\Evaluation\CheckResultRecorder;
use Olein\WordPressMonitor\Evaluation\StateTransition;
use Olein\WordPressMonitor\Evaluation\StatusEvaluator;
use Olein\WordPressMonitor\Event\EventRepository;
use Olein\WordPressMonitor\Event\EventType;
use Olein\WordPressMonitor\Event\MonitoringEvent;
use Olein\WordPressMonitor\Monitor\CheckResult;
use Olein\WordPressMonitor\Monitor\Status;
use Olein\WordPressMonitor\Notification\NotificationManager;
use Olein\WordPressMonitor\Notification\NotificationRule;
use Olein\WordPressMonitor\Notification\NotificationSenderInterface;
use Olein\WordPressMonitor\Notification\NotificationSettings;
use Olein\WordPressMonitor\Status\SiteStatusRepository;

final class CheckResultRecorderTest extends \WP_UnitTestCase {
	private CheckResultRecorder $recorder;
	private CheckRepository $checks;
	private SiteStatusRepository $statuses;
	private EventRepository $events;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;

		( new DatabaseMigrator( $wpdb ) )->migrate();
		$this->checks   = new CheckRepository( $wpdb );
		$this->statuses = new SiteStatusRepository( $wpdb );
		$this->events   = new EventRepository( $wpdb );
		$this->recorder = new CheckResultRecorder(
			$wpdb,
			$this->checks,
			$this->statuses,
			$this->events,
			new StatusEvaluator(),
			new StateTransition()
		);
		$wpdb->query( "DELETE FROM {$wpdb->prefix}odm_checks" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}odm_site_status" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}odm_events" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		delete_option( NotificationSettings::OPTION );
	}

	public function test_persists_history_current_state_and_only_meaningful_events(): void {
		$this->assertTrue( $this->recorder->record( $this->result( Status::HEALTHY, '2026-09-09T00:00:00Z' ) ) );
		$this->assertCount( 0, $this->events->for_site( 7 ) );

		$this->assertTrue( $this->recorder->record( $this->result( Status::CRITICAL, '2026-09-09T00:05:00Z' ) ) );
		$this->assertTrue( $this->recorder->record( $this->result( Status::CRITICAL, '2026-09-09T00:10:00Z' ) ) );
		$this->assertTrue( $this->recorder->record( $this->result( Status::HEALTHY, '2026-09-09T00:15:00Z' ) ) );

		$checks = $this->checks->for_site( 7 );
		$events = $this->events->for_site( 7 );

		$this->assertCount( 4, $checks );
		$this->assertSame( Status::HEALTHY, $this->statuses->find( 7 )->http_status() );
		$this->assertCount( 2, $events );
		$this->assertSame( EventType::RECOVERED, $events[0]->type() );
		$this->assertSame( EventType::SITE_DOWN, $events[1]->type() );
	}

	public function test_rolls_back_when_result_cannot_be_evaluated(): void {
		$checked_at = new DateTimeImmutable( '2026-09-09T00:00:00Z' );
		$result     = new CheckResult( 7, 'custom', Status::HEALTHY, null, 'Checked.', $checked_at, $checked_at, 5 );

		$this->assertWPError( $this->recorder->record( $result ) );
		$this->assertCount( 0, $this->checks->for_site( 7 ) );
		$this->assertNull( $this->statuses->find( 7 ) );
		$this->assertCount( 0, $this->events->for_site( 7 ) );
	}

	public function test_notifies_once_for_outage_and_once_for_recovery(): void {
		update_option(
			NotificationSettings::OPTION,
			array(
				'enabled' => '1',
				'email'   => 'alerts@example.com',
			)
		);
		$sender   = new class() implements NotificationSenderInterface {
			/** @var list<string> */
			public array $types = array();

			public function send( string $recipient, MonitoringEvent $event, string $notification_type ): bool {
				unset( $recipient, $event );
				$this->types[] = $notification_type;

				return true;
			}
		};
		$recorder = $this->recorder_with_sender( $sender );

		$this->assertTrue( $recorder->record( $this->result( Status::HEALTHY, '2026-09-09T00:00:00Z' ) ) );
		$this->assertTrue( $recorder->record( $this->result( Status::CRITICAL, '2026-09-09T00:05:00Z' ) ) );
		$this->assertTrue( $recorder->record( $this->result( Status::CRITICAL, '2026-09-09T00:10:00Z' ) ) );
		$this->assertTrue( $recorder->record( $this->result( Status::HEALTHY, '2026-09-09T00:15:00Z' ) ) );

		$events = $this->events->for_site( 7 );
		$this->assertSame( array( NotificationRule::OUTAGE, NotificationRule::RECOVERY ), $sender->types );
		$this->assertCount( 2, $events );
		$this->assertSame( 'sent', $events[0]->metadata()['notification']['status'] );
		$this->assertSame( 'sent', $events[1]->metadata()['notification']['status'] );
	}

	public function test_notification_failure_is_recorded_without_failing_monitoring(): void {
		update_option(
			NotificationSettings::OPTION,
			array(
				'enabled' => '1',
				'email'   => 'alerts@example.com',
			)
		);
		$sender   = new class() implements NotificationSenderInterface {
			public function send( string $recipient, MonitoringEvent $event, string $notification_type ): bool {
				unset( $recipient, $event, $notification_type );

				return false;
			}
		};
		$recorder = $this->recorder_with_sender( $sender );

		$this->assertTrue( $recorder->record( $this->result( Status::HEALTHY, '2026-09-09T00:00:00Z' ) ) );
		$this->assertTrue( $recorder->record( $this->result( Status::CRITICAL, '2026-09-09T00:05:00Z' ) ) );

		$event        = $this->events->for_site( 7 )[0];
		$notification = $event->metadata()['notification'];
		$this->assertSame( 'failed', $notification['status'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $notification['timestamp'] );
		$this->assertArrayNotHasKey( 'recipient', $notification );
		$this->assertSame( Status::CRITICAL, $this->statuses->find( 7 )->http_status() );
		$this->assertCount( 2, $this->checks->for_site( 7 ) );
	}

	private function recorder_with_sender( NotificationSenderInterface $sender ): CheckResultRecorder {
		return new CheckResultRecorder(
			$this->database(),
			$this->checks,
			$this->statuses,
			$this->events,
			new StatusEvaluator(),
			new StateTransition(),
			new NotificationManager( new NotificationSettings(), new NotificationRule(), $sender )
		);
	}

	private function database(): \wpdb {
		global $wpdb;

		return $wpdb;
	}

	/**
	 * @param array<string|int,mixed> $data Result metadata.
	 */
	private function result( string $status, string $time, array $data = array() ): CheckResult {
		$checked_at = new DateTimeImmutable( $time );

		return new CheckResult( 7, 'http', $status, Status::CRITICAL === $status ? 'CONNECTION_ERROR' : null, 'Checked.', $checked_at, $checked_at, 5, $data );
	}
}
