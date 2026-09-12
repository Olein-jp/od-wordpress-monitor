<?php
/**
 * Webhook URL allow-list tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use Olein\WordPressMonitor\Notification\DiscordNotifier;
use Olein\WordPressMonitor\Notification\SlackNotifier;
use Olein\WordPressMonitor\Notification\WebhookUrlValidator;

final class WebhookUrlValidatorTest extends \WP_UnitTestCase {
	private WebhookUrlValidator $validator;

	public function set_up(): void {
		parent::set_up();
		$this->validator = new WebhookUrlValidator();
	}

	public function test_accepts_expected_slack_and_discord_urls(): void {
		$this->assertSame(
			'https://hooks.slack.com/services/T000/B000/token_value',
			$this->validator->validate( SlackNotifier::CHANNEL_ID, 'https://hooks.slack.com/services/T000/B000/token_value' )
		);
		$this->assertSame(
			'https://discord.com/api/webhooks/123456/token.value-value',
			$this->validator->validate( DiscordNotifier::CHANNEL_ID, 'https://discord.com/api/webhooks/123456/token.value-value' )
		);
	}

	/**
	 * @dataProvider invalid_url_provider
	 */
	public function test_rejects_unexpected_scheme_host_port_path_and_url_components( string $channel_id, string $url ): void {
		$result = $this->validator->validate( $channel_id, $url );

		$this->assertWPError( $result );
		$this->assertSame( 'INVALID_WEBHOOK_URL', $result->get_error_code() );
	}

	/**
	 * @return array<string,array{string,string}>
	 */
	public function invalid_url_provider(): array {
		return array(
			'http'             => array( SlackNotifier::CHANNEL_ID, 'http://hooks.slack.com/services/T/B/token' ),
			'lookalike host'   => array( SlackNotifier::CHANNEL_ID, 'https://hooks.slack.com.example.com/services/T/B/token' ),
			'custom port'      => array( SlackNotifier::CHANNEL_ID, 'https://hooks.slack.com:8443/services/T/B/token' ),
			'userinfo'         => array( SlackNotifier::CHANNEL_ID, 'https://user@hooks.slack.com/services/T/B/token' ),
			'query'            => array( SlackNotifier::CHANNEL_ID, 'https://hooks.slack.com/services/T/B/token?copy=1' ),
			'wrong path'       => array( SlackNotifier::CHANNEL_ID, 'https://hooks.slack.com/api/chat.postMessage' ),
			'discord canary'   => array( DiscordNotifier::CHANNEL_ID, 'https://discordapp.com/api/webhooks/123/token' ),
			'discord thread'   => array( DiscordNotifier::CHANNEL_ID, 'https://discord.com/api/webhooks/123/token?thread_id=4' ),
			'discord no token' => array( DiscordNotifier::CHANNEL_ID, 'https://discord.com/api/webhooks/123' ),
		);
	}
}
