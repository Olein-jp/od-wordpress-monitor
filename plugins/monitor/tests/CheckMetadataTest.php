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
					'total_updates'     => 6,
					'wordpress_updates' => 1,
					'plugin_updates'    => 3,
					'theme_updates'     => 2,
					'plugins'           => array( 'private-plugin' ),
					'invalid_count'     => -1,
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
