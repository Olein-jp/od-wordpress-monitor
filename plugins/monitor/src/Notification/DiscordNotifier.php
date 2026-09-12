<?php
/**
 * Discord Incoming Webhook notification delivery.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Notification;

final class DiscordNotifier implements NotificationSenderInterface {
	public const CHANNEL_ID = 'discord';

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

		if ( function_exists( 'mb_substr' ) ) {
			$text = mb_substr( $text, 0, 2000, 'UTF-8' );
		} else {
			$text = substr( $text, 0, 2000 );
		}

		return $this->client->post(
			self::CHANNEL_ID,
			$url,
			array(
				'content'          => $text,
				'allowed_mentions' => array( 'parse' => array() ),
			)
		);
	}
}
