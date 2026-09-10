<?php
/**
 * Selects and dispatches configured notifications.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Notification;

use Olein\WordPressMonitor\Event\MonitoringEvent;
use Throwable;

final class NotificationManager {
	public function __construct(
		private readonly NotificationSettings $settings,
		private readonly NotificationRule $rule,
		private readonly NotificationSenderInterface $sender
	) {
	}

	/**
	 * Dispatch only an allowed transition with a valid enabled recipient.
	 *
	 * @return bool|null True when sent, false on delivery failure, or null when suppressed.
	 */
	public function notify( MonitoringEvent $event ): ?bool {
		if ( ! $this->settings->enabled() ) {
			return null;
		}

		$recipient = $this->settings->email();

		if ( '' === $recipient ) {
			return null;
		}

		$notification_type = $this->rule->classify( $event );

		if ( null === $notification_type ) {
			return null;
		}

		try {
			return $this->sender->send( $recipient, $event, $notification_type );
		} catch ( Throwable $exception ) {
			unset( $exception );

			return false;
		}
	}
}
