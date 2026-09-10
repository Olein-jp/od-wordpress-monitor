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
use InvalidArgumentException;
use Olein\WordPressMonitor\Check\CheckRepository;
use WP_Error;

final class CheckRetention {
	public const RETENTION_DAYS      = 90;
	public const DEFAULT_BATCH_LIMIT = 300;

	/**
	 * @param null|Closure():DateTimeImmutable $clock Current time provider for deterministic execution.
	 */
	public function __construct(
		private readonly CheckRepository $checks,
		private readonly ?Closure $clock = null,
		private readonly ?OptionCleanupRepository $options = null,
		private readonly int $batch_limit = self::DEFAULT_BATCH_LIMIT
	) {
		if ( $batch_limit < 1 || ( null !== $options && $batch_limit < 3 ) ) {
			throw new InvalidArgumentException( 'The cleanup batch limit must be positive and allow every cleanup phase to run.' );
		}
	}

	/**
	 * Delete checks strictly older than the retention boundary.
	 *
	 * @return int|WP_Error Number of deleted rows or a safe failure.
	 */
	public function cleanup(): int|WP_Error {
		$now    = null === $this->clock ? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) : ( $this->clock )();
		$cutoff = $now->setTimezone( new DateTimeZone( 'UTC' ) )->sub( new DateInterval( 'P' . self::RETENTION_DAYS . 'D' ) );

		if ( null === $this->options ) {
			return $this->checks->delete_before( $cutoff, $this->batch_limit );
		}

		$deleted          = 0;
		$remaining        = $this->batch_limit;
		$remaining_phases = 3;
		$phases           = array(
			fn( int $limit ) => $this->checks->delete_before( $cutoff, $limit ),
			fn( int $limit ) => $this->options->delete_expired_locks( $now->getTimestamp(), $limit ),
			fn( int $limit ) => $this->options->delete_expired_transients( $now->getTimestamp(), $limit ),
		);

		foreach ( $phases as $phase ) {
			$limit  = max( 1, (int) ceil( $remaining / $remaining_phases ) );
			$result = $phase( $limit );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$deleted   += $result;
			$remaining -= $result;
			--$remaining_phases;
		}

		return $deleted;
	}
}
