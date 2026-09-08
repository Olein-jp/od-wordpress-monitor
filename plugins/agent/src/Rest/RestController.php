<?php
/**
 * Shared REST controller behavior.
 *
 * @package OD_Monitor_Agent
 */

namespace Olein\MonitorAgent\Rest;

use Olein\MonitorAgent\Auth\Capability;
use WP_Error;
use WP_REST_Controller;

abstract class RestController extends WP_REST_Controller {
	public const API_NAMESPACE  = 'od-monitor-agent/v1';
	public const SCHEMA_VERSION = '1.0';

	/**
	 * Set the shared namespace.
	 */
	public function __construct() {
		$this->namespace = self::API_NAMESPACE;
	}

	/**
	 * Require the dedicated read capability.
	 *
	 * @return true|WP_Error
	 */
	public function permissions_check() {
		if ( current_user_can( Capability::READ ) ) {
			return true;
		}

		return new WP_Error(
			'od_monitor_agent_forbidden',
			__( 'You are not allowed to read monitoring data.', 'od-monitor-agent' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Return an ISO 8601 timestamp in UTC.
	 */
	protected function timestamp(): string {
		return gmdate( 'Y-m-d\\TH:i:s\\Z' );
	}
}
