<?php
/**
 * Executes one monitor type for a bounded page of enabled sites.
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
	public const DEFAULT_BATCH_LIMIT = 20;
	public const MAX_BATCH_LIMIT     = 100;

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
		private readonly ?RetryScheduler $retries = null,
		private readonly ?BatchScheduler $batches = null,
		private readonly int $batch_limit = self::DEFAULT_BATCH_LIMIT
	) {
		if ( null !== $batches && ( $batch_limit < 1 || $batch_limit > self::MAX_BATCH_LIMIT ) ) {
			throw new InvalidArgumentException( 'The check batch limit must be between 1 and 100.' );
		}

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

		if ( null === $this->batches ) {
			return $this->process_sites( $check_type, $this->sites->enabled() );
		}

		return $this->run_batch( $check_type );
	}

	/**
	 * Resume one persisted batch generation.
	 *
	 * @return list<CheckResult>
	 */
	public function continue_batch( string $check_type, string $generation ): array {
		if ( null === $this->batches || ! isset( $this->monitors[ $check_type ] ) || ! wp_is_uuid( $generation ) ) {
			return array();
		}

		return $this->run_batch( $check_type, $generation );
	}

	/**
	 * @return list<CheckResult>
	 */
	private function run_batch( string $check_type, ?string $generation = null ): array {
		$token = $this->batches?->acquire( $check_type );

		if ( null === $token ) {
			return array();
		}

		try {
			$state = null === $generation
				? $this->batches->begin_or_resume( $check_type )
				: $this->batches->resume( $check_type, $generation );

			if ( null === $state ) {
				return array();
			}

			$this->batches->clear_continuation( $check_type, $state['generation'] );
			$sites   = $this->sites->enabled_after( $state['cursor'], $this->batch_limit );
			$results = $this->process_sites( $check_type, $sites, $state['generation'] );
			$current = $this->batches->resume( $check_type, $state['generation'] );

			if ( null === $current ) {
				return $results;
			}

			$cursor = $current['cursor'];

			if ( empty( $sites ) || empty( $this->sites->enabled_after( $cursor, 1 ) ) ) {
				$this->batches->complete( $check_type, $state['generation'] );
			} else {
				$this->batches->schedule_continuation( $check_type, $state['generation'] );
			}

			return $results;
		} finally {
			$this->batches?->release( $check_type, $token );
		}
	}

	/**
	 * @param list<Site> $sites Selected sites.
	 * @return list<CheckResult>
	 */
	private function process_sites( string $check_type, array $sites, ?string $generation = null ): array {
		$monitor = $this->monitors[ $check_type ];
		$results = array();

		foreach ( $sites as $selected_site ) {
			$site = $this->current_site( $selected_site );

			if ( null === $site ) {
				$this->advance_batch( $check_type, $generation, (int) $selected_site->id() );
				continue;
			}

			if ( null !== $this->retries && $this->retries->has_pending( $site, $check_type ) ) {
				$this->advance_batch( $check_type, $generation, (int) $site->id() );
				continue;
			}

			$result = $this->execute( $site, $monitor, $check_type, 1 );

			if ( null !== $result ) {
				$results[] = $result;
			}

			$this->advance_batch( $check_type, $generation, (int) $site->id() );
		}

		return $results;
	}

	private function current_site( Site $selected ): ?Site {
		$current = $this->sites->find( (int) $selected->id() );

		return null !== $current && $current->enabled() && hash_equals( $selected->uuid(), $current->uuid() ) ? $current : null;
	}

	private function advance_batch( string $check_type, ?string $generation, int $site_id ): void {
		if ( null !== $generation ) {
			$this->batches?->advance( $check_type, $generation, $site_id );
		}
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
