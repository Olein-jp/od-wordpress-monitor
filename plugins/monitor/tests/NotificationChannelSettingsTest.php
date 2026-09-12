<?php
/**
 * Encrypted webhook setting tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use Olein\WordPressMonitor\Notification\DiscordNotifier;
use Olein\WordPressMonitor\Notification\ChatworkNotifier;
use Olein\WordPressMonitor\Notification\NotificationChannelSettings;
use Olein\WordPressMonitor\Notification\NotificationSecretEncryptor;
use Olein\WordPressMonitor\Notification\SlackNotifier;
use Olein\WordPressMonitor\Notification\WebhookUrlValidator;

final class NotificationChannelSettingsTest extends \WP_UnitTestCase {
	private NotificationChannelSettings $settings;

	public function set_up(): void {
		parent::set_up();
		delete_option( NotificationChannelSettings::OPTION );
		$this->settings = new NotificationChannelSettings(
			new NotificationSecretEncryptor( str_repeat( 'n', SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) ),
			new WebhookUrlValidator()
		);
	}

	public function tear_down(): void {
		delete_option( NotificationChannelSettings::OPTION );
		parent::tear_down();
	}

	public function test_encrypts_secrets_and_creates_a_non_autoloaded_option(): void {
		$this->settings->register();
		$plain = 'https://hooks.slack.com/services/T000/B000/private-token';
		$value = $this->settings->sanitize(
			array(
				SlackNotifier::CHANNEL_ID => array(
					'enabled'     => '1',
					'webhook_url' => $plain,
				),
			)
		);
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Avoids applying the registered sanitizer twice in this isolated registration test.
			$wpdb->options,
			array( 'option_value' => maybe_serialize( $value ) ),
			array( 'option_name' => NotificationChannelSettings::OPTION )
		);
		wp_cache_delete( NotificationChannelSettings::OPTION, 'options' );

		$stored = get_option( NotificationChannelSettings::OPTION );
		$this->assertNotSame( $plain, $stored['slack']['encrypted_webhook_url'] );
		$this->assertStringNotContainsString( 'private-token', (string) wp_json_encode( $stored ) );
		$this->assertSame( $plain, $this->settings->webhook_url( SlackNotifier::CHANNEL_ID ) );
		$this->assertTrue( $this->settings->enabled( SlackNotifier::CHANNEL_ID ) );

		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", NotificationChannelSettings::OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertContains( $autoload, array( 'no', 'off' ), true );
	}

	public function test_warning_is_enabled_and_digests_are_disabled_by_default(): void {
		$this->settings->register();
		$this->assertTrue( $this->settings->ssl_warning_enabled() );
		$this->assertFalse( $this->settings->updates_digest_enabled() );
		$this->assertFalse( $this->settings->site_health_digest_enabled() );
		$this->assertSame( 9, $this->settings->digest_hour() );
	}

	public function test_digest_rules_and_hour_are_sanitized(): void {
		$value = $this->settings->sanitize(
			array(
				'rules' => array(
					'ssl_warning'                    => '1',
					'updates_digest'                 => '1',
					'site_health_recommended_digest' => '1',
					'digest_hour'                    => '23',
				),
			)
		);
		$this->assertSame( '1', $value['rules']['updates_digest'] );
		$this->assertSame( '23', $value['rules']['digest_hour'] );
		$invalid = $this->settings->sanitize( array( 'rules' => array( 'digest_hour' => '99' ) ) );
		$this->assertSame( '9', $invalid['rules']['digest_hour'] );
	}

	public function test_blank_input_preserves_secret_and_explicit_delete_removes_it(): void {
		$initial = $this->settings->sanitize(
			array(
				DiscordNotifier::CHANNEL_ID => array(
					'enabled'     => '1',
					'webhook_url' => 'https://discord.com/api/webhooks/123456/private-token',
				),
			)
		);
		update_option( NotificationChannelSettings::OPTION, $initial );
		$preserved = $this->settings->sanitize(
			array(
				DiscordNotifier::CHANNEL_ID => array(
					'enabled'     => '1',
					'webhook_url' => '',
				),
			)
		);
		$this->assertSame( $initial['discord']['encrypted_webhook_url'], $preserved['discord']['encrypted_webhook_url'] );

		update_option( NotificationChannelSettings::OPTION, $preserved );
		$deleted = $this->settings->sanitize(
			array(
				DiscordNotifier::CHANNEL_ID => array(
					'enabled' => '1',
					'delete'  => '1',
				),
			)
		);
		$this->assertSame( '0', $deleted['discord']['enabled'] );
		$this->assertSame( '', $deleted['discord']['encrypted_webhook_url'] );
	}

	public function test_invalid_replacement_preserves_existing_secret_and_state(): void {
		$initial = $this->settings->sanitize(
			array(
				SlackNotifier::CHANNEL_ID => array(
					'enabled'     => '1',
					'webhook_url' => 'https://hooks.slack.com/services/T000/B000/private-token',
				),
			)
		);
		update_option( NotificationChannelSettings::OPTION, $initial );

		$this->assertSame(
			$initial,
			$this->settings->sanitize(
				array(
					SlackNotifier::CHANNEL_ID => array( 'webhook_url' => 'https://evil.example/collect' ),
				)
			)
		);
	}

	public function test_tampered_ciphertext_disables_channel_without_exposing_an_error(): void {
		update_option(
			NotificationChannelSettings::OPTION,
			array(
				SlackNotifier::CHANNEL_ID => array(
					'enabled'               => '1',
					'encrypted_webhook_url' => 'not-ciphertext-private-value',
				),
			)
		);

		$this->assertSame( '', $this->settings->webhook_url( SlackNotifier::CHANNEL_ID ) );
		$this->assertFalse( $this->settings->enabled( SlackNotifier::CHANNEL_ID ) );
	}

	public function test_chatwork_token_is_encrypted_and_blank_input_preserves_it(): void {
		$plain   = 'chatwork-private-token-1234567890';
		$initial = $this->settings->sanitize(
			array(
				ChatworkNotifier::CHANNEL_ID => array(
					'enabled'   => '1',
					'room_id'   => '123456789',
					'api_token' => $plain,
				),
			)
		);

		$this->assertSame( '123456789', $initial['chatwork']['room_id'] );
		$this->assertNotSame( $plain, $initial['chatwork']['encrypted_api_token'] );
		$this->assertStringNotContainsString( $plain, (string) wp_json_encode( $initial ) );
		update_option( NotificationChannelSettings::OPTION, $initial );
		$this->assertTrue( $this->settings->enabled( ChatworkNotifier::CHANNEL_ID ) );
		$this->assertSame( $plain, $this->settings->api_token() );

		$preserved = $this->settings->sanitize(
			array(
				ChatworkNotifier::CHANNEL_ID => array(
					'enabled'   => '1',
					'room_id'   => '123456789',
					'api_token' => '',
				),
			)
		);
		$this->assertSame( $initial['chatwork']['encrypted_api_token'], $preserved['chatwork']['encrypted_api_token'] );
	}

	public function test_chatwork_requires_positive_room_id_and_supports_explicit_token_deletion(): void {
		$initial = $this->settings->sanitize(
			array(
				ChatworkNotifier::CHANNEL_ID => array(
					'enabled'   => '1',
					'room_id'   => '42',
					'api_token' => 'valid-token',
				),
			)
		);
		update_option( NotificationChannelSettings::OPTION, $initial );

		$this->assertSame(
			$initial,
			$this->settings->sanitize(
				array(
					ChatworkNotifier::CHANNEL_ID => array(
						'enabled' => '1',
						'room_id' => '0',
					),
				)
			)
		);

		$deleted = $this->settings->sanitize(
			array(
				ChatworkNotifier::CHANNEL_ID => array(
					'enabled' => '1',
					'room_id' => '42',
					'delete'  => '1',
				),
			)
		);
		$this->assertSame( '0', $deleted['chatwork']['enabled'] );
		$this->assertSame( '', $deleted['chatwork']['encrypted_api_token'] );
	}

	public function test_chatwork_rejects_a_token_that_is_unsafe_for_an_http_header(): void {
		$settings = $this->settings->sanitize(
			array(
				ChatworkNotifier::CHANNEL_ID => array(
					'enabled'   => '1',
					'room_id'   => '42',
					'api_token' => "token\nInjected: value",
				),
			)
		);

		$this->assertSame( '0', $settings['chatwork']['enabled'] );
		$this->assertSame( '', $settings['chatwork']['encrypted_api_token'] );
	}
}
