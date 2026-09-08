<?php
/**
 * WordPress HTTP API adapter.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Http;

use WP_Error;

final class HttpClient {
	/**
	 * Make a safe GET request.
	 *
	 * @param array<string,mixed> $arguments Request arguments.
	 * @return array<string,mixed>|WP_Error
	 */
	public function get( string $url, array $arguments = array() ) {
		return wp_safe_remote_get( $url, $arguments );
	}
}
