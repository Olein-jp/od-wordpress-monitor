<?php
/**
 * Shared monitor status values.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Monitor;

final class Status {
	public const HEALTHY  = 'healthy';
	public const WARNING  = 'warning';
	public const CRITICAL = 'critical';
	public const UNKNOWN  = 'unknown';

	private const VALUES = array(
		self::HEALTHY,
		self::WARNING,
		self::CRITICAL,
		self::UNKNOWN,
	);

	public static function is_valid( string $status ): bool {
		return in_array( $status, self::VALUES, true );
	}
}
