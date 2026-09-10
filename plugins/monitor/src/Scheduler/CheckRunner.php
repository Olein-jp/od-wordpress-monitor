<?php
/**
 * Executes one monitor type for all enabled sites.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Scheduler;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Olein\WordPressMonitor\Evaluation\CheckResultRecorder;
use Olein\WordPressMonitor\Monitor\CheckResult;
use Olein\WordPressMonitor\Monitor\MonitorInterface;
use Olein\WordPressMonitor\Site\Site;
use Olein\WordPressMonitor\Site\SiteRepository;
use Olein\WordPressMonitor\Support\ErrorCode;
use Throwable;

final class CheckRunner {
	/** @var array<string,MonitorInterface> */
	private readonly array $monitors;

	/**
	 * @param list<MonitorInterface> $monitors Available monitors.
	 */
	public function __construct(
		private readonly SiteRepository $sites,
		private readonly CheckLockInterface $lock,
		array $monitors,
		private readonly ?CheckResultRecorder $recorder = null,
		private readonly ?RetryScheduler $retries = null
	) {
		$registry = array();

		foreach ( $monitors as $monitor ) {
			if ( isset( $registry[ $monitor->get_type() ] ) ) {
				throw new InvalidArgumentException( 'Monitor types must be unique.' );
			}

			$registry[ $monitor->get_type() ] = $monitor;
		}

		$this->monitors = $registry;
	}

	/**
	 * Run one check type and return results for checks that acquired a lock.
	 *
	 * This public method is shared by WP-Cron and direct system-cron entrypoints.
	 *
	 * @return list<CheckResult>
	 */
	public function run( string $check_type ): array {
		if ( ! isset( $this->monitors[ $check_type ] ) ) {
			throw new InvalidArgumentException( 'Unknown monitor type.' );
		}

		$monitor = $this->monitors[ $check_type ];
		$results = array();

		foreach ( $this->sites->enabled() as $site ) {
			if ( null !== $this->retries && $this->retries->has_pending( $site, $check_type ) ) {
				continue;
			}

			$result = $this->execute( $site, $monitor, $check_type, 1 );

			if ( null !== $result ) {
				$results[] = $result;
			}
		}

		return $results;
	}

	/**
	 * Run one retry event after validating its persisted, non-sensitive identity.
	 */
	public function retry( int $site_id, string $site_uuid, string $check_type, int $attempt ): ?CheckResult {
		if ( null === $this->retries || ! isset( $this->monitors[ $check_type ] ) || $attempt < 2 || $attempt > RetryScheduler::MAX_ATTEMPTS ) {
			return null;
		}

		$site = $this->sites->find( $site_id );

		if ( null === $site || ! $site->enabled() || ! hash_equals( $site->uuid(), $site_uuid ) ) {
			return null;
		}

		$this->retries?->clear_attempt( $site, $check_type, $attempt );

		return $this->execute( $site, $this->monitors[ $check_type ], $check_type, $attempt );
	}

	private function execute( Site $site, MonitorInterface $monitor, string $check_type, int $attempt ): ?CheckResult {
		$token = $this->lock->acquire( $site, $check_type );

		if ( null === $token ) {
			if ( $attempt > 1 ) {
				$this->retries?->reschedule_locked( $site, $check_type, $attempt );
			}

			return null;
		}

		try {
			try {
				$result = $monitor->check( $site );
			} catch ( Throwable $exception ) {
				unset( $exception );
				$result = $this->failed_result( $site, $check_type );
			}

			if ( null !== $this->retries && $this->retries->schedule_next( $site, $result, $attempt ) ) {
				return null;
			}

			$this->retries?->clear( $site, $check_type );
			$this->publish( $result, $site );

			return $result;
		} finally {
			$this->lock->release( $site, $check_type, $token );
		}
	}

	private function publish( CheckResult $result, Site $site ): void {
		if ( null !== $this->recorder ) {
			$recorded = $this->recorder->record( $result );

			if ( is_wp_error( $recorded ) ) {
				do_action( 'odm_check_persistence_error', $recorded->get_error_code(), $result, $site );
			}
		}

		do_action( 'odm_check_result', $result, $site );
	}

	private function failed_result( Site $site, string $check_type ): CheckResult {
		$now = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );

		return new CheckResult(
			(int) $site->id(),
			$check_type,
			CheckResult::STATUS_UNKNOWN,
			ErrorCode::RUNNER_ERROR,
			__( 'The scheduled check could not be completed.', 'od-wordpress-monitor' ),
			$now,
			$now,
			0
		);
	}
}
