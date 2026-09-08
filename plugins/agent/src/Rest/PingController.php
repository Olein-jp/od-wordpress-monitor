<?php
/**
 * Ping endpoint.
 *
 * @package OD_Monitor_Agent
 */

namespace Olein\MonitorAgent\Rest;

use Olein\MonitorAgent\Support\Version;
use WP_REST_Response;
use WP_REST_Server;

final class PingController extends RestController {
	/**
	 * Register GET /ping.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/ping',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}

	/**
	 * Return protocol and Agent availability data.
	 */
	public function get_item( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return new WP_REST_Response(
			array(
				'schema_version' => self::SCHEMA_VERSION,
				'success'        => true,
				'agent'          => array(
					'slug'    => 'od-monitor-agent',
					'version' => Version::get(),
				),
				'timestamp'      => $this->timestamp(),
			),
			200
		);
	}
}
