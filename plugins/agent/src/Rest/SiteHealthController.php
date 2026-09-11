<?php
/**
 * Site Health endpoint.
 *
 * @package OD_Monitor_Agent
 */

namespace Olein\MonitorAgent\Rest;

use Olein\MonitorAgent\Collector\SiteHealthCollector;
use Throwable;
use WP_Error;
use WP_REST_Response;
use WP_REST_Server;

final class SiteHealthController extends RestController {
	public function __construct( private readonly SiteHealthCollector $site_health_collector ) {
		parent::__construct();
	}

	/**
	 * Register GET /site-health.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/site-health',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}

	/**
	 * Return the normalized Site Health payload.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		try {
			$payload = $this->site_health_collector->collect();

			if ( is_wp_error( $payload ) ) {
				return $payload;
			}

			return new WP_REST_Response(
				array_merge(
					array( 'schema_version' => self::SCHEMA_VERSION ),
					$payload
				),
				200
			);
		} catch ( Throwable ) {
			return $this->unavailable_error();
		}
	}
}
