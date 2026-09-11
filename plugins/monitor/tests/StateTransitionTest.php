<?php
/**
 * State transition tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use DateTimeImmutable;
use Olein\WordPressMonitor\Evaluation\StateTransition;
use Olein\WordPressMonitor\Event\EventType;
use Olein\WordPressMonitor\Monitor\CheckResult;
use Olein\WordPressMonitor\Monitor\Status;
use Olein\WordPressMonitor\Status\SiteStatus;

final class StateTransitionTest extends \WP_UnitTestCase {
	private StateTransition $transition;

	public function set_up(): void {
		parent::set_up();
		$this->transition = new StateTransition();
	}

	/**
	 * @dataProvider worsening_transition_provider
	 */
	public function test_creates_events_for_meaningful_worsening( string $type, string $previous_status, string $current_status, string $event_type ): void {
		$result   = $this->result( $type, $current_status );
		$previous = $this->status( $type, $previous_status );
		$current  = $this->status( $type, $current_status );
		$event    = $this->transition->detect( $previous, $current, $result );

		$this->assertNotNull( $event );
		$this->assertSame( $event_type, $event->type() );
		$this->assertSame( $previous_status, $event->previous_status() );
		$this->assertSame( $current_status, $event->current_status() );
		$this->assertSame( $type, $event->metadata()['check_type'] );
	}

	/**
	 * @return array<string,array{string,string,string,string}>
	 */
	public function worsening_transition_provider(): array {
		return array(
			'site down'            => array( 'http', Status::HEALTHY, Status::CRITICAL, EventType::SITE_DOWN ),
			'agent failure'        => array( 'agent_ping', Status::HEALTHY, Status::CRITICAL, EventType::AGENT ),
			'updates found'        => array( 'updates', Status::HEALTHY, Status::WARNING, EventType::UPDATES ),
			'ssl expiring'         => array( 'ssl', Status::HEALTHY, Status::WARNING, EventType::SSL ),
			'ssl escalated'        => array( 'ssl', Status::WARNING, Status::CRITICAL, EventType::SSL ),
			'site health critical' => array( 'site_health', Status::HEALTHY, Status::CRITICAL, EventType::SITE_HEALTH_CRITICAL ),
		);
	}

	public function test_creates_site_health_recovery_only_from_critical(): void {
		$result = $this->result( 'site_health', Status::HEALTHY );
		$event  = $this->transition->detect(
			$this->status( 'site_health', Status::CRITICAL ),
			$this->status( 'site_health', Status::HEALTHY ),
			$result
		);

		$this->assertNotNull( $event );
		$this->assertSame( EventType::SITE_HEALTH_RECOVERED, $event->type() );
		$this->assertNull(
			$this->transition->detect(
				$this->status( 'site_health', Status::WARNING ),
				$this->status( 'site_health', Status::HEALTHY ),
				$result
			)
		);
	}

	public function test_creates_site_health_partial_recovery_from_critical_to_warning(): void {
		$result = $this->result( 'site_health', Status::WARNING );
		$event  = $this->transition->detect(
			$this->status( 'site_health', Status::CRITICAL ),
			$this->status( 'site_health', Status::WARNING ),
			$result
		);

		$this->assertNotNull( $event );
		$this->assertSame( EventType::SITE_HEALTH_PARTIALLY_RECOVERED, $event->type() );
		$this->assertSame( Status::CRITICAL, $event->previous_status() );
		$this->assertSame( Status::WARNING, $event->current_status() );
	}

	public function test_ignores_initial_site_health_recommendation(): void {
		$this->assertNull(
			$this->transition->detect(
				$this->status( 'site_health', Status::UNKNOWN ),
				$this->status( 'site_health', Status::WARNING ),
				$this->result( 'site_health', Status::WARNING )
			)
		);
	}

	public function test_creates_a_recovery_event(): void {
		$result = $this->result( 'http', Status::HEALTHY );
		$event  = $this->transition->detect(
			$this->status( 'http', Status::CRITICAL ),
			$this->status( 'http', Status::HEALTHY ),
			$result
		);

		$this->assertNotNull( $event );
		$this->assertSame( EventType::RECOVERED, $event->type() );
	}

	public function test_creates_an_event_when_the_first_known_state_is_a_failure(): void {
		$result = $this->result( 'http', Status::CRITICAL );
		$event  = $this->transition->detect(
			$this->status( 'http', Status::UNKNOWN ),
			$this->status( 'http', Status::CRITICAL ),
			$result
		);

		$this->assertNotNull( $event );
		$this->assertSame( EventType::SITE_DOWN, $event->type() );
	}

	/**
	 * @dataProvider ignored_transition_provider
	 */
	public function test_ignores_unknown_unchanged_and_partial_recovery( string $previous_status, string $current_status ): void {
		$result = $this->result( 'ssl', $current_status );

		$this->assertNull(
			$this->transition->detect(
				$this->status( 'ssl', $previous_status ),
				$this->status( 'ssl', $current_status ),
				$result
			)
		);
	}

	/**
	 * @return array<string,array{string,string}>
	 */
	public function ignored_transition_provider(): array {
		return array(
			'initial healthy'      => array( Status::UNKNOWN, Status::HEALTHY ),
			'unknown check result' => array( Status::HEALTHY, Status::UNKNOWN ),
			'ongoing failure'      => array( Status::CRITICAL, Status::CRITICAL ),
			'ongoing warning'      => array( Status::WARNING, Status::WARNING ),
			'partial recovery'     => array( Status::CRITICAL, Status::WARNING ),
		);
	}

	private function status( string $type, string $status ): SiteStatus {
		$arguments = array(
			'site_id'            => 1,
			'http_status'        => Status::HEALTHY,
			'agent_status'       => Status::HEALTHY,
			'updates_status'     => Status::HEALTHY,
			'site_health_status' => Status::HEALTHY,
			'ssl_status'         => Status::HEALTHY,
		);

		$arguments[ match ( $type ) {
			'http'                       => 'http_status',
			'agent_ping', 'agent_status' => 'agent_status',
			'updates'                    => 'updates_status',
			'site_health'                => 'site_health_status',
			'ssl'                        => 'ssl_status',
		} ] = $status;

		return new SiteStatus( ...$arguments );
	}

	private function result( string $type, string $status ): CheckResult {
		$time = new DateTimeImmutable( '2026-09-09T00:00:00Z' );

		return new CheckResult( 1, $type, $status, null, 'Changed.', $time, $time, 1, array( 'source' => $type ) );
	}
}
