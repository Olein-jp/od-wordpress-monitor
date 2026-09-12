<?php
/**
 * Slack Incoming Webhook notification delivery.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Notification;

final class SlackNotifier implements NotificationSenderInterface {
	public const CHANNEL_ID = 'slack';

	public function __construct(
		private readonly NotificationChannelSettings $settings,
		private readonly WebhookClient $client,
		private readonly NotificationTextFormatter $formatter
	) {
	}

	public function channel_id(): string {
		return self::CHANNEL_ID;
	}

	public function enabled(): bool {
		return $this->settings->enabled( self::CHANNEL_ID );
	}

	public function send( NotificationMessage $message ): NotificationChannelResult {
		$url  = $this->settings->webhook_url( self::CHANNEL_ID );
		$text = $this->formatter->format( $message );

		if ( '' === $url ) {
			return NotificationChannelResult::failed( self::CHANNEL_ID, 'WEBHOOK_UNAVAILABLE' );
		}

		if ( '' === $text ) {
			return NotificationChannelResult::failed( self::CHANNEL_ID, 'NOTIFICATION_TYPE_INVALID' );
		}

		return $this->client->post( self::CHANNEL_ID, $url, array( 'text' => $text ) );
	}
}
