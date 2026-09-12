<?php
/**
 * Aggregate result for all enabled notification channels.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Notification;

use InvalidArgumentException;

final class NotificationDeliveryResult {
	public const SENT    = 'sent';
	public const PARTIAL = 'partial';
	public const FAILED  = 'failed';

	/** @var array<string,NotificationChannelResult> */
	private readonly array $channels;

	/**
	 * @param list<NotificationChannelResult> $channels Attempted channel results.
	 */
	public function __construct( array $channels ) {
		$indexed = array();

		foreach ( $channels as $channel ) {
			if ( ! $channel instanceof NotificationChannelResult ) {
				throw new InvalidArgumentException( 'Notification delivery results must contain channel results.' );
			}

			if ( isset( $indexed[ $channel->channel_id() ] ) ) {
				throw new InvalidArgumentException( 'Notification delivery channel IDs must be unique.' );
			}

			$indexed[ $channel->channel_id() ] = $channel;
		}

		if ( array() === $indexed ) {
			throw new InvalidArgumentException( 'Notification delivery results cannot be empty.' );
		}

		$this->channels = $indexed;
	}

	public function status(): string {
		$succeeded = count(
			array_filter(
				$this->channels,
				static fn( NotificationChannelResult $channel ): bool => $channel->succeeded()
			)
		);

		if ( count( $this->channels ) === $succeeded ) {
			return self::SENT;
		}

		return 0 === $succeeded ? self::FAILED : self::PARTIAL;
	}

	/**
	 * @return array<string,NotificationChannelResult>
	 */
	public function channels(): array {
		return $this->channels;
	}
}
