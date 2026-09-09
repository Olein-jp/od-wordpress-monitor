<?php
/**
 * Check repository tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use DateTimeImmutable;
use Olein\WordPressMonitor\Activation\DatabaseMigrator;
use Olein\WordPressMonitor\Check\CheckRepository;
use Olein\WordPressMonitor\Monitor\CheckResult;

final class CheckRepositoryTest extends \WP_UnitTestCase {
	private CheckRepository $repository;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;

		( new DatabaseMigrator( $wpdb ) )->migrate();
		$this->repository = new CheckRepository( $wpdb );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}odm_checks" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	public function test_creates_and_returns_checks_in_reverse_chronological_order(): void {
		$older = $this->result( new DateTimeImmutable( '2026-09-09T00:00:00Z' ), 'healthy' );
		$newer = $this->result( new DateTimeImmutable( '2026-09-09T01:00:00Z' ), 'critical' );

		$older_id = $this->repository->create( $older );
		$newer_id = $this->repository->create( $newer );
		$checks   = $this->repository->for_site( 4 );

		$this->assertIsInt( $older_id );
		$this->assertIsInt( $newer_id );
		$this->assertSame( array( $newer_id, $older_id ), array_map( static fn( $check ) => $check->id(), $checks ) );
		$this->assertSame( 'critical', $this->repository->find( $newer_id )->status() );
		$this->assertSame( array( 'http_status' => 503 ), $this->repository->find( $newer_id )->metadata() );
	}

	public function test_invalid_persisted_metadata_decodes_to_empty_array(): void {
		$id = $this->repository->create( $this->result( new DateTimeImmutable( '2026-09-09T00:00:00Z' ), 'healthy' ) );
		$this->assertIsInt( $id );

		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'odm_checks', array( 'metadata' => '{bad json' ), array( 'id' => $id ) );

		$this->assertSame( array(), $this->repository->find( $id )->metadata() );
	}

	private function result( DateTimeImmutable $time, string $status ): CheckResult {
		return new CheckResult( 4, 'http', $status, null, 'Checked.', $time, $time, 25, array( 'http_status' => 503 ) );
	}
}
