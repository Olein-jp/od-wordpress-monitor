<?php
/**
 * Schedules one delayed retry for transient notification delivery failures.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Notification;

use Closure;
use DateTimeImmutable;
use Olein\WordPressMonitor\Event\EventRepository;

final class NotificationDeliveryRetry {
	public const HOOK = 'odm_retry_notification_delivery';

	private const DEFAULT_DELAY  = 60;
	private const CLAIM_LIFETIME = DAY_IN_SECONDS;

	/**
	 * @param null|Closure():int $clock UTC Unix timestamp provider.
	 */
	public function __construct(
		private readonly EventRepository $events,
		private readonly NotificationManager $manager,
		private readonly ?Closure $clock = null
	) {
	}

	public function schedule_failed( int $event_id, NotificationDeliveryResult $delivery ): void {
		if ( $event_id < 1 ) {
			return;
		}

		foreach ( $delivery->channels() as $channel_id => $result ) {
			if ( ! $this->is_retryable( $result ) || 1 !== $result->attempts() ) {
				continue;
			}

			$args = array( $event_id, $channel_id );
			if ( false !== wp_next_scheduled( self::HOOK, $args ) ) {
				continue;
			}

			$delay = $result->retry_after_seconds() ?? self::DEFAULT_DELAY;
			wp_schedule_single_event( $this->now() + $delay, self::HOOK, $args );
		}
	}

	public function run( int $event_id, string $channel_id ): ?NotificationChannelResult {
		if ( $event_id < 1 || 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $channel_id ) ) {
			return null;
		}

		$event = $this->events->find( $event_id );
		if ( null === $event || ! $this->is_pending( $event->metadata(), $channel_id ) ) {
			return null;
		}

		$claim = 'odm_lock_notification_retry_' . $event_id . '_' . $channel_id;
		if ( ! add_option( $claim, ( $this->now() + self::CLAIM_LIFETIME ) . ':claimed', '', false ) ) {
			return null;
		}

		$event = $this->events->find( $event_id );
		if ( null === $event || ! $this->is_pending( $event->metadata(), $channel_id ) ) {
			return null;
		}

		$result = $this->manager->retry_channel( $event, $channel_id );
		if ( null === $result ) {
			return null;
		}

		$this->events->record_channel_retry_result( $event_id, $channel_id, $result, new DateTimeImmutable( '@' . $this->now() ) );
		return $result;
	}

	public function is_retryable( NotificationChannelResult $result ): bool {
		if ( $result->succeeded() ) {
			return false;
		}

		$code = $result->error_code();
		if ( in_array( $code, array( 'TIMEOUT', 'CONNECTION_ERROR', 'HTTP_408', 'HTTP_429' ), true ) ) {
			return true;
		}

		if ( ! is_string( $code ) || 1 !== preg_match( '/^HTTP_5[0-9]{2}$/', $code ) ) {
			return false;
		}

		return true;
	}

	/**
	 * @param array<string|int,mixed> $metadata Safe event metadata.
	 */
	private function is_pending( array $metadata, string $channel_id ): bool {
		$notification = $metadata['notification'] ?? null;
		$channels     = is_array( $notification ) ? ( $notification['channels'] ?? null ) : null;
		$channel      = is_array( $channels ) ? ( $channels[ $channel_id ] ?? null ) : null;

		return is_array( $channel )
			&& NotificationChannelResult::FAILED === ( $channel['status'] ?? null )
			&& 1 === ( $channel['attempts'] ?? null )
			&& $this->is_retryable( NotificationChannelResult::failed( $channel_id, is_string( $channel['error_code'] ?? null ) ? $channel['error_code'] : '' ) );
	}

	private function now(): int {
		return null === $this->clock ? time() : ( $this->clock )();
	}
}
