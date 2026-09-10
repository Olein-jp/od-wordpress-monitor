<?php
/**
 * Batched scheduled check integration tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use Olein\WordPressMonitor\Activation\DatabaseMigrator;
use Olein\WordPressMonitor\Monitor\CheckResult;
use Olein\WordPressMonitor\Monitor\MonitorInterface;
use Olein\WordPressMonitor\Monitor\Status;
use Olein\WordPressMonitor\Scheduler\BatchScheduler;
use Olein\WordPressMonitor\Scheduler\CheckLockInterface;
use Olein\WordPressMonitor\Scheduler\CheckRunner;
use Olein\WordPressMonitor\Site\Site;
use Olein\WordPressMonitor\Site\SiteRepository;

final class CheckRunnerBatchTest extends \WP_UnitTestCase {
	private SiteRepository $sites;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;

		( new DatabaseMigrator( $wpdb ) )->migrate();
		$this->sites = new SiteRepository( $wpdb );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}odm_sites" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->clear_batch_state();
	}

	public function tear_down(): void {
		$this->clear_batch_state();
		parent::tear_down();
	}

	public function test_under_limit_completes_without_continuation(): void {
		global $wpdb;
		$ids     = $this->create_sites( 3 );
		$calls   = array();
		$batches = new BatchScheduler( $wpdb );
		$runner  = new CheckRunner( $this->sites, $this->open_lock(), array( $this->monitor( $calls ) ), null, null, $batches, 5 );

		$results = $runner->run( 'http' );

		$this->assertCount( 3, $results );
		$this->assertSame( $ids, $calls );
		$this->assertNull( $batches->current( 'http' ) );
		$this->assertFalse( $batches->has_pending_continuation( 'http' ) );
	}

	public function test_multiple_batches_resume_cursor_without_gaps_or_duplicates(): void {
		global $wpdb;
		$ids     = $this->create_sites( 5 );
		$calls   = array();
		$batches = new BatchScheduler( $wpdb );
		$runner  = new CheckRunner( $this->sites, $this->open_lock(), array( $this->monitor( $calls ) ), null, null, $batches, 2 );
		$first   = $runner->run( 'http' );
		$state   = $batches->current( 'http' );
		$this->assertNotNull( $state );
		$generation = $state['generation'];

		$this->assertCount( 2, $first );
		$this->assertSame( $ids[1], $state['cursor'] );
		$this->assertIsInt( wp_next_scheduled( BatchScheduler::HOOK, array( 'http', $generation ) ) );
		$this->assertSame( array(), $runner->continue_batch( 'http', wp_generate_uuid4() ) );
		$this->assertSame( array( $ids[0], $ids[1] ), $calls );

		$restarted_batches = new BatchScheduler( $wpdb );
		$restarted_runner  = new CheckRunner( $this->sites, $this->open_lock(), array( $this->monitor( $calls ) ), null, null, $restarted_batches, 2 );
		$second            = $restarted_runner->run( 'http' );

		$this->assertCount( 2, $second );
		$this->assertSame( $ids[3], $restarted_batches->current( 'http' )['cursor'] );
		$third = $restarted_runner->continue_batch( 'http', $generation );

		$this->assertCount( 1, $third );
		$this->assertSame( $ids, $calls );
		$this->assertNull( $restarted_batches->current( 'http' ) );
		$this->assertFalse( wp_next_scheduled( BatchScheduler::HOOK, array( 'http', $generation ) ) );
	}

	public function test_site_lock_conflict_does_not_duplicate_current_batch_and_recovers_next_cycle(): void {
		global $wpdb;
		$ids     = $this->create_sites( 2 );
		$calls   = array();
		$blocked = $ids[0];
		$lock    = new class( $blocked ) implements CheckLockInterface {
			public function __construct( private ?int &$blocked ) {
			}

			public function acquire( Site $site, string $check_type ): ?string {
				unset( $check_type );
				return $site->id() === $this->blocked ? null : 'owner';
			}

			public function release( Site $site, string $check_type, string $token ): void {
				unset( $site, $check_type, $token );
			}
		};
		$runner  = new CheckRunner( $this->sites, $lock, array( $this->monitor( $calls ) ), null, null, new BatchScheduler( $wpdb ), 2 );

		$this->assertCount( 1, $runner->run( 'http' ) );
		$this->assertSame( array( $ids[1] ), $calls );

		$blocked = null;
		$this->assertCount( 2, $runner->run( 'http' ) );
		$this->assertSame( array( $ids[1], $ids[0], $ids[1] ), $calls );
	}

	public function test_added_site_is_continued_and_deleted_site_is_not_executed(): void {
		global $wpdb;
		$ids        = $this->create_sites( 2 );
		$calls      = array();
		$added_id   = null;
		$repository = $this->sites;
		$monitor    = new class( $repository, $ids[0], $ids[1], $calls, $added_id ) implements MonitorInterface {
			/**
			 * @param list<int> $calls Recorded site IDs.
			 */
			public function __construct(
				private readonly SiteRepository $sites,
				private readonly int $first_id,
				private readonly int $delete_id,
				private array &$calls,
				private ?int &$added_id
			) {
			}

			public function get_type(): string {
				return 'http';
			}

			public function check( Site $site ): CheckResult {
				$this->calls[] = (int) $site->id();

				if ( $site->id() === $this->first_id ) {
					$this->sites->delete( $this->delete_id );
					$created        = $this->sites->create( new Site( null, wp_generate_uuid4(), 'Added', 'https://added.example.com', 'https://added.example.com/agent', true ) );
					$this->added_id = is_int( $created ) ? $created : null;
				}

				$now = new DateTimeImmutable( '2026-09-11T00:00:00Z' );
				return new CheckResult( (int) $site->id(), 'http', Status::HEALTHY, null, 'Passed.', $now, $now, 0 );
			}
		};
		$batches    = new BatchScheduler( $wpdb );
		$runner     = new CheckRunner( $this->sites, $this->open_lock(), array( $monitor ), null, null, $batches, 2 );

		$this->assertCount( 1, $runner->run( 'http' ) );
		$state = $batches->current( 'http' );
		$this->assertNotNull( $state );
		$this->assertIsInt( $added_id );
		$this->assertCount( 1, $runner->continue_batch( 'http', $state['generation'] ) );

		$this->assertSame( array( $ids[0], $added_id ), $calls );
		$this->assertNull( $this->sites->find( $ids[1] ) );
		$this->assertNull( $batches->current( 'http' ) );
	}

	public function test_batch_limit_outside_safe_range_is_rejected(): void {
		global $wpdb;

		$this->expectException( InvalidArgumentException::class );
		new CheckRunner( $this->sites, $this->open_lock(), array( $this->monitor() ), null, null, new BatchScheduler( $wpdb ), CheckRunner::MAX_BATCH_LIMIT + 1 );
	}

	/**
	 * @return list<int>
	 */
	private function create_sites( int $count ): array {
		$ids = array();

		for ( $index = 1; $index <= $count; ++$index ) {
			$id = $this->sites->create(
				new Site( null, wp_generate_uuid4(), 'Site ' . $index, 'https://site-' . $index . '.example.com', 'https://site-' . $index . '.example.com/agent', true )
			);
			$this->assertIsInt( $id );
			$ids[] = $id;
		}

		return $ids;
	}

	/**
	 * @param list<int> $calls Recorded site IDs.
	 */
	private function monitor( array &$calls = array() ): MonitorInterface {
		return new class( $calls ) implements MonitorInterface {
			/**
			 * @param list<int> $calls Recorded site IDs.
			 */
			public function __construct( private array &$calls ) {
			}

			public function get_type(): string {
				return 'http';
			}

			public function check( Site $site ): CheckResult {
				$this->calls[] = (int) $site->id();
				$now           = new DateTimeImmutable( '2026-09-11T00:00:00Z' );

				return new CheckResult( (int) $site->id(), 'http', Status::HEALTHY, null, 'Passed.', $now, $now, 0 );
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

	private function clear_batch_state(): void {
		wp_unschedule_hook( BatchScheduler::HOOK );
		delete_option( 'odm_batch_state_http' );
		delete_option( 'odm_lock_batch_http' );
	}
}
