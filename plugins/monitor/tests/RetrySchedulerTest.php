<?php
/**
 * Monitoring retry policy tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use DateTimeImmutable;
use Olein\WordPressMonitor\Monitor\CheckResult;
use Olein\WordPressMonitor\Monitor\Status;
use Olein\WordPressMonitor\Scheduler\RetryScheduler;
use Olein\WordPressMonitor\Support\ErrorCode;

final class RetrySchedulerTest extends \WP_UnitTestCase {
	/**
	 * @dataProvider transient_error_provider
	 *
	 * @param array<string,int> $data Safe result metadata.
	 */
	public function test_transient_failures_are_retryable( string $error_code, array $data ): void {
		$this->assertTrue( ( new RetryScheduler() )->is_retryable( $this->result( $error_code, $data ) ) );
	}

	/**
	 * @return list<array{string,array<string,int>}>
	 */
	public function transient_error_provider(): array {
		return array(
			array( ErrorCode::TIMEOUT, array() ),
			array( ErrorCode::CONNECTION_ERROR, array() ),
			array( ErrorCode::HTTP_STATUS, array( 'http_status' => 408 ) ),
			array( ErrorCode::HTTP_STATUS, array( 'http_status' => 425 ) ),
			array( ErrorCode::HTTP_STATUS, array( 'http_status' => 429 ) ),
			array( ErrorCode::HTTP_STATUS, array( 'http_status' => 500 ) ),
			array( ErrorCode::HTTP_STATUS, array( 'http_status' => 599 ) ),
		);
	}

	/**
	 * @dataProvider permanent_error_provider
	 *
	 * @param array<string,int> $data Safe result metadata.
	 */
	public function test_permanent_failures_are_not_retryable( string $error_code, array $data ): void {
		$this->assertFalse( ( new RetryScheduler() )->is_retryable( $this->result( $error_code, $data ) ) );
	}

	/**
	 * @return list<array{string,array<string,int>}>
	 */
	public function permanent_error_provider(): array {
		return array(
			array( ErrorCode::AUTHENTICATION_FAILED, array() ),
			array( ErrorCode::PERMISSION_DENIED, array() ),
			array( ErrorCode::INVALID_URL, array() ),
			array( ErrorCode::INVALID_RESPONSE, array() ),
			array( ErrorCode::CERTIFICATE_VALIDATION_FAILED, array() ),
			array( ErrorCode::RUNNER_ERROR, array() ),
			array( ErrorCode::HTTP_STATUS, array( 'http_status' => 400 ) ),
			array( ErrorCode::HTTP_STATUS, array( 'http_status' => 404 ) ),
		);
	}

	/**
	 * @param array<string,int> $data Safe result metadata.
	 */
	private function result( string $error_code, array $data ): CheckResult {
		$now = new DateTimeImmutable( '2026-09-11T00:00:00Z' );

		return new CheckResult( 1, 'http', Status::CRITICAL, $error_code, 'Failed safely.', $now, $now, 0, $data );
	}
}
