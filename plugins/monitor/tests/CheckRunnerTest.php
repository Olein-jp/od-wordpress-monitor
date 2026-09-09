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
use Olein\WordPressMonitor\Monitor\CheckResult;
use Olein\WordPressMonitor\Monitor\MonitorInterface;
use Olein\WordPressMonitor\Scheduler\CheckLockInterface;
use Olein\WordPressMonitor\Scheduler\CheckRunner;
use Olein\WordPressMonitor\Site\Site;
use Olein\WordPressMonitor\Site\SiteRepository;

final class CheckRunnerTest extends \WP_UnitTestCase {
	private SiteRepository $sites;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;

		( new DatabaseMigrator( $wpdb ) )->migrate();
		$this->sites = new SiteRepository( $wpdb );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}odm_sites" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
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
	private function monitor( string $type, array &$calls = array() ): MonitorInterface {
		return new class( $type, $calls ) implements MonitorInterface {
			/**
			 * @param list<string> $calls Recorded monitor types.
			 */
			public function __construct( private readonly string $type, private array &$calls ) {
			}

			public function get_type(): string {
				return $this->type;
			}

			public function check( Site $site ): CheckResult {
				$this->calls[] = $this->type;
				$now           = new DateTimeImmutable( '2026-09-09T00:00:00Z' );

				return new CheckResult( (int) $site->id(), $this->type, CheckResult::STATUS_HEALTHY, null, 'Passed.', $now, $now, 0 );
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
