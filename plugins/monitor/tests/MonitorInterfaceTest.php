<?php
/**
 * Shared monitor contract tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use DateTimeImmutable;
use Olein\WordPressMonitor\Monitor\CheckResult;
use Olein\WordPressMonitor\Monitor\MonitorInterface;
use Olein\WordPressMonitor\Site\Site;

final class MonitorInterfaceTest extends \WP_UnitTestCase {
	public function test_monitor_implementation_fulfills_contract(): void {
		$monitor = new class() implements MonitorInterface {
			public function get_type(): string {
				return 'test';
			}

			public function check( Site $site ): CheckResult {
				$now = new DateTimeImmutable( '2026-09-09T09:00:00+00:00' );

				return new CheckResult(
					(int) $site->id(),
					$this->get_type(),
					CheckResult::STATUS_HEALTHY,
					null,
					'Check passed.',
					$now,
					$now,
					0
				);
			}
		};
		$site    = new Site( 7, wp_generate_uuid4(), 'Example', 'https://example.com', 'https://example.com/wp-json/od-monitor-agent/v1' );
		$result  = $monitor->check( $site );

		$this->assertSame( 'test', $monitor->get_type() );
		$this->assertInstanceOf( CheckResult::class, $result );
		$this->assertSame( 7, $result->site_id() );
	}
}
