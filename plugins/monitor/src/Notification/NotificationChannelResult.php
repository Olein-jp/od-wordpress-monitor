<?php
/**
 * Result of one notification channel delivery attempt.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Notification;

use InvalidArgumentException;

final class NotificationChannelResult {
	public const SENT   = 'sent';
	public const FAILED = 'failed';

	private function __construct(
		private readonly string $channel_id,
		private readonly string $status,
		private readonly int $attempts,
		private readonly ?string $error_code
	) {
		if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $channel_id ) ) {
			throw new InvalidArgumentException( 'Notification channel IDs must use lowercase safe characters.' );
		}

		if ( ! in_array( $status, array( self::SENT, self::FAILED ), true ) || $attempts < 1 ) {
			throw new InvalidArgumentException( 'The notification channel result is invalid.' );
		}
	}

	public static function sent( string $channel_id, int $attempts = 1 ): self {
		return new self( $channel_id, self::SENT, $attempts, null );
	}

	public static function failed( string $channel_id, string $error_code = 'DELIVERY_FAILED', int $attempts = 1 ): self {
		$error_code = 1 === preg_match( '/^[A-Z0-9_]{1,100}$/', $error_code ) ? $error_code : 'DELIVERY_FAILED';

		return new self( $channel_id, self::FAILED, $attempts, $error_code );
	}

	public function channel_id(): string {
		return $this->channel_id;
	}

	public function status(): string {
		return $this->status;
	}

	public function attempts(): int {
		return $this->attempts;
	}

	public function error_code(): ?string {
		return $this->error_code;
	}

	public function succeeded(): bool {
		return self::SENT === $this->status;
	}
}
