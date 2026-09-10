<?php
/**
 * User-facing labels for persisted monitoring status values.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Admin;

use Olein\WordPressMonitor\Monitor\Status;

final class StatusLabel {
	/**
	 * Normalize a persisted status to the supported internal values.
	 */
	public static function normalize( string $status ): string {
		return Status::is_valid( $status ) ? $status : Status::UNKNOWN;
	}

	/**
	 * Convert an internal status value to its user-facing label.
	 */
	public static function for_status( string $status ): string {
		switch ( self::normalize( $status ) ) {
			case Status::HEALTHY:
				return __( 'Healthy', 'od-wordpress-monitor' );
			case Status::WARNING:
				return __( 'Attention', 'od-wordpress-monitor' );
			case Status::CRITICAL:
				return __( 'Problem', 'od-wordpress-monitor' );
			default:
				return __( 'Unknown', 'od-wordpress-monitor' );
		}
	}
}
