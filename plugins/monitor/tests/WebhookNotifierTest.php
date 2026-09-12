<?php
/**
 * Slack and Discord webhook delivery tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use DateTimeImmutable;
use Olein\WordPressMonitor\Notification\DiscordNotifier;
use Olein\WordPressMonitor\Notification\NotificationChannelResult;
use Olein\WordPressMonitor\Notification\NotificationChannelSettings;
use Olein\WordPressMonitor\Notification\NotificationMessage;
use Olein\WordPressMonitor\Notification\NotificationRule;
use Olein\WordPressMonitor\Notification\NotificationSecretEncryptor;
use Olein\WordPressMonitor\Notification\NotificationTextFormatter;
use Olein\WordPressMonitor\Notification\SlackNotifier;
use Olein\WordPressMonitor\Notification\WebhookClient;
use Olein\WordPressMonitor\Notification\WebhookUrlValidator;
use WP_Error;

final class WebhookNotifierTest extends \WP_UnitTestCase {
	private NotificationChannelSettings $settings;

	public function set_up(): void {
		parent::set_up();
		delete_option( NotificationChannelSettings::OPTION );
		$this->settings = new NotificationChannelSettings(
			new NotificationSecretEncryptor( str_repeat( 'w', SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) ),
			new WebhookUrlValidator()
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		delete_option( NotificationChannelSettings::OPTION );
		parent::tear_down();
	}

	public function test_slack_uses_expected_endpoint_payload_and_safe_request_arguments(): void {
		$url = 'https://hooks.slack.com/services/T000/B000/private-token';
		$this->save( SlackNotifier::CHANNEL_ID, $url );
		$request = array();
		add_filter(
			'pre_http_request',
			function ( $preempt, array $args, string $requested_url ) use ( &$request ) {
				unset( $preempt );
				$request = array(
					'args' => $args,
					'url'  => $requested_url,
				);

				return $this->response( 200, 'ok' );
			},
			10,
			3
		);

		$result  = $this->slack()->send( $this->message() );
		$payload = json_decode( $request['args']['body'], true );

		$this->assertSame( NotificationChannelResult::SENT, $result->status() );
		$this->assertSame( $url, $request['url'] );
		$this->assertSame( 10, $request['args']['timeout'] );
		$this->assertSame( 0, $request['args']['redirection'] );
		$this->assertTrue( $request['args']['reject_unsafe_urls'] );
		$this->assertSame( 'application/json; charset=utf-8', $request['args']['headers']['Content-Type'] );
		$this->assertStringContainsString( '[OD Monitor] Outage', $payload['text'] );
		$this->assertStringContainsString( 'https://example.com', $payload['text'] );
	}

	public function test_discord_uses_wait_and_disables_mentions(): void {
		$url = 'https://discord.com/api/webhooks/123456/private-token';
		$this->save( DiscordNotifier::CHANNEL_ID, $url );
		$request = array();
		add_filter(
			'pre_http_request',
			function ( $preempt, array $args, string $requested_url ) use ( &$request ) {
				unset( $preempt );
				$request = array(
					'args' => $args,
					'url'  => $requested_url,
				);

				return $this->response( 200, '{}' );
			},
			10,
			3
		);

		$result  = $this->discord()->send( $this->message() );
		$payload = json_decode( $request['args']['body'], true );

		$this->assertSame( NotificationChannelResult::SENT, $result->status() );
		$this->assertSame( $url . '?wait=true', $request['url'] );
		$this->assertSame( array( 'parse' => array() ), $payload['allowed_mentions'] );
		$this->assertLessThanOrEqual( 2000, mb_strlen( $payload['content'] ) );
	}

	/**
	 * @dataProvider failure_provider
	 */
	public function test_normalizes_http_and_transport_failures( int $status, ?string $transport_code, string $expected ): void {
		$this->save( SlackNotifier::CHANNEL_ID, 'https://hooks.slack.com/services/T000/B000/private-token' );
		add_filter(
			'pre_http_request',
			fn() => null === $transport_code ? $this->response( $status, 'sensitive response' ) : new WP_Error( $transport_code, 'private-token sensitive failure' )
		);

		$result = $this->slack()->send( $this->message() );

		$this->assertSame( NotificationChannelResult::FAILED, $result->status() );
		$this->assertSame( $expected, $result->error_code() );
		$this->assertStringNotContainsString( 'private', (string) $result->error_code() );
	}

	/**
	 * @dataProvider retry_after_provider
	 */
	public function test_accepts_only_bounded_retry_after( int $status, string $header, ?int $expected ): void {
		$this->save( SlackNotifier::CHANNEL_ID, 'https://hooks.slack.com/services/T000/B000/private-token' );
		add_filter(
			'pre_http_request',
			function () use ( $status, $header ) {
				$response            = $this->response( $status, 'private response' );
				$response['headers'] = array( 'retry-after' => $header );
				return $response;
			}
		);

		$result = $this->slack()->send( $this->message() );
		$this->assertSame( $expected, $result->retry_after_seconds() );
	}

	public function test_tls_failure_is_not_classified_as_a_retryable_connection_error(): void {
		$this->save( SlackNotifier::CHANNEL_ID, 'https://hooks.slack.com/services/T000/B000/private-token' );
		add_filter( 'pre_http_request', static fn() => new WP_Error( 'http_request_failed', 'SSL certificate verify failed: private detail' ) );
		$this->assertSame( 'TLS_ERROR', $this->slack()->send( $this->message() )->error_code() );
	}

	/**
	 * @return array<string,array{int,string,?int}>
	 */
	public function retry_after_provider(): array {
		return array(
			'bounded rate limit' => array( 429, '120', 120 ),
			'too long'           => array( 429, '3600', null ),
			'bad header'         => array( 503, 'invalid', null ),
			'permanent failure'  => array( 403, '120', null ),
		);
	}

	/**
	 * @return array<string,array{int,?string,string}>
	 */
	public function failure_provider(): array {
		return array(
			'client error' => array( 403, null, 'HTTP_403' ),
			'rate limit'   => array( 429, null, 'HTTP_429' ),
			'server error' => array( 503, null, 'HTTP_503' ),
			'redirect'     => array( 302, null, 'HTTP_302' ),
			'timeout'      => array( 0, 'http_request_timeout', 'TIMEOUT' ),
			'connection'   => array( 0, 'http_request_failed', 'CONNECTION_ERROR' ),
		);
	}

	private function slack(): SlackNotifier {
		return new SlackNotifier( $this->settings, new WebhookClient( new WebhookUrlValidator() ), new NotificationTextFormatter() );
	}

	private function discord(): DiscordNotifier {
		return new DiscordNotifier( $this->settings, new WebhookClient( new WebhookUrlValidator() ), new NotificationTextFormatter() );
	}

	private function save( string $channel_id, string $url ): void {
		update_option(
			NotificationChannelSettings::OPTION,
			$this->settings->sanitize(
				array(
					$channel_id => array(
						'enabled'     => '1',
						'webhook_url' => $url,
					),
				)
			)
		);
	}

	private function message(): NotificationMessage {
		return new NotificationMessage(
			NotificationRule::OUTAGE,
			'Example Site @everyone',
			'https://example.com',
			'SITE_DOWN',
			'healthy',
			'critical',
			new DateTimeImmutable( '2026-09-12T00:00:00Z' ),
			'CONNECTION_ERROR',
			'Request failed.'
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function response( int $status, string $body ): array {
		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
