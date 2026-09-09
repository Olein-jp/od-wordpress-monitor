<?php
/**
 * Current monitoring state for one site.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Status;

use DateTimeImmutable;

final class SiteStatus {
	/**
	 * @param array<string|int,mixed> $metadata Non-sensitive status metadata.
	 */
	public function __construct(
		private readonly int $site_id,
		private readonly string $overall_status = 'unknown',
		private readonly string $http_status = 'unknown',
		private readonly ?DateTimeImmutable $http_checked_at = null,
		private readonly string $agent_status = 'unknown',
		private readonly ?DateTimeImmutable $agent_checked_at = null,
		private readonly string $updates_status = 'unknown',
		private readonly ?DateTimeImmutable $updates_checked_at = null,
		private readonly string $site_health_status = 'unknown',
		private readonly ?DateTimeImmutable $site_health_checked_at = null,
		private readonly string $ssl_status = 'unknown',
		private readonly ?DateTimeImmutable $ssl_checked_at = null,
		private readonly ?DateTimeImmutable $last_checked_at = null,
		private readonly ?string $last_error_code = null,
		private readonly string $last_message = '',
		private readonly array $metadata = array(),
		private readonly ?DateTimeImmutable $updated_at = null
	) {
	}

	public function site_id(): int {
		return $this->site_id;
	}

	public function overall_status(): string {
		return $this->overall_status;
	}

	public function http_status(): string {
		return $this->http_status;
	}

	public function http_checked_at(): ?DateTimeImmutable {
		return $this->http_checked_at;
	}

	public function agent_status(): string {
		return $this->agent_status;
	}

	public function agent_checked_at(): ?DateTimeImmutable {
		return $this->agent_checked_at;
	}

	public function updates_status(): string {
		return $this->updates_status;
	}

	public function updates_checked_at(): ?DateTimeImmutable {
		return $this->updates_checked_at;
	}

	public function site_health_status(): string {
		return $this->site_health_status;
	}

	public function site_health_checked_at(): ?DateTimeImmutable {
		return $this->site_health_checked_at;
	}

	public function ssl_status(): string {
		return $this->ssl_status;
	}

	public function ssl_checked_at(): ?DateTimeImmutable {
		return $this->ssl_checked_at;
	}

	public function last_checked_at(): ?DateTimeImmutable {
		return $this->last_checked_at;
	}

	public function last_error_code(): ?string {
		return $this->last_error_code;
	}

	public function last_message(): string {
		return $this->last_message;
	}

	/**
	 * @return array<string|int,mixed>
	 */
	public function metadata(): array {
		return $this->metadata;
	}

	public function updated_at(): ?DateTimeImmutable {
		return $this->updated_at;
	}
}
