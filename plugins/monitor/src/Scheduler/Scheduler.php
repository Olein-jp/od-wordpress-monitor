<?php
/**
 * WP-Cron schedule registration and dispatch.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Scheduler;

use Olein\WordPressMonitor\Monitor\CheckResult;

final class Scheduler {
	public const HOOK = 'odm_run_scheduled_check';

	public const CHECK_SCHEDULES = array(
		'http'         => 'odm_five_minutes',
		'agent_ping'   => 'odm_five_minutes',
		'agent_status' => 'odm_fifteen_minutes',
		'updates'      => 'hourly',
		'ssl'          => 'daily',
	);

	public function __construct( private readonly CheckRunner $runner ) {
	}

	public function register_hooks(): void {
		add_filter( 'cron_schedules', array( self::class, 'add_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- Five minutes is the explicit monitoring requirement.
		add_action( self::HOOK, array( $this, 'run' ) );
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
	}

	/**
	 * Remove every event owned by this plugin.
	 */
	public static function clear_scheduled(): void {
		foreach ( array_keys( self::CHECK_SCHEDULES ) as $check_type ) {
			wp_clear_scheduled_hook( self::HOOK, array( $check_type ) );
		}
	}

	/**
	 * Run the same entrypoint used by WP-Cron directly.
	 *
	 * @return list<CheckResult>
	 */
	public function run( string $check_type ): array {
		return $this->runner->run( $check_type );
	}
}
