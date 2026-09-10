<?php
/**
 * Minimal scheduler execution heartbeat and health read model.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Scheduler;

use Closure;

final class SchedulerHeartbeat {
	public const OPTION = 'odm_scheduler_heartbeat';

	public const RESULT_RUNNING = 'running';
	public const RESULT_SUCCESS = 'success';
	public const RESULT_FAILED  = 'failed';

	public const HEALTH_HEALTHY = 'healthy';
	public const HEALTH_STALE   = 'stale';

	private const STALE_INTERVALS = 2;

	private readonly Closure $clock;

	/**
	 * @param null|Closure():int $clock Unix timestamp provider.
	 */
	public function __construct( ?Closure $clock = null ) {
		$this->clock = $clock ?? static fn(): int => time();
	}

	public function record_started( string $job ): void {
		$records         = $this->records();
		$previous        = $records[ $job ] ?? array();
		$records[ $job ] = array(
			'last_started_at'   => $this->now(),
			'last_completed_at' => $this->nullable_integer( $previous['last_completed_at'] ?? null ),
			'result'            => self::RESULT_RUNNING,
			'processed'         => $this->non_negative_integer( $previous['processed'] ?? 0 ),
		);

		$this->save( $records );
	}

	public function record_completed( string $job, int $processed ): void {
		$this->record_finished( $job, self::RESULT_SUCCESS, $processed );
	}

	public function record_failed( string $job ): void {
		$this->record_finished( $job, self::RESULT_FAILED, 0 );
	}

	/**
	 * Return all scheduler jobs with their current timing health.
	 *
	 * @return list<array{
	 *     job:string,
	 *     recurrence:string,
	 *     interval:int,
	 *     last_started_at:?int,
	 *     last_completed_at:?int,
	 *     result:string,
	 *     processed:int,
	 *     next_run:?int,
	 *     health:string
	 * }>
	 */
	public function snapshot(): array {
		$jobs      = array();
		$records   = $this->records();
		$schedules = wp_get_schedules();

		foreach ( Scheduler::CHECK_SCHEDULES as $job => $recurrence ) {
			$interval = isset( $schedules[ $recurrence ]['interval'] ) ? (int) $schedules[ $recurrence ]['interval'] : 0;
			$jobs[]   = $this->job_status( $job, $recurrence, $interval, Scheduler::HOOK, array( $job ), $records[ $job ] ?? array() );
		}

		$cleanup_interval = isset( $schedules[ Scheduler::CLEANUP_RECURRENCE ]['interval'] ) ? (int) $schedules[ Scheduler::CLEANUP_RECURRENCE ]['interval'] : 0;
		$jobs[]           = $this->job_status(
			Scheduler::CLEANUP_JOB,
			Scheduler::CLEANUP_RECURRENCE,
			$cleanup_interval,
			Scheduler::CLEANUP_HOOK,
			array(),
			$records[ Scheduler::CLEANUP_JOB ] ?? array()
		);

		return $jobs;
	}

	private function record_finished( string $job, string $result, int $processed ): void {
		$records         = $this->records();
		$previous        = $records[ $job ] ?? array();
		$records[ $job ] = array(
			'last_started_at'   => $this->nullable_integer( $previous['last_started_at'] ?? null ),
			'last_completed_at' => $this->now(),
			'result'            => $result,
			'processed'         => max( 0, $processed ),
		);

		$this->save( $records );
	}

	/**
	 * @param list<string|int>          $args   Cron event arguments.
	 * @param array<string,mixed>       $record Stored heartbeat record.
	 * @return array{
	 *     job:string,
	 *     recurrence:string,
	 *     interval:int,
	 *     last_started_at:?int,
	 *     last_completed_at:?int,
	 *     result:string,
	 *     processed:int,
	 *     next_run:?int,
	 *     health:string
	 * }
	 */
	private function job_status( string $job, string $recurrence, int $interval, string $hook, array $args, array $record ): array {
		$next_run   = wp_next_scheduled( $hook, $args );
		$next_run   = false === $next_run ? null : $next_run;
		$started_at = $this->nullable_integer( $record['last_started_at'] ?? null );
		$completed  = $this->nullable_integer( $record['last_completed_at'] ?? null );
		$result     = isset( $record['result'] ) && in_array( $record['result'], array( self::RESULT_RUNNING, self::RESULT_SUCCESS, self::RESULT_FAILED ), true )
			? $record['result']
			: 'unknown';

		return array(
			'job'               => $job,
			'recurrence'        => $recurrence,
			'interval'          => $interval,
			'last_started_at'   => $started_at,
			'last_completed_at' => $completed,
			'result'            => $result,
			'processed'         => $this->non_negative_integer( $record['processed'] ?? 0 ),
			'next_run'          => $next_run,
			'health'            => $this->health( $interval, $next_run, $started_at, $completed, $result ),
		);
	}

	private function health( int $interval, ?int $next_run, ?int $started_at, ?int $completed_at, string $result ): string {
		if ( 1 > $interval || null === $next_run ) {
			return self::HEALTH_STALE;
		}

		$stale_before = $this->now() - ( $interval * self::STALE_INTERVALS );

		if ( self::RESULT_RUNNING === $result ) {
			return null !== $started_at && $started_at <= $stale_before ? self::HEALTH_STALE : self::HEALTH_HEALTHY;
		}

		if ( null !== $completed_at ) {
			return $completed_at <= $stale_before ? self::HEALTH_STALE : self::HEALTH_HEALTHY;
		}

		return $next_run <= $stale_before ? self::HEALTH_STALE : self::HEALTH_HEALTHY;
	}

	/**
	 * Return only valid, bounded heartbeat fields from storage.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function records(): array {
		$stored = get_option( self::OPTION, array() );
		$valid  = array();

		if ( ! is_array( $stored ) ) {
			return $valid;
		}

		$allowed_jobs = array_merge( array_keys( Scheduler::CHECK_SCHEDULES ), array( Scheduler::CLEANUP_JOB ) );

		foreach ( $allowed_jobs as $job ) {
			if ( ! isset( $stored[ $job ] ) || ! is_array( $stored[ $job ] ) ) {
				continue;
			}

			$record        = $stored[ $job ];
			$stored_result = $record['result'] ?? 'unknown';
			$valid[ $job ] = array(
				'last_started_at'   => $this->nullable_integer( $record['last_started_at'] ?? null ),
				'last_completed_at' => $this->nullable_integer( $record['last_completed_at'] ?? null ),
				'result'            => is_string( $stored_result ) && in_array( $stored_result, array( self::RESULT_RUNNING, self::RESULT_SUCCESS, self::RESULT_FAILED ), true ) ? $stored_result : 'unknown',
				'processed'         => $this->non_negative_integer( $record['processed'] ?? 0 ),
			);
		}

		return $valid;
	}

	/**
	 * @param array<string,array<string,mixed>> $records Heartbeat records.
	 */
	private function save( array $records ): void {
		update_option( self::OPTION, $records, false );
	}

	private function now(): int {
		return ( $this->clock )();
	}

	/**
	 * @param mixed $value Stored value.
	 */
	private function nullable_integer( $value ): ?int {
		return is_int( $value ) && 0 < $value ? $value : null;
	}

	/**
	 * @param mixed $value Stored value.
	 */
	private function non_negative_integer( $value ): int {
		return is_int( $value ) ? max( 0, $value ) : 0;
	}
}
