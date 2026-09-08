<?php
/**
 * Protocol fixture integration tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\IntegrationTests;

use Olein\WordPressMonitor\Protocol\ResponseValidator;

final class ProtocolFixtureTest extends \WP_UnitTestCase {
	public function test_documented_fixtures_match_runtime_validator(): void {
		$root      = dirname( __DIR__, 2 );
		$ping      = json_decode( file_get_contents( $root . '/packages/protocol/fixtures/ping-success.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$status    = json_decode( file_get_contents( $root . '/packages/protocol/fixtures/status-success.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$validator = new ResponseValidator();

		$this->assertTrue( $validator->validate_ping( $ping ) );
		$this->assertTrue( $validator->validate_status( $status ) );
	}
}
