<?php
/**
 * Notification settings tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use Olein\WordPressMonitor\Admin\NotificationSettingsPage;
use Olein\WordPressMonitor\Notification\NotificationSettings;

final class NotificationSettingsTest extends \WP_UnitTestCase {
	private NotificationSettings $settings;

	public function set_up(): void {
		parent::set_up();
		delete_option( NotificationSettings::OPTION );
		$this->settings = new NotificationSettings();
	}

	public function test_sanitizes_to_supported_fields_and_valid_email(): void {
		$this->assertSame(
			array(
				'enabled' => '1',
				'email'   => 'alerts@example.com',
			),
			$this->settings->sanitize(
				array(
					'enabled' => '1',
					'email'   => ' alerts@example.com ',
					'unknown' => 'discarded',
				)
			)
		);
	}

	public function test_invalid_values_are_safe(): void {
		$this->assertSame(
			array(
				'enabled' => '0',
				'email'   => '',
			),
			$this->settings->sanitize(
				array(
					'enabled' => 'yes',
					'email'   => 'not-an-email',
				)
			)
		);
	}

	public function test_registers_settings_api_sanitization_and_admin_capability(): void {
		$this->settings->register();
		$registered = get_registered_settings();

		$this->assertArrayHasKey( NotificationSettings::OPTION, $registered );
		$this->assertSame( 'array', $registered[ NotificationSettings::OPTION ]['type'] );
		$this->assertSame(
			'manage_options',
			apply_filters( 'option_page_capability_' . NotificationSettings::GROUP, 'manage_options' )
		);
	}

	public function test_admin_page_uses_settings_api_nonce_and_escapes_values(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		update_option(
			NotificationSettings::OPTION,
			array(
				'enabled' => '1',
				'email'   => 'alerts+monitor@example.com',
			)
		);
		$page = new NotificationSettingsPage( $this->settings );
		$page->register_settings();

		ob_start();
		$page->render();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( esc_url( admin_url( 'options.php' ) ), $output );
		$this->assertStringContainsString( 'alerts+monitor@example.com', $output );
		$this->assertMatchesRegularExpression( '/name=[\'\"]option_page[\'\"] value=[\'\"]' . NotificationSettings::GROUP . '[\'\"]/', $output );
		$this->assertSame( 1, preg_match( '/name=[\'\"]_wpnonce[\'\"] value=[\'\"]([a-z0-9]+)[\'\"]/', $output, $matches ) );
		$this->assertSame( 1, wp_verify_nonce( $matches[1], NotificationSettings::GROUP . '-options' ) );
	}

	public function test_non_administrator_cannot_render_settings_page(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );
		$page = new NotificationSettingsPage( $this->settings );

		$this->expectException( \WPDieException::class );
		$page->render();
	}
}
