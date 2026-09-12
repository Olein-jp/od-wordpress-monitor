<?php
/**
 * Encrypted Slack and Discord notification settings.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Notification;

use Throwable;

final class NotificationChannelSettings {
	public const OPTION = 'odm_notification_channel_settings';

	private const CHANNELS = array( SlackNotifier::CHANNEL_ID, DiscordNotifier::CHANNEL_ID );

	public function __construct(
		private readonly NotificationSecretEncryptor $encryptor,
		private readonly WebhookUrlValidator $validator
	) {
	}

	public function register(): void {
		add_option( self::OPTION, $this->defaults(), '', false );
		register_setting(
			NotificationSettings::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'default'           => $this->defaults(),
				'sanitize_callback' => array( $this, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
	}

	public function enabled( string $channel_id ): bool {
		$settings = $this->get();

		return isset( $settings[ $channel_id ] )
			&& '1' === $settings[ $channel_id ]['enabled']
			&& '' !== $this->webhook_url( $channel_id );
	}

	public function has_webhook( string $channel_id ): bool {
		return '' !== $this->webhook_url( $channel_id );
	}

	public function webhook_url( string $channel_id ): string {
		$settings  = $this->get();
		$encrypted = $settings[ $channel_id ]['encrypted_webhook_url'] ?? '';

		if ( '' === $encrypted ) {
			return '';
		}

		try {
			$url       = $this->encryptor->decrypt( $encrypted );
			$validated = $this->validator->validate( $channel_id, $url );

			return is_wp_error( $validated ) ? '' : $validated;
		} catch ( Throwable $exception ) {
			unset( $exception );

			return '';
		}
	}

	/**
	 * Preserve blank secrets, and only replace or delete them explicitly.
	 *
	 * @param mixed $input Submitted option value.
	 * @return array<string,array{enabled:string,encrypted_webhook_url:string}>
	 */
	public function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$current  = $this->get();
		$settings = $current;

		foreach ( self::CHANNELS as $channel_id ) {
			$submitted = isset( $input[ $channel_id ] ) && is_array( $input[ $channel_id ] ) ? $input[ $channel_id ] : array();
			$delete    = isset( $submitted['delete'] ) && '1' === (string) $submitted['delete'];
			$new_url   = isset( $submitted['webhook_url'] ) && is_scalar( $submitted['webhook_url'] )
				? trim( (string) wp_unslash( $submitted['webhook_url'] ) )
				: '';

			if ( $delete ) {
				$settings[ $channel_id ] = array(
					'enabled'               => '0',
					'encrypted_webhook_url' => '',
				);
				continue;
			}

			if ( '' !== $new_url ) {
				$validated = $this->validator->validate( $channel_id, $new_url );
				if ( is_wp_error( $validated ) ) {
					add_settings_error(
						self::OPTION,
						'invalid_' . $channel_id . '_webhook',
						sprintf(
							/* translators: %s: notification service name. */
							__( '%s webhook URL was not changed because it is invalid.', 'od-wordpress-monitor' ),
							ucfirst( $channel_id )
						)
					);
					continue;
				}

				$settings[ $channel_id ]['encrypted_webhook_url'] = $this->encryptor->encrypt( $validated );
			}

			$settings[ $channel_id ]['enabled'] = isset( $submitted['enabled'] )
				&& '1' === (string) $submitted['enabled']
				&& '' !== $settings[ $channel_id ]['encrypted_webhook_url']
				? '1'
				: '0';
		}

		return $settings;
	}

	/**
	 * @return array<string,array{enabled:string,encrypted_webhook_url:string}>
	 */
	private function get(): array {
		$stored = get_option( self::OPTION, $this->defaults() );
		$stored = is_array( $stored ) ? $stored : array();
		$result = $this->defaults();

		foreach ( self::CHANNELS as $channel_id ) {
			$channel               = isset( $stored[ $channel_id ] ) && is_array( $stored[ $channel_id ] ) ? $stored[ $channel_id ] : array();
			$result[ $channel_id ] = array(
				'enabled'               => isset( $channel['enabled'] ) && '1' === (string) $channel['enabled'] ? '1' : '0',
				'encrypted_webhook_url' => isset( $channel['encrypted_webhook_url'] ) && is_string( $channel['encrypted_webhook_url'] ) ? $channel['encrypted_webhook_url'] : '',
			);
		}

		return $result;
	}

	/**
	 * @return array<string,array{enabled:string,encrypted_webhook_url:string}>
	 */
	private function defaults(): array {
		return array(
			SlackNotifier::CHANNEL_ID   => array(
				'enabled'               => '0',
				'encrypted_webhook_url' => '',
			),
			DiscordNotifier::CHANNEL_ID => array(
				'enabled'               => '0',
				'encrypted_webhook_url' => '',
			),
		);
	}
}
