<?php
/**
 * Common monitor result value object.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Monitor;

use DateTimeImmutable;
use InvalidArgumentException;

final class CheckResult {
	public const STATUS_HEALTHY  = Status::HEALTHY;
	public const STATUS_WARNING  = Status::WARNING;
	public const STATUS_CRITICAL = Status::CRITICAL;
	public const STATUS_UNKNOWN  = Status::UNKNOWN;

	private const SENSITIVE_KEYS = array(
		'api_key',
		'application_password',
		'authorization',
		'cookie',
		'cookies',
		'credential',
		'credentials',
		'password',
		'salt',
		'secret',
		'token',
	);

	/**
	 * @param array<string|int,mixed> $data Non-sensitive result metadata.
	 */
	public function __construct(
		private readonly int $site_id,
		private readonly string $type,
		private readonly string $status,
		private readonly ?string $error_code,
		private readonly string $message,
		private readonly DateTimeImmutable $started_at,
		private readonly DateTimeImmutable $finished_at,
		private readonly int $duration_ms,
		private readonly array $data = array()
	) {
		if ( $site_id < 1 ) {
			throw new InvalidArgumentException( 'The site ID must be a positive integer.' );
		}

		if ( 1 !== preg_match( '/^[a-z][a-z0-9_-]*$/', $type ) ) {
			throw new InvalidArgumentException( 'The monitor type is invalid.' );
		}

		if ( ! Status::is_valid( $status ) ) {
			throw new InvalidArgumentException( 'The monitor status is invalid.' );
		}

		if ( null !== $error_code && 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9_.-]*$/', $error_code ) ) {
			throw new InvalidArgumentException( 'The error code is invalid.' );
		}

		if ( $finished_at < $started_at ) {
			throw new InvalidArgumentException( 'The finish time cannot be before the start time.' );
		}

		if ( $duration_ms < 0 ) {
			throw new InvalidArgumentException( 'The duration cannot be negative.' );
		}

		$this->assert_safe_string( $message );
		$this->assert_safe_data( $data );
	}

	public function site_id(): int {
		return $this->site_id;
	}

	public function type(): string {
		return $this->type;
	}

	public function status(): string {
		return $this->status;
	}

	public function error_code(): ?string {
		return $this->error_code;
	}

	public function message(): string {
		return $this->message;
	}

	public function started_at(): DateTimeImmutable {
		return $this->started_at;
	}

	public function finished_at(): DateTimeImmutable {
		return $this->finished_at;
	}

	public function duration_ms(): int {
		return $this->duration_ms;
	}

	/**
	 * @return array<string|int,mixed>
	 */
	public function data(): array {
		return $this->data;
	}

	/**
	 * Reject obvious authentication material from result strings.
	 */
	private function assert_safe_string( string $value ): void {
		if ( 1 === preg_match( '/(?:^|\s)authorization\s*:|\b(?:basic|bearer)\s+[a-z0-9+\/=._-]{8,}/i', $value ) ) {
			throw new InvalidArgumentException( 'Monitor results cannot contain authentication material.' );
		}
	}

	/**
	 * Recursively validate result metadata as scalar, non-sensitive data.
	 *
	 * @param array<string|int,mixed> $data Result metadata.
	 */
	private function assert_safe_data( array $data ): void {
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) ) {
				$normalized_key = strtolower( str_replace( '-', '_', $key ) );

				if ( in_array( $normalized_key, self::SENSITIVE_KEYS, true ) || 1 === preg_match( '/(?:^|_)(?:api_key|authorization|cookies?|credentials?|password|salt|secret|token)(?:_|$)/', $normalized_key ) ) {
					throw new InvalidArgumentException( 'Monitor result data cannot contain sensitive fields.' );
				}
			}

			if ( is_array( $value ) ) {
				$this->assert_safe_data( $value );
			} elseif ( is_string( $value ) ) {
				$this->assert_safe_string( $value );
			} elseif ( is_float( $value ) && ! is_finite( $value ) ) {
				throw new InvalidArgumentException( 'Monitor result data must contain finite numbers.' );
			} elseif ( null !== $value && ! is_int( $value ) && ! is_float( $value ) && ! is_bool( $value ) ) {
				throw new InvalidArgumentException( 'Monitor result data must be JSON-safe scalar or array values.' );
			}
		}
	}
}
