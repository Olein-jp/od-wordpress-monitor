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
use Olein\WordPressMonitor\Monitor\CheckResult;
use Olein\WordPressMonitor\Monitor\MonitorInterface;
use Olein\WordPressMonitor\Site\Site;
use Olein\WordPressMonitor\Site\SiteRepository;
use Throwable;

final class CheckRunner {
	/** @var array<string,MonitorInterface> */
	private readonly array $monitors;

	/**
	 * @param list<MonitorInterface> $monitors Available monitors.
	 */
	public function __construct( private readonly SiteRepository $sites, private readonly CheckLockInterface $lock, array $monitors ) {
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
			$token = $this->lock->acquire( $site, $check_type );

			if ( null === $token ) {
				continue;
			}

			try {
				$result = $monitor->check( $site );
			} catch ( Throwable $exception ) {
				unset( $exception );
				$result = $this->failed_result( $site, $check_type );
			} finally {
				$this->lock->release( $site, $check_type, $token );
			}

			$results[] = $result;
			do_action( 'odm_check_result', $result, $site );
		}

		return $results;
	}

	private function failed_result( Site $site, string $check_type ): CheckResult {
		$now = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );

		return new CheckResult(
			(int) $site->id(),
			$check_type,
			CheckResult::STATUS_UNKNOWN,
			'RUNNER_ERROR',
			__( 'The scheduled check could not be completed.', 'od-wordpress-monitor' ),
			$now,
			$now,
			0
		);
	}
}
