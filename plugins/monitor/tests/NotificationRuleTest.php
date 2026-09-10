<?php
/**
 * Notification rule tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use DateTimeImmutable;
use Olein\WordPressMonitor\Event\MonitoringEvent;
use Olein\WordPressMonitor\Notification\NotificationRule;

final class NotificationRuleTest extends \WP_UnitTestCase {
	private NotificationRule $rule;

	public function set_up(): void {
		parent::set_up();
		$this->rule = new NotificationRule();
	}

	/**
	 * @dataProvider selected_transition_provider
	 */
	public function test_selects_only_outage_and_recovery_transitions( string $previous, string $current, string $expected ): void {
		$this->assertSame( $expected, $this->rule->classify( $this->event( $previous, $current ) ) );
	}

	/**
	 * @return array<string,array{string,string,string}>
	 */
	public function selected_transition_provider(): array {
		return array(
			'healthy to critical' => array( 'healthy', 'critical', NotificationRule::OUTAGE ),
			'warning to critical' => array( 'warning', 'critical', NotificationRule::OUTAGE ),
			'critical to healthy' => array( 'critical', 'healthy', NotificationRule::RECOVERY ),
		);
	}

	/**
	 * @dataProvider ignored_transition_provider
	 */
	public function test_ignores_all_other_transitions( string $previous, string $current ): void {
		$this->assertNull( $this->rule->classify( $this->event( $previous, $current ) ) );
	}

	/**
	 * @return array<string,array{string,string}>
	 */
	public function ignored_transition_provider(): array {
		return array(
			'ongoing critical'    => array( 'critical', 'critical' ),
			'ongoing warning'     => array( 'warning', 'warning' ),
			'initial critical'    => array( 'unknown', 'critical' ),
			'warning recovered'   => array( 'warning', 'healthy' ),
			'critical to warning' => array( 'critical', 'warning' ),
		);
	}

	private function event( string $previous, string $current ): MonitoringEvent {
		return new MonitoringEvent(
			null,
			1,
			'SITE_DOWN',
			$previous,
			$current,
			null,
			'Changed.',
			new DateTimeImmutable( '2026-09-10T00:00:00Z' )
		);
	}
}
