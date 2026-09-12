<?php
/**
 * Persists one check result, current status, and optional transition event.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Evaluation;

use Olein\WordPressMonitor\Check\CheckRepository;
use Olein\WordPressMonitor\Event\EventRepository;
use Olein\WordPressMonitor\Event\MonitoringEvent;
use Olein\WordPressMonitor\Monitor\CheckResult;
use Olein\WordPressMonitor\Notification\NotificationManager;
use Olein\WordPressMonitor\Notification\NotificationDeliveryRetry;
use Olein\WordPressMonitor\Status\SiteStatus;
use Olein\WordPressMonitor\Status\SiteStatusRepository;
use Throwable;
use WP_Error;
use wpdb;

final class CheckResultRecorder {
	public function __construct(
		private readonly wpdb $database,
		private readonly CheckRepository $checks,
		private readonly SiteStatusRepository $statuses,
		private readonly EventRepository $events,
		private readonly StatusEvaluator $evaluator,
		private readonly StateTransition $transition,
		private readonly ?NotificationManager $notifications = null,
		private readonly ?NotificationDeliveryRetry $notification_retry = null
	) {
	}

	/**
	 * Persist all state derived from a check as one database transaction.
	 *
	 * @return true|WP_Error
	 */
	public function record( CheckResult $result ) {
		if ( false === $this->database->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			return $this->database_error();
		}

		try {
			$event    = null;
			$event_id = null;
			$previous = $this->statuses->find_for_update( $result->site_id() ) ?? new SiteStatus( $result->site_id() );
			$check_id = $this->checks->create( $result );

			if ( is_wp_error( $check_id ) ) {
				return $this->rollback( $check_id );
			}

			$current = $this->evaluator->apply( $previous, $result );
			$saved   = $this->statuses->upsert( $current );

			if ( is_wp_error( $saved ) ) {
				return $this->rollback( $saved );
			}

			$event = $this->transition->detect( $previous, $current, $result );

			if ( null !== $event ) {
				$event_id = $this->events->create( $event );

				if ( is_wp_error( $event_id ) ) {
					return $this->rollback( $event_id );
				}
			}

			if ( false === $this->database->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				return $this->rollback( $this->database_error() );
			}

			$this->notify( $event, $event_id );

			return true;
		} catch ( Throwable $exception ) {
			unset( $exception );

			return $this->rollback( $this->database_error() );
		}
	}

	private function notify( ?MonitoringEvent $event, ?int $event_id ): void {
		if ( null === $event || null === $event_id || null === $this->notifications ) {
			return;
		}

		try {
			$delivery = $this->notifications->notify( $event );

			if ( null !== $delivery && true === $this->events->record_notification_result( $event_id, $delivery ) ) {
				$this->notification_retry?->schedule_failed( $event_id, $delivery );
			}
		} catch ( Throwable $exception ) {
			unset( $exception );
		}
	}

	private function rollback( WP_Error $error ): WP_Error {
		$this->database->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return $error;
	}

	private function database_error(): WP_Error {
		return new WP_Error( 'DATABASE_ERROR', __( 'The check result could not be persisted.', 'od-wordpress-monitor' ) );
	}
}
