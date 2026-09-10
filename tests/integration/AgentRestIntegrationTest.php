<?php
/**
 * Agent REST integration tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\IntegrationTests;

use Olein\MonitorAgent\Auth\Capability;
use Olein\MonitorAgent\Auth\Role;
use Olein\MonitorAgent\Activation\Activator;
use WP_REST_Request;

final class AgentRestIntegrationTest extends \WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		do_action( 'rest_api_init' );
	}

	public function test_registered_ping_route_rejects_anonymous_request(): void {
		wp_set_current_user( 0 );
		$ping    = rest_do_request( new WP_REST_Request( 'GET', '/od-monitor-agent/v1/ping' ) );
		$updates = rest_do_request( new WP_REST_Request( 'GET', '/od-monitor-agent/v1/updates' ) );
		$health  = rest_do_request( new WP_REST_Request( 'GET', '/od-monitor-agent/v1/site-health' ) );

		$this->assertSame( 403, $ping->get_status() );
		$this->assertSame( 403, $updates->get_status() );
		$this->assertSame( 403, $health->get_status() );
	}

	public function test_registered_endpoints_return_data_for_capable_user(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_user_by( 'id', $user_id );
		$user->add_cap( Capability::READ );
		wp_set_current_user( $user_id );

		$ping    = rest_do_request( new WP_REST_Request( 'GET', '/od-monitor-agent/v1/ping' ) );
		$status  = rest_do_request( new WP_REST_Request( 'GET', '/od-monitor-agent/v1/status' ) );
		$updates = rest_do_request( new WP_REST_Request( 'GET', '/od-monitor-agent/v1/updates' ) );
		$health  = rest_do_request( new WP_REST_Request( 'GET', '/od-monitor-agent/v1/site-health' ) );

		$this->assertSame( 200, $ping->get_status() );
		$this->assertSame( 200, $status->get_status() );
		$this->assertSame( 200, $updates->get_status() );
		$this->assertSame( 200, $health->get_status() );
		$this->assertSame( '1.0', $ping->get_data()['schema_version'] );
		$this->assertSame( PHP_VERSION, $status->get_data()['server']['php_version'] );
		$this->assertArrayHasKey( 'summary', $updates->get_data() );
		$this->assertArrayHasKey( 'summary', $health->get_data() );
		$this->assertNotEmpty( $health->get_data()['tests'] );
	}

	public function test_wordpress_can_issue_application_password_for_agent_user(): void {
		Activator::activate();
		$user_id = self::factory()->user->create(
			array(
				'role'       => Role::NAME,
				'user_login' => 'od-monitor-agent-test',
			)
		);
		$result  = \WP_Application_Passwords::create_new_application_password(
			$user_id,
			array( 'name' => 'OD WordPress Monitor test' )
		);
		add_filter( 'application_password_is_api_request', '__return_true' );
		$user = wp_authenticate_application_password( null, 'od-monitor-agent-test', $result[0] );
		remove_filter( 'application_password_is_api_request', '__return_true' );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result[0] );
		$this->assertInstanceOf( \WP_User::class, $user );
		$this->assertTrue( user_can( $user, Capability::READ ) );
	}
}
