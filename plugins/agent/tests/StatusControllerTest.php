<?php
/**
 * Status endpoint tests.
 *
 * @package OD_Monitor_Agent
 */

namespace Olein\MonitorAgent\Tests;

use Olein\MonitorAgent\Collector\ServerCollector;
use Olein\MonitorAgent\Collector\SiteCollector;
use Olein\MonitorAgent\Collector\WordPressCollector;
use Olein\MonitorAgent\Rest\StatusController;
use WP_REST_Request;

final class StatusControllerTest extends \WP_UnitTestCase {
	public function test_status_contains_expected_safe_metadata(): void {
		global $wp_version;

		$controller = new StatusController( new SiteCollector(), new WordPressCollector(), new ServerCollector() );
		$response   = $controller->get_item( new WP_REST_Request( 'GET', '/od-monitor-agent/v1/status' ) );
		$data       = $response->get_data();

		$this->assertSame( '1.0', $data['schema_version'] );
		$this->assertSame( $wp_version, $data['wordpress']['version'] );
		$this->assertSame( is_multisite(), $data['wordpress']['multisite'] );
		$this->assertSame( PHP_VERSION, $data['server']['php_version'] );
		$this->assertSame( OD_MONITOR_AGENT_VERSION, $data['agent']['version'] );
		$this->assertArrayHasKey( 'url', $data['site'] );
		$this->assertArrayHasKey( 'home_url', $data['site'] );
		$this->assertArrayHasKey( 'name', $data['site'] );
	}
}
