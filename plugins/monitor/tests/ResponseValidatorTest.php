<?php
/**
 * Protocol response validation tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use Olein\WordPressMonitor\Protocol\ResponseValidator;

final class ResponseValidatorTest extends \WP_UnitTestCase {
	private ResponseValidator $validator;

	public function set_up(): void {
		parent::set_up();
		$this->validator = new ResponseValidator();
	}

	public function test_valid_ping_passes(): void {
		$this->assertTrue( $this->validator->validate_ping( $this->valid_ping() ) );
	}

	public function test_missing_property_fails(): void {
		$ping = $this->valid_ping();
		unset( $ping['agent']['version'] );
		$result = $this->validator->validate_ping( $ping );

		$this->assertWPError( $result );
		$this->assertSame( 'INVALID_RESPONSE', $result->get_error_code() );
	}

	public function test_unsupported_schema_fails(): void {
		$ping                   = $this->valid_ping();
		$ping['schema_version'] = '2.0';
		$result                 = $this->validator->validate_ping( $ping );

		$this->assertWPError( $result );
		$this->assertSame( 'UNSUPPORTED_SCHEMA', $result->get_error_code() );
	}

	public function test_invalid_type_fails(): void {
		$ping            = $this->valid_ping();
		$ping['success'] = 1;
		$result          = $this->validator->validate_ping( $ping );

		$this->assertWPError( $result );
		$this->assertSame( 'INVALID_RESPONSE', $result->get_error_code() );
	}

	/**
	 * Return a valid ping fixture.
	 *
	 * @return array<string,mixed>
	 */
	private function valid_ping(): array {
		return array(
			'schema_version' => '1.0',
			'success'        => true,
			'agent'          => array(
				'slug'    => 'od-monitor-agent',
				'version' => '1.0.0',
			),
			'timestamp'      => '2026-09-08T09:00:00Z',
		);
	}
}
