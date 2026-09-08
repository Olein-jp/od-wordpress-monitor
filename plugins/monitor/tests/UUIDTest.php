<?php
/**
 * UUID tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use Olein\WordPressMonitor\Support\UUID;

final class UUIDTest extends \WP_UnitTestCase {
	public function test_generates_valid_version_four_uuid(): void {
		$uuid = new UUID();

		$this->assertTrue( $uuid->is_valid( $uuid->generate() ) );
	}
}
