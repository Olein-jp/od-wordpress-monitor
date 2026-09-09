<?php
/**
 * Site status repository tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use DateTimeImmutable;
use Olein\WordPressMonitor\Activation\DatabaseMigrator;
use Olein\WordPressMonitor\Status\SiteStatus;
use Olein\WordPressMonitor\Status\SiteStatusRepository;

final class SiteStatusRepositoryTest extends \WP_UnitTestCase {
	private SiteStatusRepository $repository;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;

		( new DatabaseMigrator( $wpdb ) )->migrate();
		$this->repository = new SiteStatusRepository( $wpdb );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}odm_site_status" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	public function test_upserts_one_current_row_per_site(): void {
		$checked_at = new DateTimeImmutable( '2026-09-09T10:00:00+09:00' );
		$first      = new SiteStatus(
			site_id: 12,
			overall_status: 'healthy',
			http_status: 'healthy',
			http_checked_at: $checked_at,
			last_checked_at: $checked_at,
			metadata: array( 'wordpress_version' => '6.9' )
		);

		$this->assertTrue( $this->repository->upsert( $first ) );
		$this->assertTrue(
			$this->repository->upsert(
				new SiteStatus(
					site_id: 12,
					overall_status: 'critical',
					http_status: 'critical',
					http_checked_at: $checked_at,
					last_checked_at: $checked_at,
					last_error_code: 'HTTP_UNREACHABLE',
					last_message: 'The site is unavailable.'
				)
			)
		);

		global $wpdb;
		$stored = $this->repository->find( 12 );
		$count  = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}odm_site_status WHERE site_id = 12" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->assertSame( '1', $count );
		$this->assertSame( 'critical', $stored->overall_status() );
		$this->assertSame( 'HTTP_UNREACHABLE', $stored->last_error_code() );
		$this->assertSame( '2026-09-09 01:00:00', $stored->http_checked_at()->format( 'Y-m-d H:i:s' ) );
	}

	public function test_rejects_sensitive_metadata_and_reads_malformed_json_safely(): void {
		$result = $this->repository->upsert( new SiteStatus( site_id: 10, metadata: array( 'password' => 'secret' ) ) );
		$this->assertWPError( $result );

		$this->assertTrue( $this->repository->upsert( new SiteStatus( site_id: 10, metadata: array( 'safe' => true ) ) ) );
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'odm_site_status', array( 'metadata' => '{bad json' ), array( 'site_id' => 10 ) );

		$this->assertSame( array(), $this->repository->find( 10 )->metadata() );
	}
}
