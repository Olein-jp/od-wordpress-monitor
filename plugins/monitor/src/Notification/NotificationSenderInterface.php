<?php
/**
 * Notification delivery boundary.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Notification;

use Olein\WordPressMonitor\Event\MonitoringEvent;

interface NotificationSenderInterface {
	/**
	 * Deliver a selected notification.
	 */
	public function send( string $recipient, MonitoringEvent $event, string $notification_type ): bool;
}
