<?php
/**
 * Atomic expiring check lock backed by the WordPress options table.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Scheduler;

use Closure;
use InvalidArgumentException;
use Olein\WordPressMonitor\Site\Site;
use wpdb;

final class CheckLock implements CheckLockInterface {
	public const DEFAULT_TTL = 300;

	private readonly Closure $clock;

	public function __construct( private readonly wpdb $database, private readonly int $ttl = self::DEFAULT_TTL, ?Closure $clock = null ) {
		if ( $ttl < 1 ) {
			throw new InvalidArgumentException( 'The check lock TTL must be positive.' );
		}

		$this->clock = $clock ?? static fn(): int => time();
	}

	public function acquire( Site $site, string $check_type ): ?string {
		$key       = $this->key( $site, $check_type );
		$token     = wp_generate_uuid4();
		$expires   = ( $this->clock )() + $this->ttl;
		$value     = $expires . ':' . $token;
		$existing  = get_option( $key, '' );
		$separator = is_string( $existing ) ? strpos( $existing, ':' ) : false;

		if ( '' === $existing && add_option( $key, $value, '', false ) ) {
			return $token;
		}

		if ( false === $separator || (int) substr( $existing, 0, $separator ) > ( $this->clock )() ) {
			return null;
		}

		$this->delete_value( $key, $existing );

		return add_option( $key, $value, '', false ) ? $token : null;
	}

	public function release( Site $site, string $check_type, string $token ): void {
		$key      = $this->key( $site, $check_type );
		$existing = get_option( $key, '' );

		if ( ! is_string( $existing ) || ! str_ends_with( $existing, ':' . $token ) ) {
			return;
		}

		$this->delete_value( $key, $existing );
	}

	private function key( Site $site, string $check_type ): string {
		return 'odm_lock_' . $site->uuid() . '_' . $check_type;
	}

	/**
	 * Delete only the lock value that was observed, preserving a replacement lock.
	 */
	private function delete_value( string $key, string $value ): void {
		$this->database->delete(
			$this->database->options,
			array(
				'option_name'  => $key,
				'option_value' => $value,
			),
			array( '%s', '%s' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		wp_cache_delete( $key, 'options' );
	}
}
