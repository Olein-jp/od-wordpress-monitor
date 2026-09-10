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

	public function test_valid_updates_passes(): void {
		$this->assertTrue( $this->validator->validate_updates( $this->valid_updates() ) );
	}

	public function test_updates_rejects_inconsistent_summary(): void {
		$updates                       = $this->valid_updates();
		$updates['summary']['plugins'] = 0;
		$result                        = $this->validator->validate_updates( $updates );

		$this->assertWPError( $result );
		$this->assertSame( 'INVALID_RESPONSE', $result->get_error_code() );
	}

	public function test_updates_rejects_inconsistent_update_flag(): void {
		$updates                                   = $this->valid_updates();
		$updates['plugins'][0]['update_available'] = false;
		$result                                    = $this->validator->validate_updates( $updates );

		$this->assertWPError( $result );
		$this->assertSame( 'INVALID_RESPONSE', $result->get_error_code() );
	}

	public function test_updates_rejects_fields_outside_contract(): void {
		$updates                           = $this->valid_updates();
		$updates['plugins'][0]['settings'] = array( 'secret' => true );
		$result                            = $this->validator->validate_updates( $updates );

		$this->assertWPError( $result );
		$this->assertSame( 'INVALID_RESPONSE', $result->get_error_code() );
	}

	public function test_valid_site_health_passes(): void {
		$this->assertTrue( $this->validator->validate_site_health( $this->valid_site_health() ) );
	}

	public function test_site_health_rejects_inconsistent_summary(): void {
		$health                        = $this->valid_site_health();
		$health['summary']['critical'] = 1;
		$result                        = $this->validator->validate_site_health( $health );

		$this->assertWPError( $result );
		$this->assertSame( 'INVALID_RESPONSE', $result->get_error_code() );
	}

	public function test_site_health_rejects_duplicate_ids(): void {
		$health            = $this->valid_site_health();
		$health['tests'][] = $health['tests'][0];
		++$health['summary']['good'];
		$result = $this->validator->validate_site_health( $health );

		$this->assertWPError( $result );
		$this->assertSame( 'INVALID_RESPONSE', $result->get_error_code() );
	}

	public function test_site_health_rejects_html_label(): void {
		$health                      = $this->valid_site_health();
		$health['tests'][0]['label'] = '<strong>Healthy</strong>';
		$result                      = $this->validator->validate_site_health( $health );

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

	/**
	 * Return a valid updates fixture.
	 *
	 * @return array<string,mixed>
	 */
	private function valid_updates(): array {
		return array(
			'schema_version' => '1.0',
			'wordpress'      => array(
				'current_version'  => '7.1',
				'latest_version'   => '7.1',
				'update_available' => false,
			),
			'plugins'        => array(
				array(
					'file'             => 'example/example.php',
					'name'             => 'Example Plugin',
					'current_version'  => '1.0.0',
					'latest_version'   => '1.1.0',
					'update_available' => true,
					'active'           => true,
				),
			),
			'themes'         => array(),
			'summary'        => array(
				'wordpress' => 0,
				'plugins'   => 1,
				'themes'    => 0,
				'total'     => 1,
			),
			'timestamp'      => '2026-09-09T09:00:00Z',
		);
	}

	/**
	 * Return a valid Site Health fixture.
	 *
	 * @return array<string,mixed>
	 */
	private function valid_site_health(): array {
		return array(
			'schema_version' => '1.0',
			'summary'        => array(
				'critical'    => 0,
				'recommended' => 0,
				'good'        => 1,
			),
			'tests'          => array(
				array(
					'id'     => 'php_extensions',
					'status' => 'good',
					'label'  => 'Required PHP modules are available',
				),
			),
			'timestamp'      => '2026-09-10T03:00:00Z',
		);
	}
}
