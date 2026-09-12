<?php
/**
 * Notification message creation boundary.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Notification;

use Olein\WordPressMonitor\Event\MonitoringEvent;

interface NotificationMessageFactoryInterface {
	/**
	 * Build secret-free shared content for one selected event.
	 */
	public function create( MonitoringEvent $event, string $notification_type ): ?NotificationMessage;
}
