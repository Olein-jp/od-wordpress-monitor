<?php
/**
 * Agent role tests.
 *
 * @package OD_Monitor_Agent
 */

namespace Olein\MonitorAgent\Tests;

use Olein\MonitorAgent\Activation\Activator;
use Olein\MonitorAgent\Auth\Capability;
use Olein\MonitorAgent\Auth\Role;

final class RoleTest extends \WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		remove_role( Role::NAME );
	}

	public function tear_down(): void {
		remove_role( Role::NAME );
		parent::tear_down();
	}

	public function test_activation_creates_least_privilege_role(): void {
		Activator::activate();

		$role = get_role( Role::NAME );

		$this->assertNotNull( $role );
		$this->assertSame(
			array(
				'read'           => true,
				Capability::READ => true,
			),
			$role->capabilities
		);
	}
}
