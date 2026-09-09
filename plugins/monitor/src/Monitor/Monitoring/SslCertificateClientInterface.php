<?php
/**
 * SSL certificate inspection contract.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Monitor\Monitoring;

use WP_Error;

interface SslCertificateClientInterface {
	/**
	 * Inspect a verified peer certificate.
	 *
	 * @return array{valid_from:int,valid_to:int}|WP_Error
	 */
	public function inspect( string $host, int $port, int $timeout ): array|WP_Error;
}
