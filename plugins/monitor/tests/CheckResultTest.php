<?php
/**
 * Common monitor result tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use Olein\WordPressMonitor\Monitor\CheckResult;

final class CheckResultTest extends \WP_UnitTestCase {
	public function test_exposes_required_result_fields(): void {
		$started_at  = new DateTimeImmutable( '2026-09-09T09:00:00+00:00' );
		$finished_at = new DateTimeImmutable( '2026-09-09T09:00:01+00:00' );
		$result      = new CheckResult(
			12,
			'agent_ping',
			CheckResult::STATUS_HEALTHY,
			null,
			'Agent is reachable.',
			$started_at,
			$finished_at,
			125,
			array( 'http_status' => 200 )
		);

		$this->assertSame( 12, $result->site_id() );
		$this->assertSame( 'agent_ping', $result->type() );
		$this->assertSame( 'healthy', $result->status() );
		$this->assertNull( $result->error_code() );
		$this->assertSame( 'Agent is reachable.', $result->message() );
		$this->assertSame( $started_at, $result->started_at() );
		$this->assertSame( $finished_at, $result->finished_at() );
		$this->assertSame( 125, $result->duration_ms() );
		$this->assertSame( array( 'http_status' => 200 ), $result->data() );
	}

	/**
	 * @dataProvider invalid_result_provider
	 *
	 * @param callable():CheckResult $factory Invalid result factory.
	 */
	public function test_rejects_invalid_result_values( callable $factory ): void {
		$this->expectException( InvalidArgumentException::class );
		$factory();
	}

	/**
	 * @return array<string,array{callable():CheckResult}>
	 */
	public function invalid_result_provider(): array {
		return array(
			'invalid site ID'    => array( fn() => $this->result( site_id: 0 ) ),
			'invalid type'       => array( fn() => $this->result( type: 'Agent Ping' ) ),
			'invalid status'     => array( fn() => $this->result( status: 'success' ) ),
			'invalid error code' => array( fn() => $this->result( error_code: 'bad code' ) ),
			'reversed time'      => array(
				fn() => $this->result(
					started_at: new DateTimeImmutable( '2026-09-09T09:00:01+00:00' ),
					finished_at: new DateTimeImmutable( '2026-09-09T09:00:00+00:00' )
				),
			),
			'negative duration'  => array( fn() => $this->result( duration_ms: -1 ) ),
		);
	}

	/**
	 * @dataProvider sensitive_result_provider
	 *
	 * @param callable():CheckResult $factory Unsafe result factory.
	 */
	public function test_rejects_sensitive_result_content( callable $factory ): void {
		$this->expectException( InvalidArgumentException::class );
		$factory();
	}

	/**
	 * @return array<string,array{callable():CheckResult}>
	 */
	public function sensitive_result_provider(): array {
		return array(
			'authorization message' => array( fn() => $this->result( message: 'Authorization: Basic YWdlbnQ6c2VjcmV0' ) ),
			'password field'        => array( fn() => $this->result( data: array( 'password' => 'secret' ) ) ),
			'authorization header'  => array( fn() => $this->result( data: array( 'authorization_header' => 'Basic YWdlbnQ6c2VjcmV0' ) ) ),
			'nested credential'     => array( fn() => $this->result( data: array( 'request' => array( 'credential' => 'secret' ) ) ) ),
			'object data'           => array( fn() => $this->result( data: array( 'response' => new \stdClass() ) ) ),
			'non-finite number'     => array( fn() => $this->result( data: array( 'latency' => INF ) ) ),
		);
	}

	/**
	 * Build a result with overridable values.
	 *
	 * @param array<string|int,mixed> $data Result metadata.
	 */
	private function result(
		int $site_id = 1,
		string $type = 'test',
		string $status = CheckResult::STATUS_UNKNOWN,
		?string $error_code = null,
		string $message = '',
		?DateTimeImmutable $started_at = null,
		?DateTimeImmutable $finished_at = null,
		int $duration_ms = 0,
		array $data = array()
	): CheckResult {
		$started_at  = $started_at ?? new DateTimeImmutable( '2026-09-09T09:00:00+00:00' );
		$finished_at = $finished_at ?? $started_at;

		return new CheckResult( $site_id, $type, $status, $error_code, $message, $started_at, $finished_at, $duration_ms, $data );
	}
}
