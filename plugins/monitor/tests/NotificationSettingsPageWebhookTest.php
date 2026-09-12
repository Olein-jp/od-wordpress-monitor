<?php
/**
 * Webhook notification administration tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use Olein\WordPressMonitor\Admin\NotificationSettingsPage;
use Olein\WordPressMonitor\Notification\ChatworkNotifier;
use Olein\WordPressMonitor\Notification\DiscordNotifier;
use Olein\WordPressMonitor\Notification\NotificationChannelSettings;
use Olein\WordPressMonitor\Notification\NotificationSecretEncryptor;
use Olein\WordPressMonitor\Notification\NotificationSettings;
use Olein\WordPressMonitor\Notification\NotificationTestService;
use Olein\WordPressMonitor\Notification\NotificationTextFormatter;
use Olein\WordPressMonitor\Notification\SlackNotifier;
use Olein\WordPressMonitor\Notification\WebhookClient;
use Olein\WordPressMonitor\Notification\WebhookUrlValidator;

final class NotificationSettingsPageWebhookTest extends \WP_UnitTestCase {
	private NotificationChannelSettings $channel_settings;
	private NotificationSettingsPage $page;

	public function set_up(): void {
		parent::set_up();
		$_POST = array();
		delete_option( NotificationChannelSettings::OPTION );
		$validator              = new WebhookUrlValidator();
		$this->channel_settings = new NotificationChannelSettings(
			new NotificationSecretEncryptor( str_repeat( 'p', SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) ),
			$validator
		);
		$client                 = new WebhookClient( $validator );
		$formatter              = new NotificationTextFormatter();
		$slack                  = new SlackNotifier( $this->channel_settings, $client, $formatter );
		$discord                = new DiscordNotifier( $this->channel_settings, $client, $formatter );
		$chatwork               = new ChatworkNotifier( $this->channel_settings, $client, $formatter );
		$this->page             = new NotificationSettingsPage(
			new NotificationSettings(),
			$this->channel_settings,
			new NotificationTestService( array( $slack, $discord, $chatwork ) )
		);
	}

	public function tear_down(): void {
		$_POST = array();
		delete_option( NotificationChannelSettings::OPTION );
		parent::tear_down();
	}

	public function test_render_never_exposes_plaintext_or_ciphertext_and_has_separate_test_forms(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$plain  = 'https://hooks.slack.com/services/T000/B000/private-token';
		$token  = 'chatwork-private-token';
		$stored = $this->channel_settings->sanitize(
			array(
				SlackNotifier::CHANNEL_ID    => array(
					'enabled'     => '1',
					'webhook_url' => $plain,
				),
				ChatworkNotifier::CHANNEL_ID => array(
					'enabled'   => '1',
					'room_id'   => '12345',
					'api_token' => $token,
				),
			)
		);
		update_option( NotificationChannelSettings::OPTION, $stored );
		$this->page->register_settings();

		ob_start();
		$this->page->render();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Slack notifications', $output );
		$this->assertStringContainsString( 'Discord notifications', $output );
		$this->assertStringContainsString( 'Chatwork notifications', $output );
		$this->assertStringContainsString( 'value="12345"', $output );
		$this->assertStringContainsString( 'Configured. Leave blank', $output );
		$this->assertStringContainsString( 'value="odm_test_notification"', $output );
		$this->assertSame( 3, substr_count( $output, 'admin-post.php' ) );
		$this->assertStringNotContainsString( $plain, $output );
		$this->assertStringNotContainsString( $stored['slack']['encrypted_webhook_url'], $output );
		$this->assertStringNotContainsString( $token, $output );
		$this->assertStringNotContainsString( $stored['chatwork']['encrypted_api_token'], $output );
	}

	public function test_test_action_requires_manage_options(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_POST['channel'] = SlackNotifier::CHANNEL_ID;

		$this->expectException( \WPDieException::class );
		$this->page->handle_test();
	}

	public function test_test_action_requires_a_channel_specific_nonce(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST['channel'] = ChatworkNotifier::CHANNEL_ID;

		$this->expectException( \WPDieException::class );
		$this->page->handle_test();
	}
}
