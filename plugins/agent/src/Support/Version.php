<?php
/**
 * Agent version access.
 *
 * @package OD_Monitor_Agent
 */

namespace Olein\MonitorAgent\Support;

final class Version {
	/**
	 * Return the plugin version.
	 */
	public static function get(): string {
		return OD_MONITOR_AGENT_VERSION;
	}
}
