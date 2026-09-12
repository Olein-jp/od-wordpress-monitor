<?php
/**
 * Notification delivery boundary.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Notification;

interface NotificationSenderInterface {
	/**
	 * Return a stable, non-sensitive channel identifier.
	 */
	public function channel_id(): string;

	/**
	 * Whether this channel currently has valid enabled configuration.
	 */
	public function enabled(): bool;

	/**
	 * Deliver one secret-free notification message.
	 */
	public function send( NotificationMessage $message ): NotificationChannelResult;
}
