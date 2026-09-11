<?php
/**
 * Check metadata allowlist tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use DateTimeImmutable;
use Olein\WordPressMonitor\Monitor\CheckMetadata;
use Olein\WordPressMonitor\Monitor\CheckResult;

final class CheckMetadataTest extends \WP_UnitTestCase {
	private CheckMetadata $metadata;

	public function set_up(): void {
		parent::set_up();
		$this->metadata = new CheckMetadata();
	}

	public function test_http_keeps_only_status_and_public_final_url(): void {
		$metadata = $this->metadata->for_result(
			$this->result(
				'http',
				array(
					'http_status' => 200,
					'final_url'   => 'https://user@example.com:8443/path?signature=private#result',
					'response'    => 'discarded',
				)
			)
		);

		$this->assertSame(
			array(
				'http_status' => 200,
				'final_url'   => 'https://example.com:8443/path',
			),
			$metadata
		);
	}

	public function test_updates_keeps_only_non_negative_counts(): void {
		$metadata = $this->metadata->for_result(
			$this->result(
				'updates',
				array(
					'total_updates'      => 6,
					'wordpress_updates'  => 1,
					'plugin_updates'     => 3,
					'theme_updates'      => 2,
					'plugins'            => array( 'private-plugin' ),
					'software_inventory' => array(
						'plugins'      => array(),
						'collected_at' => '2026-09-10T00:00:00Z',
					),
					'invalid_count'      => -1,
				)
			)
		);

		$this->assertSame(
			array(
				'total_updates'     => 6,
				'wordpress_updates' => 1,
				'plugin_updates'    => 3,
				'theme_updates'     => 2,
			),
			$metadata
		);
	}

	public function test_status_metadata_normalizes_and_sorts_software_inventory(): void {
		$metadata = $this->metadata->for_status(
			$this->result(
				'updates',
				array(
					'total_updates'      => 0,
					'software_inventory' => array(
						'theme'        => array(
							'id'               => 'theme',
							'name'             => '<b>Snow Monkey</b>',
							'current_version'  => '31.0.2<script>discarded()</script>',
							'latest_version'   => '31.1.0',
							'update_available' => true,
						),
						'plugins'      => array(
							array(
								'id'              => 'z/z.php',
								'name'            => 'Zulu',
								'current_version' => '',
							),
							array(
								'id'               => 'a/a.php',
								'name'             => '<em>Alpha</em>',
								'current_version'  => '1.0.0',
								'latest_version'   => '1.0.0',
								'update_available' => false,
							),
							array(
								'id'               => 'a/a.php',
								'name'             => 'Duplicate',
								'current_version'  => '9.0.0',
								'latest_version'   => '9.0.0',
								'update_available' => false,
							),
						),
						'collected_at' => '2026-09-10T00:00:00Z',
					),
				)
			)
		);

		$this->assertArrayNotHasKey( 'wordpress_version', $metadata['software_inventory'] );
		$this->assertSame( 'Snow Monkey', $metadata['software_inventory']['theme']['name'] );
		$this->assertSame( '31.0.2', $metadata['software_inventory']['theme']['current_version'] );
		$this->assertSame( '31.1.0', $metadata['software_inventory']['theme']['latest_version'] );
		$this->assertTrue( $metadata['software_inventory']['theme']['update_available'] );
		$this->assertSame( array( 'Alpha', 'Zulu' ), array_column( $metadata['software_inventory']['plugins'], 'name' ) );
		$this->assertSame( '', $metadata['software_inventory']['plugins'][1]['current_version'] );
		$this->assertArrayNotHasKey( 'update_available', $metadata['software_inventory']['plugins'][1] );
		$this->assertFalse( $metadata['software_inventory']['truncated'] );
	}

	public function test_status_metadata_rejects_invalid_inventory_and_keeps_safe_agent_versions(): void {
		$invalid = $this->metadata->for_status(
			$this->result(
				'updates',
				array(
					'software_inventory' => array(
						'plugins'      => array(),
						'collected_at' => 'not-a-date',
					),
				)
			)
		);
		$agent   = $this->metadata->for_status(
			$this->result(
				'agent_status',
				array(
					'wordpress'        => '7.1',
					'php'              => '8.3.33',
					'agent_version'    => '1.0.2',
					'environment_type' => 'production',
					'is_multisite'     => false,
					'site_name'        => 'Discarded',
				)
			)
		);

		$this->assertArrayNotHasKey( 'software_inventory', $invalid );
		$this->assertSame(
			array(
				'wordpress'        => '7.1',
				'php'              => '8.3.33',
				'agent_version'    => '1.0.2',
				'environment_type' => 'production',
				'is_multisite'     => false,
			),
			$agent
		);
	}

	public function test_status_metadata_sorts_before_limiting_active_plugins(): void {
		$plugins = array();

		for ( $index = 101; $index >= 1; --$index ) {
			$name      = sprintf( 'Plugin %03d', $index );
			$plugins[] = array(
				'id'               => sprintf( 'plugin-%03d/plugin.php', $index ),
				'name'             => $name,
				'current_version'  => '1.0.0',
				'latest_version'   => '1.0.0',
				'update_available' => false,
			);
		}

		$metadata = $this->metadata->for_status(
			$this->result(
				'updates',
				array(
					'software_inventory' => array(
						'plugins'      => $plugins,
						'collected_at' => '2026-09-10T00:00:00Z',
					),
				)
			)
		);

		$this->assertCount( 100, $metadata['software_inventory']['plugins'] );
		$this->assertSame( 'Plugin 001', $metadata['software_inventory']['plugins'][0]['name'] );
		$this->assertSame( 'Plugin 100', $metadata['software_inventory']['plugins'][99]['name'] );
		$this->assertTrue( $metadata['software_inventory']['truncated'] );
	}

	public function test_ssl_normalizes_existing_monitor_fields(): void {
		$metadata = $this->metadata->for_result(
			$this->result(
				'ssl',
				array(
					'valid_to'  => '2026-12-31T23:59:59+00:00',
					'days_left' => 112,
					'issuer'    => 'discarded',
				)
			)
		);

		$this->assertSame(
			array(
				'expires_at'     => '2026-12-31T23:59:59+00:00',
				'days_remaining' => 112,
			),
			$metadata
		);
	}

	public function test_site_health_keeps_only_counts_and_representative_test(): void {
		$metadata = $this->metadata->for_result(
			$this->result(
				'site_health',
				array(
					'critical'                   => 1,
					'recommended'                => 2,
					'good'                       => 3,
					'representative_test_id'     => 'direct_requests',
					'representative_test_status' => 'critical',
					'label'                      => 'Discarded diagnostic details',
					'raw_response'               => array( 'discarded' ),
				)
			)
		);

		$this->assertSame(
			array(
				'critical'                   => 1,
				'recommended'                => 2,
				'good'                       => 3,
				'representative_test_id'     => 'direct_requests',
				'representative_test_status' => 'critical',
			),
			$metadata
		);
	}

	public function test_unknown_monitor_has_no_persisted_metadata(): void {
		$this->assertSame( array(), $this->metadata->for_result( $this->result( 'custom', array( 'value' => 'discarded' ) ) ) );
	}

	public function test_agent_response_details_are_not_persisted(): void {
		$this->assertSame(
			array(),
			$this->metadata->for_result(
				$this->result(
					'agent_status',
					array(
						'schema_version' => '1.0',
						'wordpress'      => '6.9',
						'agent_version'  => '1.0.0',
					)
				)
			)
		);
	}

	/**
	 * @param array<string|int,mixed> $data Result data.
	 */
	private function result( string $type, array $data ): CheckResult {
		$checked_at = new DateTimeImmutable( '2026-09-10T00:00:00Z' );

		return new CheckResult( 1, $type, 'healthy', null, 'Checked.', $checked_at, $checked_at, 5, $data );
	}
}
