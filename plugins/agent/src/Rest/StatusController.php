<?php
/**
 * Status endpoint.
 *
 * @package OD_Monitor_Agent
 */

namespace Olein\MonitorAgent\Rest;

use Olein\MonitorAgent\Collector\ServerCollector;
use Olein\MonitorAgent\Collector\SiteCollector;
use Olein\MonitorAgent\Collector\WordPressCollector;
use Olein\MonitorAgent\Support\Version;
use WP_REST_Response;
use WP_REST_Server;

final class StatusController extends RestController {
	public function __construct(
		private readonly SiteCollector $site_collector,
		private readonly WordPressCollector $wordpress_collector,
		private readonly ServerCollector $server_collector
	) {
		parent::__construct();
	}

	/**
	 * Register GET /status.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}

	/**
	 * Return the safe status payload.
	 */
	public function get_item( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return new WP_REST_Response(
			array(
				'schema_version' => self::SCHEMA_VERSION,
				'site'           => $this->site_collector->collect(),
				'wordpress'      => $this->wordpress_collector->collect(),
				'server'         => $this->server_collector->collect(),
				'agent'          => array( 'version' => Version::get() ),
				'timestamp'      => $this->timestamp(),
			),
			200
		);
	}
}
