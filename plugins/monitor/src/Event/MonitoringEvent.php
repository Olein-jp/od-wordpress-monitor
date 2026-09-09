<?php
/**
 * Meaningful monitoring state-change event.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Event;

use DateTimeImmutable;

final class MonitoringEvent {
	/**
	 * @param array<string|int,mixed> $metadata Non-sensitive event metadata.
	 */
	public function __construct(
		private readonly ?int $id,
		private readonly int $site_id,
		private readonly string $type,
		private readonly string $previous_status,
		private readonly string $current_status,
		private readonly ?string $error_code,
		private readonly string $message,
		private readonly DateTimeImmutable $occurred_at,
		private readonly array $metadata = array()
	) {
	}

	public function id(): ?int {
		return $this->id;
	}

	public function site_id(): int {
		return $this->site_id;
	}

	public function type(): string {
		return $this->type;
	}

	public function previous_status(): string {
		return $this->previous_status;
	}

	public function current_status(): string {
		return $this->current_status;
	}

	public function error_code(): ?string {
		return $this->error_code;
	}

	public function message(): string {
		return $this->message;
	}

	public function occurred_at(): DateTimeImmutable {
		return $this->occurred_at;
	}

	/**
	 * @return array<string|int,mixed>
	 */
	public function metadata(): array {
		return $this->metadata;
	}
}
