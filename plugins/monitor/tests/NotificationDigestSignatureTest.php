<?php
/**
 * Daily digest signature tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use Olein\WordPressMonitor\Notification\NotificationDigestSignature;

final class NotificationDigestSignatureTest extends \WP_UnitTestCase {
	public function test_update_signature_ignores_order_timestamp_and_display_name(): void {
		$signature                                    = new NotificationDigestSignature();
		$first                                        = array(
			'wordpress_updates'  => 1,
			'software_inventory' => array(
				'collected_at' => '2026-09-12T01:00:00Z',
				'theme'        => array(
					'id'               => 'theme/a',
					'name'             => 'A',
					'current_version'  => '1.0',
					'latest_version'   => '1.1',
					'update_available' => true,
				),
				'plugins'      => array(
					array(
						'id'               => 'plugin/b',
						'name'             => 'B',
						'current_version'  => '2.0',
						'latest_version'   => '2.1',
						'update_available' => true,
					),
				),
			),
		);
		$second                                       = $first;
		$second['software_inventory']['collected_at'] = '2026-09-12T02:00:00Z';
		$second['software_inventory']['theme']['name'] = 'Renamed';
		$second['software_inventory']['plugins']       = array_reverse( $second['software_inventory']['plugins'] );
		$this->assertSame( $signature->updates( $first ), $signature->updates( $second ) );
		$second['software_inventory']['theme']['latest_version'] = '1.2';
		$this->assertNotSame( $signature->updates( $first ), $signature->updates( $second ) );
	}

	public function test_site_health_signature_uses_recommended_id_and_status_only(): void {
		$signature = new NotificationDigestSignature();
		$first     = array(
			'collected_at' => '2026-09-12T01:00:00Z',
			'issues'       => array(
				array(
					'id'     => 'a',
					'status' => 'recommended',
					'label'  => 'A',
				),
			),
		);
		$second    = array(
			'collected_at' => '2026-09-12T02:00:00Z',
			'issues'       => array(
				array(
					'id'     => 'a',
					'status' => 'recommended',
					'label'  => 'Changed',
				),
			),
		);
		$this->assertSame( $signature->site_health( $first ), $signature->site_health( $second ) );
		$second['issues'][0]['id'] = 'b';
		$this->assertNotSame( $signature->site_health( $first ), $signature->site_health( $second ) );
	}
}
