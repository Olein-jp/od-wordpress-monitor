<?php
/**
 * Notification transition rules.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Notification;

use Olein\WordPressMonitor\Event\MonitoringEvent;
use Olein\WordPressMonitor\Event\EventType;
use Olein\WordPressMonitor\Monitor\Status;

final class NotificationRule {
	public const OUTAGE       = 'outage';
	public const RECOVERY     = 'recovery';
	public const SSL_WARNING  = 'ssl_warning';
	public const DAILY_DIGEST = 'daily_digest';

	public function __construct( private readonly ?NotificationChannelSettings $settings = null ) {
	}

	/**
	 * Classify an event when its transition should generate a notification.
	 */
	public function classify( MonitoringEvent $event ): ?string {
		$previous = $event->previous_status();
		$current  = $event->current_status();

		if (
			Status::CRITICAL === $current
			&& in_array( $previous, array( Status::HEALTHY, Status::WARNING ), true )
		) {
			return self::OUTAGE;
		}

		if ( Status::CRITICAL === $previous && Status::HEALTHY === $current ) {
			return self::RECOVERY;
		}

		if (
			EventType::SSL === $event->type()
			&& Status::HEALTHY === $previous
			&& Status::WARNING === $current
			&& ( null === $this->settings || $this->settings->ssl_warning_enabled() )
		) {
			return self::SSL_WARNING;
		}

		return null;
	}
}
