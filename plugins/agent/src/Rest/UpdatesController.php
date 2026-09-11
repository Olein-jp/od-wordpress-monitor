<?php
/**
 * Updates endpoint.
 *
 * @package OD_Monitor_Agent
 */

namespace Olein\MonitorAgent\Rest;

use Olein\MonitorAgent\Collector\UpdateCollector;
use Throwable;
use WP_Error;
use WP_REST_Response;
use WP_REST_Server;

final class UpdatesController extends RestController {
	public function __construct( private readonly UpdateCollector $update_collector ) {
		parent::__construct();
	}

	/**
	 * Register GET /updates.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/updates',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}

	/**
	 * Return the safe update payload.
	 */
	public function get_item( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		try {
			return new WP_REST_Response(
				array_merge(
					array( 'schema_version' => self::SCHEMA_VERSION ),
					$this->update_collector->collect(),
					array( 'timestamp' => $this->timestamp() )
				),
				200
			);
		} catch ( Throwable ) {
			return $this->unavailable_error();
		}
	}
}
