<?php
/**
 * UUID generation.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Support;

final class UUID {
	/**
	 * Generate a UUID v4.
	 */
	public function generate(): string {
		return wp_generate_uuid4();
	}

	/**
	 * Validate a UUID v4.
	 */
	public function is_valid( string $uuid ): bool {
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid );
	}
}
