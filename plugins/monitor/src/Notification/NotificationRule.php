<?php
/**
 * Notification transition rules.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Notification;

use Olein\WordPressMonitor\Event\MonitoringEvent;
use Olein\WordPressMonitor\Monitor\Status;

final class NotificationRule {
	public const OUTAGE   = 'outage';
	public const RECOVERY = 'recovery';

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

		return null;
	}
}
