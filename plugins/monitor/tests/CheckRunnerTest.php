<?php
/**
 * Scheduled check runner tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use Olein\WordPressMonitor\Activation\DatabaseMigrator;
use Olein\WordPressMonitor\Check\CheckRepository;
use Olein\WordPressMonitor\Evaluation\CheckResultRecorder;
use Olein\WordPressMonitor\Evaluation\StateTransition;
use Olein\WordPressMonitor\Evaluation\StatusEvaluator;
use Olein\WordPressMonitor\Event\EventRepository;
use Olein\WordPressMonitor\Event\EventType;
use Olein\WordPressMonitor\Monitor\CheckResult;
use Olein\WordPressMonitor\Monitor\MonitorInterface;
use Olein\WordPressMonitor\Monitor\Status;
use Olein\WordPressMonitor\Scheduler\CheckLockInterface;
use Olein\WordPressMonitor\Scheduler\CheckRunner;
use Olein\WordPressMonitor\Scheduler\RetryScheduler;
use Olein\WordPressMonitor\Site\Site;
use Olein\WordPressMonitor\Site\SiteRepository;
use Olein\WordPressMonitor\Status\SiteStatusRepository;
use Olein\WordPressMonitor\Support\ErrorCode;

final class CheckRunnerTest extends \WP_UnitTestCase {
	private SiteRepository $sites;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;

		( new DatabaseMigrator( $wpdb ) )->migrate();
		$this->sites = new SiteRepository( $wpdb );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}odm_sites" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		wp_unschedule_hook( RetryScheduler::HOOK );
	}

	public function tear_down(): void {
		wp_unschedule_hook( RetryScheduler::HOOK );
		parent::tear_down();
	}

	public function test_only_enabled_sites_are_run_and_results_reach_boundary_hook(): void {
		$enabled_id = $this->create_site( 'Enabled', true );
		$this->create_site( 'Disabled', false );
		$monitor  = $this->monitor( 'http' );
		$observed = array();
		$observer = static function ( CheckResult $result ) use ( &$observed ): void {
			$observed[] = $result;
		};
		add_action( 'odm_check_result', $observer );

		$results = ( new CheckRunner( $this->sites, $this->open_lock(), array( $monitor ) ) )->run( 'http' );
		remove_action( 'odm_check_result', $observer );

		$this->assertCount( 1, $results );
		$this->assertSame( $enabled_id, $results[0]->site_id() );
		$this->assertSame( $results, $observed );
	}

	public function test_only_requested_due_type_is_run(): void {
		$this->create_site( 'Enabled', true );
		$calls        = array();
		$http_monitor = $this->monitor( 'http', $calls );
		$ssl_monitor  = $this->monitor( 'ssl', $calls );
		$runner       = new CheckRunner( $this->sites, $this->open_lock(), array( $http_monitor, $ssl_monitor ) );

		$results = $runner->run( 'ssl' );

		$this->assertCount( 1, $results );
		$this->assertSame( array( 'ssl' ), $calls );
		$this->assertSame( 'ssl', $results[0]->type() );
	}

	public function test_active_lock_skips_check(): void {
		$this->create_site( 'Enabled', true );
		$lock = new class() implements CheckLockInterface {
			public function acquire( Site $site, string $check_type ): ?string {
				unset( $site, $check_type );
				return null;
			}

			public function release( Site $site, string $check_type, string $token ): void {
				unset( $site, $check_type, $token );
			}
		};

		$results = ( new CheckRunner( $this->sites, $lock, array( $this->monitor( 'http' ) ) ) )->run( 'http' );

		$this->assertSame( array(), $results );
	}

	public function test_monitor_failure_becomes_unknown_and_releases_lock(): void {
		$this->create_site( 'Enabled', true );
		$released = false;
		$monitor  = new class() implements MonitorInterface {
			public function get_type(): string {
				return 'http';
			}

			public function check( Site $site ): CheckResult {
				unset( $site );
				throw new \RuntimeException( 'Sensitive implementation detail.' );
			}
		};
		$lock     = new class( $released ) implements CheckLockInterface {
			public function __construct( private bool &$released ) {
			}

			public function acquire( Site $site, string $check_type ): ?string {
				unset( $site, $check_type );
				return 'owner';
			}

			public function release( Site $site, string $check_type, string $token ): void {
				unset( $site, $check_type, $token );
				$this->released = true;
			}
		};
		$result   = ( new CheckRunner( $this->sites, $lock, array( $monitor ) ) )->run( 'http' )[0];

		$this->assertTrue( $released );
		$this->assertSame( CheckResult::STATUS_UNKNOWN, $result->status() );
		$this->assertSame( 'RUNNER_ERROR', $result->error_code() );
		$this->assertStringNotContainsString( 'Sensitive', $result->message() );
	}

	public function test_site_health_failure_for_one_site_does_not_stop_the_next_site(): void {
		$this->create_site( 'First', true );
		$this->create_site( 'Second', true );
		$calls   = 0;
		$monitor = new class( $calls ) implements MonitorInterface {
			public function __construct( private int &$calls ) {
			}

			public function get_type(): string {
				return 'site_health';
			}

			public function check( Site $site ): CheckResult {
				++$this->calls;

				if ( 1 === $this->calls ) {
					throw new \RuntimeException( 'First site failed.' );
				}

				$now = new DateTimeImmutable( '2026-09-10T00:00:00Z' );
				return new CheckResult( (int) $site->id(), 'site_health', Status::HEALTHY, null, 'Passed.', $now, $now, 0 );
			}
		};
		$results = ( new CheckRunner( $this->sites, $this->open_lock(), array( $monitor ) ) )->run( 'site_health' );

		$this->assertCount( 2, $results );
		$this->assertSame( Status::UNKNOWN, $results[0]->status() );
		$this->assertSame( Status::HEALTHY, $results[1]->status() );
		$this->assertSame( 2, $calls );
	}

	public function test_runner_persists_check_status_and_transition_event(): void {
		global $wpdb;

		$site_id  = $this->create_site( 'Persisted', true );
		$checks   = new CheckRepository( $wpdb );
		$statuses = new SiteStatusRepository( $wpdb );
		$events   = new EventRepository( $wpdb );
		$recorder = new CheckResultRecorder(
			$wpdb,
			$checks,
			$statuses,
			$events,
			new StatusEvaluator(),
			new StateTransition()
		);
		$wpdb->query( "DELETE FROM {$wpdb->prefix}odm_checks" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}odm_site_status" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}odm_events" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$now = new DateTimeImmutable( '2026-09-09T00:00:00Z' );
		$this->assertTrue( $recorder->record( new CheckResult( $site_id, 'http', Status::HEALTHY, null, 'Passed.', $now, $now, 0 ) ) );

		$runner = new CheckRunner(
			$this->sites,
			$this->open_lock(),
			array( $this->monitor( 'http', status: Status::CRITICAL ) ),
			$recorder
		);
		$runner->run( 'http' );

		$this->assertCount( 2, $checks->for_site( $site_id ) );
		$this->assertSame( Status::CRITICAL, $statuses->find( $site_id )->http_status() );
		$this->assertSame( EventType::SITE_DOWN, $events->for_site( $site_id )[0]->type() );
	}

	public function test_transient_failure_is_retried_and_only_success_is_published(): void {
		$site_id   = $this->create_site( 'RetrySuccess', true );
		$site      = $this->sites->find( $site_id );
		$calls     = 0;
		$published = array();
		$monitor   = $this->sequence_monitor( array( ErrorCode::TIMEOUT, null ), $calls );
		$retries   = new RetryScheduler();
		$runner    = new CheckRunner( $this->sites, $this->open_lock(), array( $monitor ), null, $retries );
		$observer  = static function ( CheckResult $result ) use ( &$published ): void {
			$published[] = $result;
		};
		$this->assertInstanceOf( Site::class, $site );
		add_action( 'odm_check_result', $observer );

		$before = time();
		$this->assertSame( array(), $runner->run( 'http' ) );
		$this->assertTrue( $retries->has_pending( $site, 'http' ) );
		$event = wp_get_scheduled_event( RetryScheduler::HOOK, array( $site_id, $site->uuid(), 'http', 2 ) );
		$this->assertIsObject( $event );
		$this->assertSame( array( $site_id, $site->uuid(), 'http', 2 ), $event->args );
		$this->assertGreaterThanOrEqual( $before + 60, $event->timestamp );
		$this->assertLessThanOrEqual( time() + 60, $event->timestamp );
		$result = $runner->retry( $site_id, $site->uuid(), 'http', 2 );

		remove_action( 'odm_check_result', $observer );
		$this->assertInstanceOf( CheckResult::class, $result );
		$this->assertSame( Status::HEALTHY, $result->status() );
		$this->assertSame( 2, $calls );
		$this->assertSame( array( $result ), $published );
		$this->assertFalse( $retries->has_pending( $site, 'http' ) );
	}

	public function test_retry_stops_at_configured_attempt_limit(): void {
		$site_id = $this->create_site( 'RetryLimit', true );
		$site    = $this->sites->find( $site_id );
		$calls   = 0;
		$monitor = $this->sequence_monitor( array( ErrorCode::CONNECTION_ERROR, ErrorCode::CONNECTION_ERROR, ErrorCode::CONNECTION_ERROR ), $calls );
		$retries = new RetryScheduler();
		$runner  = new CheckRunner( $this->sites, $this->open_lock(), array( $monitor ), null, $retries );
		$this->assertInstanceOf( Site::class, $site );

		$this->assertSame( array(), $runner->run( 'http' ) );
		$before = time();
		$this->assertNull( $runner->retry( $site_id, $site->uuid(), 'http', 2 ) );
		$event = wp_get_scheduled_event( RetryScheduler::HOOK, array( $site_id, $site->uuid(), 'http', 3 ) );
		$this->assertIsObject( $event );
		$this->assertGreaterThanOrEqual( $before + 300, $event->timestamp );
		$this->assertLessThanOrEqual( time() + 300, $event->timestamp );
		$result = $runner->retry( $site_id, $site->uuid(), 'http', 3 );

		$this->assertInstanceOf( CheckResult::class, $result );
		$this->assertSame( ErrorCode::CONNECTION_ERROR, $result->error_code() );
		$this->assertSame( RetryScheduler::MAX_ATTEMPTS, $calls );
		$this->assertFalse( $retries->has_pending( $site, 'http' ) );
	}

	public function test_authentication_failure_is_not_retried(): void {
		$site_id = $this->create_site( 'NoRetry', true );
		$site    = $this->sites->find( $site_id );
		$calls   = 0;
		$retries = new RetryScheduler();
		$runner  = new CheckRunner(
			$this->sites,
			$this->open_lock(),
			array( $this->sequence_monitor( array( ErrorCode::AUTHENTICATION_FAILED ), $calls ) ),
			null,
			$retries
		);
		$this->assertInstanceOf( Site::class, $site );

		$results = $runner->run( 'http' );

		$this->assertCount( 1, $results );
		$this->assertSame( ErrorCode::AUTHENTICATION_FAILED, $results[0]->error_code() );
		$this->assertSame( 1, $calls );
		$this->assertFalse( $retries->has_pending( $site, 'http' ) );
	}

	public function test_pending_retry_blocks_recurring_run_and_lock_conflict_reschedules_retry(): void {
		$site_id = $this->create_site( 'RetryLock', true );
		$site    = $this->sites->find( $site_id );
		$calls   = 0;
		$retries = new RetryScheduler();
		$open    = true;
		$lock    = new class( $open ) implements CheckLockInterface {
			public function __construct( private bool &$open ) {
			}

			public function acquire( Site $site, string $check_type ): ?string {
				unset( $site, $check_type );
				return $this->open ? 'owner' : null;
			}

			public function release( Site $site, string $check_type, string $token ): void {
				unset( $site, $check_type, $token );
			}
		};
		$runner  = new CheckRunner(
			$this->sites,
			$lock,
			array( $this->sequence_monitor( array( ErrorCode::TIMEOUT, null ), $calls ) ),
			null,
			$retries
		);
		$this->assertInstanceOf( Site::class, $site );

		$this->assertSame( array(), $runner->run( 'http' ) );
		$this->assertSame( array(), $runner->run( 'http' ) );
		$this->assertSame( 1, $calls );
		$open = false;
		$this->assertNull( $runner->retry( $site_id, $site->uuid(), 'http', 2 ) );
		$this->assertTrue( $retries->has_pending( $site, 'http' ) );
		$this->assertSame( 1, $calls );
	}

	public function test_duplicate_monitor_types_are_rejected(): void {
		$monitor = $this->monitor( 'http' );

		$this->expectException( InvalidArgumentException::class );
		new CheckRunner( $this->sites, $this->open_lock(), array( $monitor, $monitor ) );
	}

	public function test_unknown_monitor_type_is_rejected(): void {
		$runner = new CheckRunner( $this->sites, $this->open_lock(), array( $this->monitor( 'http' ) ) );

		$this->expectException( InvalidArgumentException::class );
		$runner->run( 'unknown' );
	}

	private function create_site( string $name, bool $enabled ): int {
		$id = $this->sites->create(
			new Site( null, wp_generate_uuid4(), $name, 'https://' . strtolower( $name ) . '.example.com', 'https://' . strtolower( $name ) . '.example.com/agent', $enabled )
		);

		$this->assertIsInt( $id );
		return $id;
	}

	/**
	 * @param list<string> $calls Recorded monitor types.
	 */
	private function monitor( string $type, array &$calls = array(), string $status = Status::HEALTHY ): MonitorInterface {
		return new class( $type, $calls, $status ) implements MonitorInterface {
			/**
			 * @param list<string> $calls Recorded monitor types.
			 */
			public function __construct( private readonly string $type, private array &$calls, private readonly string $status ) {
			}

			public function get_type(): string {
				return $this->type;
			}

			public function check( Site $site ): CheckResult {
				$this->calls[] = $this->type;
				$now           = new DateTimeImmutable( '2026-09-09T00:00:00Z' );

				return new CheckResult( (int) $site->id(), $this->type, $this->status, null, 'Passed.', $now, $now, 0 );
			}
		};
	}

	/**
	 * @param list<?string> $errors Error code per invocation; null means success.
	 */
	private function sequence_monitor( array $errors, int &$calls ): MonitorInterface {
		return new class( $errors, $calls ) implements MonitorInterface {
			/**
			 * @param list<?string> $errors Error code per invocation; null means success.
			 */
			public function __construct( private readonly array $errors, private int &$calls ) {
			}

			public function get_type(): string {
				return 'http';
			}

			public function check( Site $site ): CheckResult {
				$error = $this->errors[ $this->calls ] ?? null;
				++$this->calls;
				$now = new DateTimeImmutable( '2026-09-11T00:00:00Z' );

				return new CheckResult(
					(int) $site->id(),
					'http',
					null === $error ? Status::HEALTHY : Status::CRITICAL,
					$error,
					null === $error ? 'Passed.' : 'Failed safely.',
					$now,
					$now,
					0
				);
			}
		};
	}

	private function open_lock(): CheckLockInterface {
		return new class() implements CheckLockInterface {
			public function acquire( Site $site, string $check_type ): ?string {
				unset( $site, $check_type );
				return 'owner';
			}

			public function release( Site $site, string $check_type, string $token ): void {
				unset( $site, $check_type, $token );
			}
		};
	}
}
