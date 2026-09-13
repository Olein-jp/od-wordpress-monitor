<?php
/**
 * Encrypted Slack, Discord, and Chatwork notification settings.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Notification;

use Throwable;

final class NotificationChannelSettings {
	public const OPTION = 'odm_notification_channel_settings';

	private const WEBHOOK_CHANNELS = array( SlackNotifier::CHANNEL_ID, DiscordNotifier::CHANNEL_ID );

	public function __construct(
		private readonly NotificationSecretEncryptor $encryptor,
		private readonly WebhookUrlValidator $validator
	) {
	}

	public function register(): void {
		add_option( self::OPTION, $this->defaults(), '', false );
		// A registered default can make update_option() sanitize again through add_option().
		register_setting(
			NotificationSettings::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
	}

	public function enabled( string $channel_id ): bool {
		$settings = $this->get();

		if ( ChatworkNotifier::CHANNEL_ID === $channel_id ) {
			return '1' === $settings[ $channel_id ]['enabled']
				&& '' !== $settings[ $channel_id ]['room_id']
				&& '' !== $this->api_token();
		}

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

		$url = $this->decrypt( $encrypted );
		if ( '' !== $url ) {
			$validated = $this->validator->validate( $channel_id, $url );

			return is_wp_error( $validated ) ? '' : $validated;
		}

		return '';
	}

	public function room_id(): string {
		return $this->get()[ ChatworkNotifier::CHANNEL_ID ]['room_id'];
	}

	public function has_api_token(): bool {
		return '' !== $this->api_token();
	}

	public function api_token(): string {
		$token = $this->decrypt( $this->get()[ ChatworkNotifier::CHANNEL_ID ]['encrypted_api_token'] );

		return 1 === preg_match( '/^[A-Za-z0-9._~-]{1,255}$/', $token ) ? $token : '';
	}

	public function ssl_warning_enabled(): bool {
		return '1' === $this->get()['rules']['ssl_warning'];
	}

	public function updates_digest_enabled(): bool {
		return '1' === $this->get()['rules']['updates_digest'];
	}

	public function site_health_digest_enabled(): bool {
		return '1' === $this->get()['rules']['site_health_recommended_digest'];
	}

	public function digest_hour(): int {
		return (int) $this->get()['rules']['digest_hour'];
	}

	/**
	 * Preserve blank secrets, and only replace or delete them explicitly.
	 *
	 * @param mixed $input Submitted option value.
	 * @return array<string,array<string,string>>
	 */
	public function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$current  = $this->get();
		$settings = $current;

		foreach ( self::WEBHOOK_CHANNELS as $channel_id ) {
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

		$settings = $this->sanitize_chatwork( $input, $settings );

		return $this->sanitize_rules( $input, $settings );
	}

	/**
	 * @param array<string,mixed>                $input Submitted settings.
	 * @param array<string,array<string,string>> $settings Current sanitized settings.
	 * @return array<string,array<string,string>>
	 */
	private function sanitize_rules( array $input, array $settings ): array {
		if ( ! isset( $input['rules'] ) || ! is_array( $input['rules'] ) ) {
			return $settings;
		}

		$rules = $input['rules'];
		$hour  = isset( $rules['digest_hour'] ) && is_scalar( $rules['digest_hour'] )
			? (string) wp_unslash( $rules['digest_hour'] )
			: $settings['rules']['digest_hour'];

		if ( 1 !== preg_match( '/^(?:[0-9]|1[0-9]|2[0-3])$/', $hour ) ) {
			add_settings_error( self::OPTION, 'invalid_digest_hour', __( 'Daily digest hour must be between 0 and 23.', 'od-wordpress-monitor' ) );
			$hour = $settings['rules']['digest_hour'];
		}

		$settings['rules'] = array(
			'ssl_warning'                    => isset( $rules['ssl_warning'] ) && '1' === (string) $rules['ssl_warning'] ? '1' : '0',
			'updates_digest'                 => isset( $rules['updates_digest'] ) && '1' === (string) $rules['updates_digest'] ? '1' : '0',
			'site_health_recommended_digest' => isset( $rules['site_health_recommended_digest'] ) && '1' === (string) $rules['site_health_recommended_digest'] ? '1' : '0',
			'digest_hour'                    => (string) (int) $hour,
		);

		return $settings;
	}

	/**
	 * @param array<string,mixed>                $input Submitted settings.
	 * @param array<string,array<string,string>> $settings Current sanitized settings.
	 * @return array<string,array<string,string>>
	 */
	private function sanitize_chatwork( array $input, array $settings ): array {
		$channel_id = ChatworkNotifier::CHANNEL_ID;
		$submitted  = isset( $input[ $channel_id ] ) && is_array( $input[ $channel_id ] ) ? $input[ $channel_id ] : array();
		$room_input = isset( $submitted['room_id'] ) && is_scalar( $submitted['room_id'] )
			? trim( (string) wp_unslash( $submitted['room_id'] ) )
			: '';

		if ( '' !== $room_input && ( 1 !== preg_match( '/^[1-9][0-9]*$/', $room_input ) || strlen( $room_input ) > 20 ) ) {
			$this->add_invalid_setting_error( $channel_id, __( 'Chatwork room ID must be a positive integer.', 'od-wordpress-monitor' ) );

			return $settings;
		}

		$delete    = isset( $submitted['delete'] ) && '1' === (string) $submitted['delete'];
		$new_token = isset( $submitted['api_token'] ) && is_scalar( $submitted['api_token'] )
			? trim( (string) wp_unslash( $submitted['api_token'] ) )
			: '';
		if ( '' !== $new_token && 1 !== preg_match( '/^[A-Za-z0-9._~-]{1,255}$/', $new_token ) ) {
			$this->add_invalid_setting_error( $channel_id, __( 'Chatwork API token is invalid.', 'od-wordpress-monitor' ) );

			return $settings;
		}

		$settings[ $channel_id ]['room_id'] = $room_input;

		if ( $delete ) {
			$settings[ $channel_id ]['enabled']             = '0';
			$settings[ $channel_id ]['encrypted_api_token'] = '';

			return $settings;
		}

		if ( '' !== $new_token ) {
			$settings[ $channel_id ]['encrypted_api_token'] = $this->encryptor->encrypt( $new_token );
		}

		$settings[ $channel_id ]['enabled'] = isset( $submitted['enabled'] )
			&& '1' === (string) $submitted['enabled']
			&& '' !== $settings[ $channel_id ]['room_id']
			&& '' !== $settings[ $channel_id ]['encrypted_api_token']
			? '1'
			: '0';

		return $settings;
	}

	/**
	 * @return array<string,array<string,string>>
	 */
	private function get(): array {
		$stored = get_option( self::OPTION, $this->defaults() );
		$stored = is_array( $stored ) ? $stored : array();
		$result = $this->defaults();

		foreach ( self::WEBHOOK_CHANNELS as $channel_id ) {
			$channel               = isset( $stored[ $channel_id ] ) && is_array( $stored[ $channel_id ] ) ? $stored[ $channel_id ] : array();
			$result[ $channel_id ] = array(
				'enabled'               => isset( $channel['enabled'] ) && '1' === (string) $channel['enabled'] ? '1' : '0',
				'encrypted_webhook_url' => isset( $channel['encrypted_webhook_url'] ) && is_string( $channel['encrypted_webhook_url'] ) ? $channel['encrypted_webhook_url'] : '',
			);
		}

		$chatwork                               = isset( $stored[ ChatworkNotifier::CHANNEL_ID ] ) && is_array( $stored[ ChatworkNotifier::CHANNEL_ID ] ) ? $stored[ ChatworkNotifier::CHANNEL_ID ] : array();
		$result[ ChatworkNotifier::CHANNEL_ID ] = array(
			'enabled'             => isset( $chatwork['enabled'] ) && '1' === (string) $chatwork['enabled'] ? '1' : '0',
			'room_id'             => isset( $chatwork['room_id'] ) && is_string( $chatwork['room_id'] ) && 1 === preg_match( '/^[1-9][0-9]{0,19}$/', $chatwork['room_id'] ) ? $chatwork['room_id'] : '',
			'encrypted_api_token' => isset( $chatwork['encrypted_api_token'] ) && is_string( $chatwork['encrypted_api_token'] ) ? $chatwork['encrypted_api_token'] : '',
		);
		$rules                                  = isset( $stored['rules'] ) && is_array( $stored['rules'] ) ? $stored['rules'] : array();
		$result['rules']                        = array(
			'ssl_warning'                    => ! isset( $rules['ssl_warning'] ) || '1' === (string) $rules['ssl_warning'] ? '1' : '0',
			'updates_digest'                 => isset( $rules['updates_digest'] ) && '1' === (string) $rules['updates_digest'] ? '1' : '0',
			'site_health_recommended_digest' => isset( $rules['site_health_recommended_digest'] ) && '1' === (string) $rules['site_health_recommended_digest'] ? '1' : '0',
			'digest_hour'                    => isset( $rules['digest_hour'] ) && 1 === preg_match( '/^(?:[0-9]|1[0-9]|2[0-3])$/', (string) $rules['digest_hour'] ) ? (string) (int) $rules['digest_hour'] : '9',
		);

		return $result;
	}

	/**
	 * @return array<string,array<string,string>>
	 */
	private function defaults(): array {
		return array(
			SlackNotifier::CHANNEL_ID    => array(
				'enabled'               => '0',
				'encrypted_webhook_url' => '',
			),
			DiscordNotifier::CHANNEL_ID  => array(
				'enabled'               => '0',
				'encrypted_webhook_url' => '',
			),
			ChatworkNotifier::CHANNEL_ID => array(
				'enabled'             => '0',
				'room_id'             => '',
				'encrypted_api_token' => '',
			),
			'rules'                      => array(
				'ssl_warning'                    => '1',
				'updates_digest'                 => '0',
				'site_health_recommended_digest' => '0',
				'digest_hour'                    => '9',
			),
		);
	}

	private function decrypt( string $encrypted ): string {
		if ( '' === $encrypted ) {
			return '';
		}

		try {
			return $this->encryptor->decrypt( $encrypted );
		} catch ( Throwable $exception ) {
			unset( $exception );

			return '';
		}
	}

	private function add_invalid_setting_error( string $channel_id, string $message ): void {
		add_settings_error( self::OPTION, 'invalid_' . $channel_id . '_setting', $message );
	}
}
