<?php
/**
 * WordPress metadata collector.
 *
 * @package OD_Monitor_Agent
 */

namespace Olein\MonitorAgent\Collector;

final class WordPressCollector {
	/**
	 * Collect non-sensitive WordPress metadata.
	 *
	 * @return array{version:string,multisite:bool,environment:string}
	 */
	public function collect(): array {
		global $wp_version;

		return array(
			'version'     => (string) $wp_version,
			'multisite'   => is_multisite(),
			'environment' => wp_get_environment_type(),
		);
	}
}
