<?php
/**
 * Selects and dispatches configured notifications.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Notification;

use Olein\WordPressMonitor\Event\MonitoringEvent;

final class NotificationManager {
	public function __construct(
		private readonly NotificationSettings $settings,
		private readonly NotificationRule $rule,
		private readonly NotificationSenderInterface $sender
	) {
	}

	/**
	 * Dispatch only an allowed transition with a valid enabled recipient.
	 */
	public function notify( MonitoringEvent $event ): bool {
		if ( ! $this->settings->enabled() ) {
			return false;
		}

		$recipient = $this->settings->email();

		if ( '' === $recipient ) {
			return false;
		}

		$notification_type = $this->rule->classify( $event );

		if ( null === $notification_type ) {
			return false;
		}

		return $this->sender->send( $recipient, $event, $notification_type );
	}
}
