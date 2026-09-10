<?php
/**
 * Global notification settings.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Notification;

final class NotificationSettings {
	public const OPTION = 'odm_notification_settings';
	public const GROUP  = 'odm_notifications';

	/**
	 * Register the global notification option with WordPress.
	 */
	public function register(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'default'           => $this->defaults(),
				'sanitize_callback' => array( $this, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
		add_filter( 'option_page_capability_' . self::GROUP, array( $this, 'capability' ) );
	}

	public function enabled(): bool {
		$settings = $this->get();

		return '1' === $settings['enabled'];
	}

	public function email(): string {
		$settings = $this->get();

		return is_email( $settings['email'] ) ? $settings['email'] : '';
	}

	/**
	 * Sanitize Settings API input to the only supported fields.
	 *
	 * @param mixed $input Submitted option value.
	 * @return array{enabled:string,email:string}
	 */
	public function sanitize( $input ): array {
		$input   = is_array( $input ) ? $input : array();
		$enabled = isset( $input['enabled'] ) && '1' === (string) $input['enabled'] ? '1' : '0';
		$email   = isset( $input['email'] ) && is_scalar( $input['email'] )
			? sanitize_email( (string) wp_unslash( $input['email'] ) )
			: '';

		if ( '' !== $email && ! is_email( $email ) ) {
			$email = '';
		}

		return array(
			'enabled' => $enabled,
			'email'   => $email,
		);
	}

	/**
	 * Require the same capability for rendering and saving the settings page.
	 */
	public function capability(): string {
		return 'manage_options';
	}

	/**
	 * @return array{enabled:string,email:string}
	 */
	private function get(): array {
		$settings = get_option( self::OPTION, $this->defaults() );
		$settings = is_array( $settings ) ? $settings : array();

		return array(
			'enabled' => isset( $settings['enabled'] ) && '1' === (string) $settings['enabled'] ? '1' : '0',
			'email'   => isset( $settings['email'] ) && is_string( $settings['email'] ) ? $settings['email'] : '',
		);
	}

	/**
	 * @return array{enabled:string,email:string}
	 */
	private function defaults(): array {
		return array(
			'enabled' => '0',
			'email'   => '',
		);
	}
}
