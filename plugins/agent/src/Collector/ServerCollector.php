<?php
/**
 * Server metadata collector.
 *
 * @package OD_Monitor_Agent
 */

namespace Olein\MonitorAgent\Collector;

final class ServerCollector {
	/**
	 * Collect the safe subset of server metadata.
	 *
	 * @return array{php_version:string}
	 */
	public function collect(): array {
		return array( 'php_version' => PHP_VERSION );
	}
}
