<?php
/**
 * Check execution lock contract.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Scheduler;

use Olein\WordPressMonitor\Site\Site;

interface CheckLockInterface {
	public function acquire( Site $site, string $check_type ): ?string;

	public function release( Site $site, string $check_type, string $token ): void;
}
