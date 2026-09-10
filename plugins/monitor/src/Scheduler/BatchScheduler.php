<?php
/**
 * Persists batch cursors and schedules safe continuation events.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Scheduler;

use Closure;
use InvalidArgumentException;
use wpdb;

final class BatchScheduler {
	public const HOOK = 'odm_continue_scheduled_check';

	private const DEFAULT_LOCK_TTL   = 900;
	private const CONTINUATION_DELAY = 5;
	private const STATE_PREFIX       = 'odm_batch_state_';
	private const LOCK_PREFIX        = 'odm_lock_batch_';

	private readonly Closure $clock;

	/**
	 * @param null|Closure():int $clock Unix timestamp provider.
	 */
	public function __construct( private readonly wpdb $database, private readonly int $lock_ttl = self::DEFAULT_LOCK_TTL, ?Closure $clock = null ) {
		if ( $lock_ttl < 1 ) {
			throw new InvalidArgumentException( 'The batch lock TTL must be positive.' );
		}

		$this->clock = $clock ?? static fn(): int => time();
	}

	public function acquire( string $check_type ): ?string {
		$key       = $this->lock_key( $check_type );
		$token     = wp_generate_uuid4();
		$value     = ( ( $this->clock )() + $this->lock_ttl ) . ':' . $token;
		$existing  = get_option( $key, '' );
		$separator = is_string( $existing ) ? strpos( $existing, ':' ) : false;

		if ( '' === $existing && add_option( $key, $value, '', false ) ) {
			return $token;
		}

		if ( false === $separator || (int) substr( $existing, 0, $separator ) > ( $this->clock )() ) {
			return null;
		}

		$this->delete_lock_value( $key, $existing );

		return add_option( $key, $value, '', false ) ? $token : null;
	}

	public function release( string $check_type, string $token ): void {
		$key      = $this->lock_key( $check_type );
		$existing = get_option( $key, '' );

		if ( is_string( $existing ) && str_ends_with( $existing, ':' . $token ) ) {
			$this->delete_lock_value( $key, $existing );
		}
	}

	/**
	 * Start a new traversal or resume the current one.
	 *
	 * @return array{generation:string,cursor:int}
	 */
	public function begin_or_resume( string $check_type ): array {
		$current = $this->current( $check_type );

		if ( null !== $current ) {
			return $current;
		}

		$state = array(
			'generation' => wp_generate_uuid4(),
			'cursor'     => 0,
		);
		update_option( $this->state_key( $check_type ), $state, false );

		return $state;
	}

	/**
	 * Return the current traversal only when a continuation belongs to it.
	 *
	 * @return array{generation:string,cursor:int}|null
	 */
	public function resume( string $check_type, string $generation ): ?array {
		$current = $this->current( $check_type );

		return null !== $current && hash_equals( $current['generation'], $generation ) ? $current : null;
	}

	public function advance( string $check_type, string $generation, int $cursor ): bool {
		$current = $this->resume( $check_type, $generation );

		if ( null === $current || $cursor <= $current['cursor'] ) {
			return false;
		}

		return update_option(
			$this->state_key( $check_type ),
			array(
				'generation' => $generation,
				'cursor'     => $cursor,
			),
			false
		);
	}

	public function complete( string $check_type, string $generation ): void {
		if ( null === $this->resume( $check_type, $generation ) ) {
			return;
		}

		$this->clear_continuation( $check_type, $generation );
		delete_option( $this->state_key( $check_type ) );
	}

	public function schedule_continuation( string $check_type, string $generation ): bool {
		$args = array( $check_type, $generation );

		if ( false !== wp_next_scheduled( self::HOOK, $args ) ) {
			return true;
		}

		return true === wp_schedule_single_event( ( $this->clock )() + self::CONTINUATION_DELAY, self::HOOK, $args, true );
	}

	public function has_pending_continuation( string $check_type ): bool {
		$current = $this->current( $check_type );

		return null !== $current && false !== wp_next_scheduled( self::HOOK, array( $check_type, $current['generation'] ) );
	}

	public function clear_continuation( string $check_type, string $generation ): void {
		wp_clear_scheduled_hook( self::HOOK, array( $check_type, $generation ) );
	}

	/**
	 * @return array{generation:string,cursor:int}|null
	 */
	public function current( string $check_type ): ?array {
		$state      = get_option( $this->state_key( $check_type ), array() );
		$generation = is_array( $state ) ? ( $state['generation'] ?? null ) : null;
		$cursor     = is_array( $state ) ? ( $state['cursor'] ?? null ) : null;

		if ( ! is_string( $generation ) || ! wp_is_uuid( $generation ) || ! is_int( $cursor ) || $cursor < 0 ) {
			return null;
		}

		return array(
			'generation' => $generation,
			'cursor'     => $cursor,
		);
	}

	private function state_key( string $check_type ): string {
		return self::STATE_PREFIX . $this->valid_type( $check_type );
	}

	private function lock_key( string $check_type ): string {
		return self::LOCK_PREFIX . $this->valid_type( $check_type );
	}

	private function valid_type( string $check_type ): string {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_-]*$/', $check_type ) ) {
			throw new InvalidArgumentException( 'The batch check type is invalid.' );
		}

		return $check_type;
	}

	private function delete_lock_value( string $key, string $value ): void {
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
