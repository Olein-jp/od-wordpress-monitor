<?php
/**
 * Schedules bounded retries for transient monitoring failures.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Scheduler;

use Olein\WordPressMonitor\Monitor\CheckResult;
use Olein\WordPressMonitor\Site\Site;
use Olein\WordPressMonitor\Support\ErrorCode;

final class RetryScheduler {
	public const HOOK         = 'odm_retry_scheduled_check';
	public const MAX_ATTEMPTS = 3;

	private const LOCK_RETRY_DELAY = 60;

	private const RETRY_DELAYS = array(
		1 => 60,
		2 => 300,
	);

	/**
	 * Schedule the next attempt when the result represents a transient failure.
	 */
	public function schedule_next( Site $site, CheckResult $result, int $attempt ): bool {
		if ( ! $this->is_retryable( $result ) || $attempt >= self::MAX_ATTEMPTS ) {
			return false;
		}

		$delay = self::RETRY_DELAYS[ $attempt ] ?? null;

		if ( null === $delay ) {
			return false;
		}

		return $this->schedule( $site, $result->type(), $attempt + 1, $delay );
	}

	/**
	 * Keep a retry pending when another process currently owns the execution lock.
	 */
	public function reschedule_locked( Site $site, string $check_type, int $attempt ): bool {
		if ( $attempt < 2 || $attempt > self::MAX_ATTEMPTS ) {
			return false;
		}

		return $this->schedule( $site, $check_type, $attempt, self::LOCK_RETRY_DELAY );
	}

	public function has_pending( Site $site, string $check_type ): bool {
		foreach ( range( 2, self::MAX_ATTEMPTS ) as $attempt ) {
			if ( false !== wp_next_scheduled( self::HOOK, $this->arguments( $site, $check_type, $attempt ) ) ) {
				return true;
			}
		}

		return false;
	}

	public function clear( Site $site, string $check_type ): void {
		foreach ( range( 2, self::MAX_ATTEMPTS ) as $attempt ) {
			wp_clear_scheduled_hook( self::HOOK, $this->arguments( $site, $check_type, $attempt ) );
		}
	}

	public function clear_attempt( Site $site, string $check_type, int $attempt ): void {
		if ( $attempt >= 2 && $attempt <= self::MAX_ATTEMPTS ) {
			wp_clear_scheduled_hook( self::HOOK, $this->arguments( $site, $check_type, $attempt ) );
		}
	}

	public function is_retryable( CheckResult $result ): bool {
		if ( in_array( $result->error_code(), array( ErrorCode::TIMEOUT, ErrorCode::CONNECTION_ERROR ), true ) ) {
			return true;
		}

		if ( ErrorCode::HTTP_STATUS !== $result->error_code() ) {
			return false;
		}

		$status = $result->data()['http_status'] ?? null;

		return is_int( $status ) && ( in_array( $status, array( 408, 425, 429 ), true ) || ( $status >= 500 && $status <= 599 ) );
	}

	private function schedule( Site $site, string $check_type, int $attempt, int $delay ): bool {
		$args = $this->arguments( $site, $check_type, $attempt );

		if ( false !== wp_next_scheduled( self::HOOK, $args ) ) {
			return true;
		}

		return true === wp_schedule_single_event( time() + $delay, self::HOOK, $args, true );
	}

	/**
	 * Store only immutable site identity and bounded retry metadata in cron.
	 *
	 * @return array{int,string,string,int}
	 */
	private function arguments( Site $site, string $check_type, int $attempt ): array {
		return array( (int) $site->id(), $site->uuid(), $check_type, $attempt );
	}
}
