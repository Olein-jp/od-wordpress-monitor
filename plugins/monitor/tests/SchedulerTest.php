<?php
/**
 * WP-Cron scheduler tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use Olein\WordPressMonitor\Activation\Activator;
use Olein\WordPressMonitor\Activation\DatabaseMigrator;
use Olein\WordPressMonitor\Check\CheckRepository;
use Olein\WordPressMonitor\Event\EventRepository;
use Olein\WordPressMonitor\Event\MonitoringEvent;
use Olein\WordPressMonitor\Notification\NotificationManager;
use Olein\WordPressMonitor\Notification\NotificationMessage;
use Olein\WordPressMonitor\Notification\NotificationMessageFactoryInterface;
use Olein\WordPressMonitor\Notification\NotificationRule;
use Olein\WordPressMonitor\Scheduler\BatchScheduler;
use Olein\WordPressMonitor\Scheduler\CheckLockInterface;
use Olein\WordPressMonitor\Scheduler\CheckRetention;
use Olein\WordPressMonitor\Scheduler\CheckRunner;
use Olein\WordPressMonitor\Scheduler\RetryScheduler;
use Olein\WordPressMonitor\Notification\NotificationDeliveryRetry;
use Olein\WordPressMonitor\Scheduler\Scheduler;
use Olein\WordPressMonitor\Site\Site;
use Olein\WordPressMonitor\Site\SiteRepository;

final class SchedulerTest extends \WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		Scheduler::clear_scheduled();
		add_filter( 'cron_schedules', array( Scheduler::class, 'add_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- Uses the production interval callback.
	}

	public function tear_down(): void {
		Scheduler::clear_scheduled();
		remove_filter( 'cron_schedules', array( Scheduler::class, 'add_schedules' ) );
		parent::tear_down();
	}

	public function test_required_intervals_are_registered(): void {
		$schedules = wp_get_schedules();

		$this->assertSame( 5 * MINUTE_IN_SECONDS, $schedules['odm_five_minutes']['interval'] );
		$this->assertSame( 15 * MINUTE_IN_SECONDS, $schedules['odm_fifteen_minutes']['interval'] );
		$this->assertSame( 'hourly', Scheduler::CHECK_SCHEDULES['site_health'] );
		$this->assertSame( 'hourly', Scheduler::DIGEST_RECURRENCE );
	}

	public function test_each_check_type_is_scheduled_exactly_once(): void {
		Scheduler::ensure_scheduled();
		$first_timestamps = array();
		$cleanup_event    = wp_get_scheduled_event( Scheduler::CLEANUP_HOOK );

		$this->assertIsObject( $cleanup_event );
		$this->assertSame( Scheduler::CLEANUP_RECURRENCE, $cleanup_event->schedule );
		$cleanup_timestamp = $cleanup_event->timestamp;

		foreach ( Scheduler::CHECK_SCHEDULES as $check_type => $recurrence ) {
			$event = wp_get_scheduled_event( Scheduler::HOOK, array( $check_type ) );
			$this->assertIsObject( $event );
			$this->assertSame( $recurrence, $event->schedule );
			$first_timestamps[ $check_type ] = $event->timestamp;
		}

		Scheduler::ensure_scheduled();

		foreach ( $first_timestamps as $check_type => $timestamp ) {
			$this->assertSame( $timestamp, wp_next_scheduled( Scheduler::HOOK, array( $check_type ) ) );
		}

		$this->assertSame( $cleanup_timestamp, wp_next_scheduled( Scheduler::CLEANUP_HOOK ) );
		$this->assertIsInt( wp_next_scheduled( Scheduler::DIGEST_HOOK ) );
	}

	public function test_activation_schedules_and_deactivation_clears_plugin_events(): void {
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, RetryScheduler::HOOK, array( 1, 'site-uuid', 'http', 2 ) );
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, NotificationDeliveryRetry::HOOK, array( 1, 'slack' ) );
		$generation = wp_generate_uuid4();
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, BatchScheduler::HOOK, array( 'http', $generation ) );
		$this->assertTrue( Activator::activate() );

		foreach ( array_keys( Scheduler::CHECK_SCHEDULES ) as $check_type ) {
			$this->assertIsInt( wp_next_scheduled( Scheduler::HOOK, array( $check_type ) ) );
		}
		$this->assertIsInt( wp_next_scheduled( Scheduler::CLEANUP_HOOK ) );
		$this->assertIsInt( wp_next_scheduled( RetryScheduler::HOOK, array( 1, 'site-uuid', 'http', 2 ) ) );
		$this->assertIsInt( wp_next_scheduled( NotificationDeliveryRetry::HOOK, array( 1, 'slack' ) ) );
		$this->assertIsInt( wp_next_scheduled( BatchScheduler::HOOK, array( 'http', $generation ) ) );

		Activator::deactivate();

		foreach ( array_keys( Scheduler::CHECK_SCHEDULES ) as $check_type ) {
			$this->assertFalse( wp_next_scheduled( Scheduler::HOOK, array( $check_type ) ) );
		}
		$this->assertFalse( wp_next_scheduled( Scheduler::CLEANUP_HOOK ) );
		$this->assertFalse( wp_next_scheduled( RetryScheduler::HOOK, array( 1, 'site-uuid', 'http', 2 ) ) );
		$this->assertFalse( wp_next_scheduled( NotificationDeliveryRetry::HOOK, array( 1, 'slack' ) ) );
		$this->assertFalse( wp_next_scheduled( BatchScheduler::HOOK, array( 'http', $generation ) ) );
	}

	public function test_registered_cron_callback_and_direct_call_share_runner(): void {
		global $wpdb;

		( new DatabaseMigrator( $wpdb ) )->migrate();
		$repository         = new SiteRepository( $wpdb );
		$lock               = new class() implements CheckLockInterface {
			public function acquire( Site $site, string $check_type ): ?string {
				unset( $site, $check_type );
				return 'owner';
			}

			public function release( Site $site, string $check_type, string $token ): void {
				unset( $site, $check_type, $token );
			}
		};
		$notification_retry = new NotificationDeliveryRetry(
			new EventRepository( $wpdb ),
			new NotificationManager(
				new NotificationRule(),
				new class() implements NotificationMessageFactoryInterface {
					public function create( MonitoringEvent $event, string $notification_type ): ?NotificationMessage {
						unset( $event, $notification_type );
						return null;
					}
				},
				array()
			)
		);
		$scheduler          = new Scheduler( new CheckRunner( $repository, $lock, array() ), null, null, null, $notification_retry );
		$scheduler->register_hooks();

		$this->assertSame( 10, has_action( Scheduler::HOOK, array( $scheduler, 'run' ) ) );
		$this->assertSame( 10, has_action( RetryScheduler::HOOK, array( $scheduler, 'retry' ) ) );
		$this->assertSame( 10, has_action( BatchScheduler::HOOK, array( $scheduler, 'continue_batch' ) ) );
		$this->assertSame( 10, has_action( NotificationDeliveryRetry::HOOK, array( $notification_retry, 'run' ) ) );
		$this->assertFalse( has_action( Scheduler::CLEANUP_HOOK, array( $scheduler, 'cleanup' ) ) );
	}

	public function test_unavailable_cleanup_exposes_only_safe_error_code(): void {
		global $wpdb;

		$repository = new SiteRepository( $wpdb );
		$lock       = new class() implements CheckLockInterface {
			public function acquire( Site $site, string $check_type ): ?string {
				unset( $site, $check_type );
				return 'owner';
			}

			public function release( Site $site, string $check_type, string $token ): void {
				unset( $site, $check_type, $token );
			}
		};
		$error_code = null;
		$observer   = static function ( string $code ) use ( &$error_code ): void {
			$error_code = $code;
		};
		$scheduler  = new Scheduler( new CheckRunner( $repository, $lock, array() ) );

		add_action( 'odm_check_cleanup_failed', $observer );
		$result = $scheduler->cleanup();
		remove_action( 'odm_check_cleanup_failed', $observer );

		$this->assertWPError( $result );
		$this->assertSame( 'CLEANUP_UNAVAILABLE', $error_code );
	}

	public function test_registered_cleanup_returns_count_and_exposes_safe_result(): void {
		global $wpdb;

		( new DatabaseMigrator( $wpdb ) )->migrate();
		$repository = new SiteRepository( $wpdb );
		$lock       = new class() implements CheckLockInterface {
			public function acquire( Site $site, string $check_type ): ?string {
				unset( $site, $check_type );
				return 'owner';
			}

			public function release( Site $site, string $check_type, string $token ): void {
				unset( $site, $check_type, $token );
			}
		};
		$count      = null;
		$observer   = static function ( int $deleted ) use ( &$count ): void {
			$count = $deleted;
		};
		$scheduler  = new Scheduler(
			new CheckRunner( $repository, $lock, array() ),
			new CheckRetention( new CheckRepository( $wpdb ) )
		);

		add_action( 'odm_check_cleanup_completed', $observer );
		$scheduler->register_hooks();
		$result = $scheduler->cleanup();
		remove_action( 'odm_check_cleanup_completed', $observer );

		$this->assertSame( 10, has_action( Scheduler::CLEANUP_HOOK, array( $scheduler, 'cleanup' ) ) );
		$this->assertIsInt( $result );
		$this->assertSame( $result, $count );
	}
}
