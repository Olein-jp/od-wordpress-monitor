<?php
/**
 * Scheduler heartbeat tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Olein\WordPressMonitor\Activation\DatabaseMigrator;
use Olein\WordPressMonitor\Monitor\CheckResult;
use Olein\WordPressMonitor\Monitor\MonitorInterface;
use Olein\WordPressMonitor\Scheduler\CheckLockInterface;
use Olein\WordPressMonitor\Scheduler\CheckRunner;
use Olein\WordPressMonitor\Scheduler\Scheduler;
use Olein\WordPressMonitor\Scheduler\SchedulerHeartbeat;
use Olein\WordPressMonitor\Site\Site;
use Olein\WordPressMonitor\Site\SiteRepository;

final class SchedulerHeartbeatTest extends \WP_UnitTestCase {
	private int $now;
	private SchedulerHeartbeat $heartbeat;

	public function set_up(): void {
		parent::set_up();
		$this->now       = time();
		$this->heartbeat = new SchedulerHeartbeat( fn(): int => $this->now );
		delete_option( SchedulerHeartbeat::OPTION );
		Scheduler::clear_scheduled();
		add_filter( 'cron_schedules', array( Scheduler::class, 'add_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- Uses the production schedule definitions.
	}

	public function tear_down(): void {
		delete_option( SchedulerHeartbeat::OPTION );
		Scheduler::clear_scheduled();
		remove_filter( 'cron_schedules', array( Scheduler::class, 'add_schedules' ) );
		parent::tear_down();
	}

	public function test_direct_scheduler_run_records_successful_heartbeat(): void {
		global $wpdb;

		( new DatabaseMigrator( $wpdb ) )->migrate();
		$sites = new SiteRepository( $wpdb );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}odm_sites" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$site_id = $sites->create( new Site( null, wp_generate_uuid4(), 'Heartbeat Site', 'https://example.com', 'https://example.com/agent' ) );
		$this->assertIsInt( $site_id );
		$monitor   = new class() implements MonitorInterface {
			public function get_type(): string {
				return 'http';
			}

			public function check( Site $site ): CheckResult {
				$now = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );

				return new CheckResult( (int) $site->id(), 'http', 'healthy', null, 'Checked.', $now, $now, 1 );
			}
		};
		$lock      = new class() implements CheckLockInterface {
			public function acquire( Site $site, string $check_type ): ?string {
				unset( $site, $check_type );
				return 'owner';
			}

			public function release( Site $site, string $check_type, string $token ): void {
				unset( $site, $check_type, $token );
			}
		};
		$scheduler = new Scheduler( new CheckRunner( $sites, $lock, array( $monitor ) ), null, $this->heartbeat );
		Scheduler::ensure_scheduled();

		$results = $scheduler->run( 'http' );
		$status  = $this->job( 'http' );

		$this->assertCount( 1, $results );
		$this->assertSame( $this->now, $status['last_started_at'] );
		$this->assertSame( $this->now, $status['last_completed_at'] );
		$this->assertSame( SchedulerHeartbeat::RESULT_SUCCESS, $status['result'] );
		$this->assertSame( 1, $status['processed'] );
		$this->assertSame( SchedulerHeartbeat::HEALTH_HEALTHY, $status['health'] );
		$this->assertIsInt( $status['next_run'] );
	}

	public function test_old_completed_job_is_stale_after_two_intervals(): void {
		Scheduler::ensure_scheduled();
		$this->heartbeat->record_started( 'http' );
		$this->heartbeat->record_completed( 'http', 2 );
		$this->now += 10 * MINUTE_IN_SECONDS;

		$status = $this->job( 'http' );

		$this->assertSame( SchedulerHeartbeat::HEALTH_STALE, $status['health'] );
		$this->assertSame( SchedulerHeartbeat::RESULT_SUCCESS, $status['result'] );
	}

	public function test_interrupted_running_job_becomes_stale(): void {
		Scheduler::ensure_scheduled();
		$this->heartbeat->record_started( 'http' );
		$this->now += 10 * MINUTE_IN_SECONDS;

		$status = $this->job( 'http' );

		$this->assertSame( SchedulerHeartbeat::RESULT_RUNNING, $status['result'] );
		$this->assertNull( $status['last_completed_at'] );
		$this->assertSame( SchedulerHeartbeat::HEALTH_STALE, $status['health'] );
	}

	public function test_failed_job_records_safe_result_without_error_details(): void {
		Scheduler::ensure_scheduled();
		update_option(
			SchedulerHeartbeat::OPTION,
			array(
				'http'        => array(
					'last_started_at' => $this->now,
					'result'          => 'running',
					'secret'          => 'must-not-survive',
				),
				'foreign_job' => array( 'secret' => 'discarded' ),
			)
		);

		$this->heartbeat->record_failed( 'http' );
		$stored = get_option( SchedulerHeartbeat::OPTION );
		$status = $this->job( 'http' );

		$this->assertSame( SchedulerHeartbeat::RESULT_FAILED, $status['result'] );
		$this->assertSame( $this->now, $status['last_completed_at'] );
		$this->assertSame( array( 'last_started_at', 'last_completed_at', 'result', 'processed' ), array_keys( $stored['http'] ) );
		$this->assertArrayNotHasKey( 'foreign_job', $stored );
		$this->assertStringNotContainsString( 'must-not-survive', wp_json_encode( $stored ) );
	}

	public function test_missing_schedule_is_stale_and_ensure_scheduled_repairs_it(): void {
		$missing = $this->job( 'http' );
		$this->assertNull( $missing['next_run'] );
		$this->assertSame( SchedulerHeartbeat::HEALTH_STALE, $missing['health'] );

		Scheduler::ensure_scheduled();
		$repaired = $this->job( 'http' );

		$this->assertIsInt( $repaired['next_run'] );
		$this->assertSame( SchedulerHeartbeat::HEALTH_HEALTHY, $repaired['health'] );
	}

	/**
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
	private function job( string $name ): array {
		foreach ( $this->heartbeat->snapshot() as $job ) {
			if ( $name === $job['job'] ) {
				return $job;
			}
		}

		$this->fail( 'Scheduler job was not found.' );
	}
}
