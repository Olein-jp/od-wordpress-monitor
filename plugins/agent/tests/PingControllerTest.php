<?php
/**
 * Ping endpoint tests.
 *
 * @package OD_Monitor_Agent
 */

namespace Olein\MonitorAgent\Tests;

use Olein\MonitorAgent\Auth\Capability;
use Olein\MonitorAgent\Rest\PingController;
use WP_REST_Request;

final class PingControllerTest extends \WP_UnitTestCase {
	private PingController $controller;

	public function set_up(): void {
		parent::set_up();
		$this->controller = new PingController();
	}

	public function test_capable_user_receives_valid_ping(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_user_by( 'id', $user_id );
		$user->add_cap( Capability::READ );
		wp_set_current_user( $user_id );

		$this->assertTrue( $this->controller->permissions_check() );

		$response = $this->controller->get_item( new WP_REST_Request( 'GET', '/od-monitor-agent/v1/ping' ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '1.0', $data['schema_version'] );
		$this->assertTrue( $data['success'] );
		$this->assertSame( 'od-monitor-agent', $data['agent']['slug'] );
		$this->assertNotEmpty( $data['timestamp'] );
	}

	public function test_anonymous_user_is_denied(): void {
		wp_set_current_user( 0 );
		$result = $this->controller->permissions_check();

		$this->assertWPError( $result );
		$this->assertSame( 'od_monitor_agent_unauthorized', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_user_without_capability_is_denied(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );
		$result = $this->controller->permissions_check();

		$this->assertWPError( $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}
}
