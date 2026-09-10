<?php
/**
 * Batch cursor and continuation scheduling tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use Olein\WordPressMonitor\Scheduler\BatchScheduler;

final class BatchSchedulerTest extends \WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		$this->clear_state();
	}

	public function tear_down(): void {
		$this->clear_state();
		parent::tear_down();
	}

	public function test_lock_prevents_parallel_batch_and_expired_owner_cannot_release_replacement(): void {
		global $wpdb;
		$now    = 100;
		$clock  = static function () use ( &$now ): int {
			return $now;
		};
		$batch  = new BatchScheduler( $wpdb, 10, $clock );
		$first  = $batch->acquire( 'http' );
		$second = new BatchScheduler( $wpdb, 10, $clock );

		$this->assertIsString( $first );
		$this->assertNull( $second->acquire( 'http' ) );

		$now         = 111;
		$replacement = $second->acquire( 'http' );
		$this->assertIsString( $replacement );
		$batch->release( 'http', $first );
		$this->assertNull( $batch->acquire( 'http' ) );

		$second->release( 'http', $replacement );
		$this->assertIsString( $batch->acquire( 'http' ) );
	}

	public function test_cursor_and_generation_survive_restart_until_completion(): void {
		global $wpdb;
		$now        = 100;
		$batch      = new BatchScheduler( $wpdb, 10, static fn(): int => $now );
		$state      = $batch->begin_or_resume( 'http' );
		$generation = $state['generation'];

		$this->assertSame( 0, $state['cursor'] );
		$this->assertTrue( $batch->advance( 'http', $generation, 25 ) );

		$restarted = new BatchScheduler( $wpdb, 10, static fn(): int => $now );
		$this->assertSame(
			array(
				'generation' => $generation,
				'cursor'     => 25,
			),
			$restarted->begin_or_resume( 'http' )
		);
		$this->assertTrue( $restarted->schedule_continuation( 'http', $generation ) );
		$this->assertSame( 105, wp_next_scheduled( BatchScheduler::HOOK, array( 'http', $generation ) ) );

		$restarted->complete( 'http', $generation );
		$this->assertNull( $restarted->current( 'http' ) );
		$this->assertFalse( wp_next_scheduled( BatchScheduler::HOOK, array( 'http', $generation ) ) );
	}

	private function clear_state(): void {
		wp_unschedule_hook( BatchScheduler::HOOK );
		delete_option( 'odm_batch_state_http' );
		delete_option( 'odm_lock_batch_http' );
	}
}
