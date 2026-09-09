<?php
/**
 * Persisted monitor check result.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Check;

use DateTimeImmutable;

final class CheckRecord {
	/**
	 * @param array<string|int,mixed> $metadata Non-sensitive result metadata.
	 */
	public function __construct(
		private readonly int $id,
		private readonly int $site_id,
		private readonly string $type,
		private readonly string $status,
		private readonly ?string $error_code,
		private readonly string $message,
		private readonly DateTimeImmutable $started_at,
		private readonly DateTimeImmutable $finished_at,
		private readonly int $duration_ms,
		private readonly array $metadata = array()
	) {
	}

	public function id(): int {
		return $this->id;
	}

	public function site_id(): int {
		return $this->site_id;
	}

	public function type(): string {
		return $this->type;
	}

	public function status(): string {
		return $this->status;
	}

	public function error_code(): ?string {
		return $this->error_code;
	}

	public function message(): string {
		return $this->message;
	}

	public function started_at(): DateTimeImmutable {
		return $this->started_at;
	}

	public function finished_at(): DateTimeImmutable {
		return $this->finished_at;
	}

	public function checked_at(): DateTimeImmutable {
		return $this->finished_at;
	}

	public function duration_ms(): int {
		return $this->duration_ms;
	}

	/**
	 * @return array<string|int,mixed>
	 */
	public function metadata(): array {
		return $this->metadata;
	}
}
