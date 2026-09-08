<?php
/**
 * Transient Agent credential value object.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Credential;

final class Credential {
	public function __construct(
		private readonly string $username,
		private readonly string $password
	) {
	}

	public function username(): string {
		return $this->username;
	}

	public function password(): string {
		return $this->password;
	}
}
