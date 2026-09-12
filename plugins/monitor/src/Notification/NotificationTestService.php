<?php
/**
 * Sends an administrator-requested test notification.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Notification;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

final class NotificationTestService {
	/** @var array<string,NotificationSenderInterface> */
	private array $senders = array();

	/**
	 * @param list<NotificationSenderInterface> $senders Supported test channels.
	 */
	public function __construct( array $senders ) {
		foreach ( $senders as $sender ) {
			$this->senders[ $sender->channel_id() ] = $sender;
		}
	}

	public function send( string $channel_id ): NotificationChannelResult {
		if ( ! isset( $this->senders[ $channel_id ] ) ) {
			throw new InvalidArgumentException( 'The notification test channel is invalid.' );
		}

		$message = new NotificationMessage(
			NotificationRule::OUTAGE,
			__( 'Test notification', 'od-wordpress-monitor' ),
			home_url( '/' ),
			'TEST_NOTIFICATION',
			'healthy',
			'critical',
			new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ),
			'—',
			__( 'This is a test notification from OD WordPress Monitor.', 'od-wordpress-monitor' )
		);

		try {
			return $this->senders[ $channel_id ]->send( $message );
		} catch ( Throwable $exception ) {
			unset( $exception );

			return NotificationChannelResult::failed( $channel_id, 'DELIVERY_EXCEPTION' );
		}
	}
}
