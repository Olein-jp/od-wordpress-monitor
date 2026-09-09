<?php
/**
 * WP-Cron schedule registration and dispatch.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Scheduler;

use Olein\WordPressMonitor\Monitor\CheckResult;
use WP_Error;

final class Scheduler {
	public const HOOK               = 'odm_run_scheduled_check';
	public const CLEANUP_HOOK       = 'odm_cleanup_checks';
	public const CLEANUP_RECURRENCE = 'daily';

	public const CHECK_SCHEDULES = array(
		'http'         => 'odm_five_minutes',
		'agent_ping'   => 'odm_five_minutes',
		'agent_status' => 'odm_fifteen_minutes',
		'updates'      => 'hourly',
		'ssl'          => 'daily',
	);

	public function __construct(
		private readonly CheckRunner $runner,
		private readonly ?CheckRetention $retention = null
	) {
	}

	public function register_hooks(): void {
		add_filter( 'cron_schedules', array( self::class, 'add_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- Five minutes is the explicit monitoring requirement.
		add_action( self::HOOK, array( $this, 'run' ) );

		if ( null !== $this->retention ) {
			add_action( self::CLEANUP_HOOK, array( $this, 'cleanup' ) );
		}

		self::ensure_scheduled();
	}

	/**
	 * Add the intervals not provided by WordPress core.
	 *
	 * @param array<string,array{interval:int,display:string}> $schedules Existing schedules.
	 * @return array<string,array{interval:int,display:string}>
	 */
	public static function add_schedules( array $schedules ): array {
		$schedules['odm_five_minutes']    = array( // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- Five minutes is the explicit monitoring requirement.
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes', 'od-wordpress-monitor' ),
		);
		$schedules['odm_fifteen_minutes'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every fifteen minutes', 'od-wordpress-monitor' ),
		);

		return $schedules;
	}

	/**
	 * Idempotently register one event per check type.
	 */
	public static function ensure_scheduled(): void {
		$schedules = wp_get_schedules();

		foreach ( self::CHECK_SCHEDULES as $check_type => $recurrence ) {
			$args = array( $check_type );

			if ( false !== wp_next_scheduled( self::HOOK, $args ) || ! isset( $schedules[ $recurrence ] ) ) {
				continue;
			}

			wp_schedule_event( time() + $schedules[ $recurrence ]['interval'], $recurrence, self::HOOK, $args );
		}

		if ( false === wp_next_scheduled( self::CLEANUP_HOOK ) && isset( $schedules[ self::CLEANUP_RECURRENCE ] ) ) {
			wp_schedule_event(
				time() + $schedules[ self::CLEANUP_RECURRENCE ]['interval'],
				self::CLEANUP_RECURRENCE,
				self::CLEANUP_HOOK
			);
		}
	}

	/**
	 * Remove every event owned by this plugin.
	 */
	public static function clear_scheduled(): void {
		foreach ( array_keys( self::CHECK_SCHEDULES ) as $check_type ) {
			wp_clear_scheduled_hook( self::HOOK, array( $check_type ) );
		}

		wp_clear_scheduled_hook( self::CLEANUP_HOOK );
	}

	/**
	 * Run the same entrypoint used by WP-Cron directly.
	 *
	 * @return list<CheckResult>
	 */
	public function run( string $check_type ): array {
		return $this->runner->run( $check_type );
	}

	/**
	 * Run retention cleanup and expose only its count or safe error code.
	 *
	 * @return int|WP_Error
	 */
	public function cleanup(): int|WP_Error {
		if ( null === $this->retention ) {
			$result = new WP_Error( 'CLEANUP_UNAVAILABLE', __( 'Check cleanup is unavailable.', 'od-wordpress-monitor' ) );
			do_action( 'odm_check_cleanup_failed', $result->get_error_code() );

			return $result;
		}

		$result = $this->retention->cleanup();

		if ( is_wp_error( $result ) ) {
			do_action( 'odm_check_cleanup_failed', $result->get_error_code() );
			return $result;
		}

		do_action( 'odm_check_cleanup_completed', $result );

		return $result;
	}
}
