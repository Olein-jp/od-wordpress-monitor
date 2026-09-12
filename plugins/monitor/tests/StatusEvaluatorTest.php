<?php
/**
 * Status evaluator tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use Olein\WordPressMonitor\Evaluation\StatusEvaluator;
use Olein\WordPressMonitor\Monitor\CheckResult;
use Olein\WordPressMonitor\Monitor\Status;
use Olein\WordPressMonitor\Status\SiteStatus;

final class StatusEvaluatorTest extends \WP_UnitTestCase {
	private StatusEvaluator $evaluator;

	public function set_up(): void {
		parent::set_up();
		$this->evaluator = new StatusEvaluator();
	}

	/**
	 * @dataProvider overall_status_provider
	 */
	public function test_evaluates_overall_status_by_priority( SiteStatus $previous, CheckResult $result, string $expected ): void {
		$this->assertSame( $expected, $this->evaluator->apply( $previous, $result )->overall_status() );
	}

	/**
	 * @return array<string,array{SiteStatus,CheckResult,string}>
	 */
	public function overall_status_provider(): array {
		$healthy = new SiteStatus(
			site_id: 1,
			http_status: Status::HEALTHY,
			agent_status: Status::HEALTHY,
			updates_status: Status::HEALTHY,
			site_health_status: Status::HEALTHY,
			ssl_status: Status::HEALTHY
		);

		return array(
			'all healthy'          => array( $healthy, $this->result( 'http', Status::HEALTHY ), Status::HEALTHY ),
			'http critical'        => array( $healthy, $this->result( 'http', Status::CRITICAL ), Status::CRITICAL ),
			'agent critical'       => array( $healthy, $this->result( 'agent_ping', Status::CRITICAL ), Status::WARNING ),
			'updates warning'      => array( $healthy, $this->result( 'updates', Status::WARNING ), Status::WARNING ),
			'updates unavailable'  => array( $healthy, $this->result( 'updates', Status::CRITICAL ), Status::WARNING ),
			'site health warning'  => array( $healthy, $this->result( 'site_health', Status::WARNING ), Status::WARNING ),
			'site health critical' => array( $healthy, $this->result( 'site_health', Status::CRITICAL ), Status::CRITICAL ),
			'ssl warning'          => array( $healthy, $this->result( 'ssl', Status::WARNING ), Status::WARNING ),
			'ssl critical'         => array( $healthy, $this->result( 'ssl', Status::CRITICAL ), Status::CRITICAL ),
			'incomplete knowledge' => array( new SiteStatus( 1 ), $this->result( 'http', Status::HEALTHY ), Status::UNKNOWN ),
		);
	}

	public function test_updates_site_health_state_checked_at_and_metadata(): void {
		$finished = new DateTimeImmutable( '2026-09-10T05:00:00Z' );
		$result   = $this->result(
			'site_health',
			Status::WARNING,
			$finished,
			array(
				'critical'     => 0,
				'recommended'  => 1,
				'good'         => 4,
				'collected_at' => '2026-09-10T04:59:00Z',
				'issues'       => array(
					array(
						'id'     => 'utf8mb4_support',
						'status' => 'recommended',
						'label'  => 'Use utf8mb4',
					),
				),
				'label'        => 'Discarded',
			)
		);
		$current  = $this->evaluator->apply( new SiteStatus( 1 ), $result );

		$this->assertSame( Status::WARNING, $current->site_health_status() );
		$this->assertSame( $finished, $current->site_health_checked_at() );
		$this->assertSame( $finished, $current->last_checked_at() );
		$this->assertSame( 'utf8mb4_support', $current->metadata()['site_health']['issues'][0]['id'] );
		$this->assertSame( '2026-09-10T04:59:00Z', $current->metadata()['site_health']['collected_at'] );
		$this->assertArrayNotHasKey( 'label', $current->metadata()['site_health'] );
	}

	public function test_updates_only_the_matching_check_state_and_safe_metadata(): void {
		$finished = new DateTimeImmutable( '2026-09-09T05:00:00Z' );
		$result   = $this->result( 'agent_status', Status::HEALTHY, $finished, array( 'wordpress' => '6.9' ) );
		$current  = $this->evaluator->apply( new SiteStatus( 1 ), $result );

		$this->assertSame( Status::HEALTHY, $current->agent_status() );
		$this->assertSame( Status::UNKNOWN, $current->http_status() );
		$this->assertSame( $finished, $current->agent_checked_at() );
		$this->assertSame( $finished, $current->last_checked_at() );
		$this->assertSame( array( 'wordpress' => '6.9' ), $current->metadata()['agent_status'] );
	}

	public function test_preserves_last_successful_inventory_when_update_check_fails(): void {
		$inventory = array(
			'wordpress_version' => '7.1',
			'theme'             => null,
			'plugins'           => array(),
			'collected_at'      => '2026-09-10T00:00:00Z',
			'truncated'         => false,
		);
		$previous  = new SiteStatus(
			1,
			metadata: array(
				'updates' => array(
					'total_updates'      => 0,
					'software_inventory' => $inventory,
				),
			)
		);
		$failed    = new CheckResult(
			1,
			'updates',
			Status::CRITICAL,
			'CONNECTION_ERROR',
			'Failed.',
			new DateTimeImmutable( '2026-09-10T01:00:00Z' ),
			new DateTimeImmutable( '2026-09-10T01:00:00Z' ),
			1,
			array( 'endpoint' => 'updates' )
		);

		$current = $this->evaluator->apply( $previous, $failed );

		$this->assertSame( $inventory, $current->metadata()['updates']['software_inventory'] );
	}

	public function test_rejects_a_result_for_another_site(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->evaluator->apply( new SiteStatus( 2 ), $this->result( 'http', Status::HEALTHY ) );
	}

	/**
	 * @param array<string|int,mixed> $data Result metadata.
	 */
	private function result( string $type, string $status, ?DateTimeImmutable $time = null, array $data = array() ): CheckResult {
		$time = $time ?? new DateTimeImmutable( '2026-09-09T00:00:00Z' );

		return new CheckResult( 1, $type, $status, null, 'Checked.', $time, $time, 1, $data );
	}
}
