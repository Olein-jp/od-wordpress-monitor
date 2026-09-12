<?php
/**
 * Plain-text email notification delivery.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Notification;

use DateTimeZone;
use Throwable;

final class EmailNotifier implements NotificationSenderInterface {
	public const CHANNEL_ID = 'email';

	public function __construct( private readonly NotificationSettings $settings ) {
	}

	public function channel_id(): string {
		return self::CHANNEL_ID;
	}

	public function enabled(): bool {
		return $this->settings->enabled() && '' !== $this->settings->email();
	}

	public function send( NotificationMessage $message ): NotificationChannelResult {
		$recipient = $this->settings->email();

		if ( '' === $recipient ) {
			return NotificationChannelResult::failed( self::CHANNEL_ID, 'EMAIL_RECIPIENT_INVALID' );
		}

		if ( NotificationRule::OUTAGE === $message->notification_type() ) {
			$label = __( 'Outage', 'od-wordpress-monitor' );
		} elseif ( NotificationRule::RECOVERY === $message->notification_type() ) {
			$label = __( 'Recovery', 'od-wordpress-monitor' );
		} elseif ( NotificationRule::SSL_WARNING === $message->notification_type() ) {
			$label = __( 'SSL certificate expiry warning', 'od-wordpress-monitor' );
		} elseif ( NotificationRule::DAILY_DIGEST === $message->notification_type() ) {
			$label = __( 'Daily digest', 'od-wordpress-monitor' );
		} else {
			return NotificationChannelResult::failed( self::CHANNEL_ID, 'NOTIFICATION_TYPE_INVALID' );
		}

		$subject = sprintf(
			/* translators: 1: Notification type, 2: monitored site name. */
			__( '[OD Monitor] %1$s: %2$s', 'od-wordpress-monitor' ),
			$label,
			$message->site_name()
		);
		$body = implode(
			"\n",
			array(
				sprintf( /* translators: %s: outage or recovery. */ __( 'Notification: %s', 'od-wordpress-monitor' ), $label ),
				sprintf( /* translators: %s: monitored site name. */ __( 'Site: %s', 'od-wordpress-monitor' ), $message->site_name() ),
				sprintf( /* translators: %s: monitored public URL. */ __( 'URL: %s', 'od-wordpress-monitor' ), $message->site_url() ),
				sprintf( /* translators: %s: monitoring event type. */ __( 'Event: %s', 'od-wordpress-monitor' ), $message->event_type() ),
				sprintf( /* translators: %s: previous monitoring status. */ __( 'Previous status: %s', 'od-wordpress-monitor' ), $message->previous_status() ),
				sprintf( /* translators: %s: current monitoring status. */ __( 'Current status: %s', 'od-wordpress-monitor' ), $message->current_status() ),
				sprintf(
					/* translators: %s: UTC detection time. */
					__( 'Detected at: %s', 'od-wordpress-monitor' ),
					$message->occurred_at()->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s \U\T\C' )
				),
				sprintf( /* translators: %s: safe monitoring error code or dash. */ __( 'Error code: %s', 'od-wordpress-monitor' ), $message->error_code() ),
				sprintf( /* translators: %s: safe monitoring result message. */ __( 'Message: %s', 'od-wordpress-monitor' ), $message->message() ),
			)
		);

		try {
			return wp_mail( $recipient, $subject, $body )
				? NotificationChannelResult::sent( self::CHANNEL_ID )
				: NotificationChannelResult::failed( self::CHANNEL_ID, 'EMAIL_SEND_FAILED' );
		} catch ( Throwable $exception ) {
			unset( $exception );

			return NotificationChannelResult::failed( self::CHANNEL_ID, 'EMAIL_SEND_EXCEPTION' );
		}
	}
}
