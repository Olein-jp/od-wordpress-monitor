<?php
/**
 * Site Health endpoint tests.
 *
 * @package OD_Monitor_Agent
 */

namespace Olein\MonitorAgent\Tests;

use Olein\MonitorAgent\Collector\SiteHealthCollector;
use Olein\MonitorAgent\Rest\SiteHealthController;
use WP_REST_Request;

final class SiteHealthControllerTest extends \WP_UnitTestCase {
	public function tear_down(): void {
		delete_site_transient( SiteHealthCollector::CACHE_KEY );
		parent::tear_down();
	}

	public function test_response_contains_normalized_cached_payload(): void {
		$payload = array(
			'summary'   => array(
				'critical'    => 0,
				'recommended' => 0,
				'good'        => 1,
			),
			'tests'     => array(
				array(
					'id'     => 'php_extensions',
					'status' => 'good',
					'label'  => 'Healthy',
				),
			),
			'timestamp' => '2026-09-10T03:00:00Z',
		);
		set_site_transient( SiteHealthCollector::CACHE_KEY, $payload, SiteHealthCollector::CACHE_TTL );

		$controller = new SiteHealthController( new SiteHealthCollector() );
		$response   = $controller->get_item( new WP_REST_Request( 'GET', '/od-monitor-agent/v1/site-health' ) );
		$data       = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '1.0', $data['schema_version'] );
		$this->assertSame( $payload['summary'], $data['summary'] );
		$this->assertSame( $payload['tests'], $data['tests'] );
		$this->assertSame( $payload['timestamp'], $data['timestamp'] );
	}
}
