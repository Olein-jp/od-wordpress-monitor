<?php
/**
 * Secret-free notification content shared by every delivery channel.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Notification;

use DateTimeImmutable;

final class NotificationMessage {
	public function __construct(
		private readonly string $notification_type,
		private readonly string $site_name,
		private readonly string $site_url,
		private readonly string $event_type,
		private readonly string $previous_status,
		private readonly string $current_status,
		private readonly DateTimeImmutable $occurred_at,
		private readonly string $error_code,
		private readonly string $message
	) {
	}

	public function notification_type(): string {
		return $this->notification_type;
	}

	public function site_name(): string {
		return $this->site_name;
	}

	public function site_url(): string {
		return $this->site_url;
	}

	public function event_type(): string {
		return $this->event_type;
	}

	public function previous_status(): string {
		return $this->previous_status;
	}

	public function current_status(): string {
		return $this->current_status;
	}

	public function occurred_at(): DateTimeImmutable {
		return $this->occurred_at;
	}

	public function error_code(): string {
		return $this->error_code;
	}

	public function message(): string {
		return $this->message;
	}
}
