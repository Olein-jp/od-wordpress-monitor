<?php
/**
 * Agent activation.
 *
 * @package OD_Monitor_Agent
 */

namespace Olein\MonitorAgent\Activation;

use Olein\MonitorAgent\Auth\Role;

final class Activator {
	/**
	 * Run activation tasks.
	 */
	public static function activate(): void {
		( new Role() )->register();
	}
}
