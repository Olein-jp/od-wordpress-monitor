<?php
/**
 * Check history retention cleanup.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Scheduler;

use Closure;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Olein\WordPressMonitor\Check\CheckRepository;
use WP_Error;

final class CheckRetention {
	public const RETENTION_DAYS = 90;

	/**
	 * @param null|Closure():DateTimeImmutable $clock Current time provider for deterministic execution.
	 */
	public function __construct(
		private readonly CheckRepository $checks,
		private readonly ?Closure $clock = null
	) {
	}

	/**
	 * Delete checks strictly older than the retention boundary.
	 *
	 * @return int|WP_Error Number of deleted rows or a safe failure.
	 */
	public function cleanup(): int|WP_Error {
		$now    = null === $this->clock ? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) : ( $this->clock )();
		$cutoff = $now->setTimezone( new DateTimeZone( 'UTC' ) )->sub( new DateInterval( 'P' . self::RETENTION_DAYS . 'D' ) );

		return $this->checks->delete_before( $cutoff );
	}
}
