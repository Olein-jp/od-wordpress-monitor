<?php
/**
 * Site repository tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use Olein\WordPressMonitor\Activation\DatabaseMigrator;
use Olein\WordPressMonitor\Site\Site;
use Olein\WordPressMonitor\Site\SiteRepository;

final class SiteRepositoryTest extends \WP_UnitTestCase {
	private SiteRepository $repository;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;

		( new DatabaseMigrator( $wpdb ) )->migrate();
		$this->repository = new SiteRepository( $wpdb );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}odm_sites" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	public function test_create_find_list_and_update(): void {
		$uuid = wp_generate_uuid4();
		$id   = $this->repository->create(
			new Site( null, $uuid, 'Original', 'https://example.com', 'https://example.com/wp-json/od-monitor-agent/v1' )
		);

		$this->assertIsInt( $id );
		$this->assertSame( 'Original', $this->repository->find( $id )->name() );
		$this->assertCount( 1, $this->repository->all() );

		$updated = new Site( $id, $uuid, 'Updated', 'https://example.org', 'https://example.org/wp-json/od-monitor-agent/v1', false );
		$this->assertTrue( $this->repository->update( $updated ) );
		$this->assertSame( 'Updated', $this->repository->find( $id )->name() );
		$this->assertFalse( $this->repository->find( $id )->enabled() );
	}

	public function test_enabled_returns_only_enabled_sites(): void {
		$this->repository->create( new Site( null, wp_generate_uuid4(), 'Enabled', 'https://enabled.example.com', 'https://enabled.example.com/agent', true ) );
		$this->repository->create( new Site( null, wp_generate_uuid4(), 'Disabled', 'https://disabled.example.com', 'https://disabled.example.com/agent', false ) );

		$sites = $this->repository->enabled();

		$this->assertCount( 1, $sites );
		$this->assertSame( 'Enabled', $sites[0]->name() );
		$this->assertTrue( $sites[0]->enabled() );
	}
}
