<?php
/**
 * Public site metadata collector.
 *
 * @package OD_Monitor_Agent
 */

namespace Olein\MonitorAgent\Collector;

final class SiteCollector {
	/**
	 * Collect site metadata.
	 *
	 * @return array{url:string,home_url:string,name:string}
	 */
	public function collect(): array {
		return array(
			'url'      => get_site_url(),
			'home_url' => home_url(),
			'name'     => get_bloginfo( 'name' ),
		);
	}
}
