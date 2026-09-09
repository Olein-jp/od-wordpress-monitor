<?php
/**
 * Shared monitor contract.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Monitor;

use Olein\WordPressMonitor\Site\Site;

interface MonitorInterface {
	public function get_type(): string;

	public function check( Site $site ): CheckResult;
}
