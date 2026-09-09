<?php
/**
 * Scheduled check lock tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use InvalidArgumentException;
use Olein\WordPressMonitor\Scheduler\CheckLock;
use Olein\WordPressMonitor\Site\Site;

final class CheckLockTest extends \WP_UnitTestCase {
	private Site $site;

	public function set_up(): void {
		parent::set_up();
		$this->site = new Site( 6, '00000000-0000-4000-8000-000000000006', 'Example', 'https://example.com', 'https://example.com/agent' );
		delete_option( 'odm_lock_' . $this->site->uuid() . '_http' );
	}

	public function tear_down(): void {
		delete_option( 'odm_lock_' . $this->site->uuid() . '_http' );
		parent::tear_down();
	}

	public function test_lock_blocks_parallel_execution_and_can_be_released(): void {
		global $wpdb;

		$lock  = new CheckLock( $wpdb );
		$token = $lock->acquire( $this->site, 'http' );

		$this->assertIsString( $token );
		$this->assertNull( $lock->acquire( $this->site, 'http' ) );

		$lock->release( $this->site, 'http', $token );
		$this->assertIsString( $lock->acquire( $this->site, 'http' ) );
	}

	public function test_expired_lock_can_be_replaced_without_old_owner_releasing_replacement(): void {
		global $wpdb;

		$now       = 100;
		$lock      = new CheckLock(
			$wpdb,
			10,
			static function () use ( &$now ): int {
				return $now;
			}
		);
		$old_token = $lock->acquire( $this->site, 'http' );
		$now       = 111;
		$new_token = $lock->acquire( $this->site, 'http' );

		$this->assertIsString( $old_token );
		$this->assertIsString( $new_token );
		$this->assertNotSame( $old_token, $new_token );

		$lock->release( $this->site, 'http', $old_token );
		$this->assertNull( $lock->acquire( $this->site, 'http' ) );

		$lock->release( $this->site, 'http', $new_token );
		$this->assertIsString( $lock->acquire( $this->site, 'http' ) );
	}

	public function test_non_positive_ttl_is_rejected(): void {
		global $wpdb;

		$this->expectException( InvalidArgumentException::class );
		new CheckLock( $wpdb, 0 );
	}
}
