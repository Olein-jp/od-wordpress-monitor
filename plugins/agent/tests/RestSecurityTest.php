<?php
/**
 * REST API security boundary tests.
 *
 * @package OD_Monitor_Agent
 */

namespace Olein\MonitorAgent\Tests;

use Olein\MonitorAgent\Auth\Capability;
use Olein\MonitorAgent\Collector\SiteHealthCollector;
use RuntimeException;
use WP_REST_Request;

final class RestSecurityTest extends \WP_UnitTestCase {
	/**
	 * @dataProvider route_provider
	 */
	public function test_anonymous_requests_are_rejected_without_monitoring_data( string $route ): void {
		wp_set_current_user( 0 );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', $route ) );
		$data     = $response->get_data();

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'od_monitor_agent_unauthorized', $data['code'] );
		$this->assertArrayNotHasKey( 'schema_version', $data );
	}

	/**
	 * @dataProvider route_provider
	 */
	public function test_authenticated_users_without_capability_are_forbidden( string $route ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', $route ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'od_monitor_agent_forbidden', $response->get_data()['code'] );
	}

	/**
	 * @dataProvider route_provider
	 */
	public function test_dedicated_capability_allows_each_endpoint( string $route ): void {
		$this->set_capable_user();
		set_site_transient(
			SiteHealthCollector::CACHE_KEY,
			array(
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
				'timestamp' => '2026-09-11T00:00:00Z',
			),
			SiteHealthCollector::CACHE_TTL
		);

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', $route ) );
		$encoded  = wp_json_encode( $response->get_data() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertIsString( $encoded );
		$this->assertDoesNotMatchRegularExpression( '/application_password|authorization|cookie|credential|password|secret|token/i', $encoded );
	}

	/**
	 * @dataProvider exception_route_provider
	 */
	public function test_collection_exceptions_are_replaced_with_a_secret_free_error( string $route, string $filter ): void {
		$this->set_capable_user();
		add_filter(
			$filter,
			static function (): void {
				throw new RuntimeException( 'Authorization: Basic dXNlcjpzdXBlcnNlY3JldA== at /var/www/html/wp-config.php' );
			}
		);

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', $route ) );
		$encoded  = wp_json_encode( $response->get_data() );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'od_monitor_agent_unavailable', $response->get_data()['code'] );
		$this->assertIsString( $encoded );
		$this->assertStringNotContainsString( 'dXNlcjpzdXBlcnNlY3JldA==', $encoded );
		$this->assertStringNotContainsString( 'wp-config.php', $encoded );
	}

	/**
	 * @return array<string,array{string,string}>
	 */
	public function exception_route_provider(): array {
		return array(
			'status'      => array( '/od-monitor-agent/v1/status', 'home_url' ),
			'updates'     => array( '/od-monitor-agent/v1/updates', 'pre_site_transient_update_core' ),
			'site-health' => array( '/od-monitor-agent/v1/site-health', 'pre_site_transient_' . SiteHealthCollector::CACHE_KEY ),
		);
	}

	/**
	 * @return array<string,array{string}>
	 */
	public function route_provider(): array {
		return array(
			'ping'        => array( '/od-monitor-agent/v1/ping' ),
			'status'      => array( '/od-monitor-agent/v1/status' ),
			'updates'     => array( '/od-monitor-agent/v1/updates' ),
			'site-health' => array( '/od-monitor-agent/v1/site-health' ),
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'home_url' );
		remove_all_filters( 'pre_site_transient_update_core' );
		remove_all_filters( 'pre_site_transient_' . SiteHealthCollector::CACHE_KEY );
		delete_site_transient( SiteHealthCollector::CACHE_KEY );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function set_capable_user(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_user_by( 'id', $user_id );
		$user->add_cap( Capability::READ );
		wp_set_current_user( $user_id );
	}
}
