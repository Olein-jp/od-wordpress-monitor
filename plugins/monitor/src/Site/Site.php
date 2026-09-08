<?php
/**
 * Monitored site value object.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Site;

final class Site {
	public function __construct(
		private readonly ?int $id,
		private readonly string $uuid,
		private readonly string $name,
		private readonly string $site_url,
		private readonly string $agent_url,
		private readonly bool $enabled = true
	) {
	}

	public function id(): ?int {
		return $this->id;
	}

	public function uuid(): string {
		return $this->uuid;
	}

	public function name(): string {
		return $this->name;
	}

	public function site_url(): string {
		return $this->site_url;
	}

	public function agent_url(): string {
		return $this->agent_url;
	}

	public function enabled(): bool {
		return $this->enabled;
	}
}
