<?php
/**
 * Chatwork message notification delivery.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Notification;

final class ChatworkNotifier implements NotificationSenderInterface {
	public const CHANNEL_ID = 'chatwork';

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
		$room_id   = $this->settings->room_id();
		$api_token = $this->settings->api_token();
		$text      = $this->formatter->format( $message );

		if ( '' === $room_id || '' === $api_token ) {
			return NotificationChannelResult::failed( self::CHANNEL_ID, 'CHATWORK_SETTINGS_INVALID' );
		}

		if ( '' === $text ) {
			return NotificationChannelResult::failed( self::CHANNEL_ID, 'NOTIFICATION_TYPE_INVALID' );
		}

		$text = preg_replace_callback(
			'/\[(?:To:[0-9]+|toall)\]/i',
			static fn( array $matches ): string => '［' . substr( $matches[0], 1 ),
			$text
		);
		$text = is_string( $text ) ? $text : '';
		if ( function_exists( 'mb_substr' ) ) {
			$text = mb_substr( $text, 0, 65535, 'UTF-8' );
		} else {
			$text = substr( $text, 0, 65535 );
		}

		return $this->client->post_chatwork( $room_id, $api_token, $text );
	}
}
