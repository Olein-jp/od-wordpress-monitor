<?php
/**
 * Chatwork notification delivery tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use DateTimeImmutable;
use Olein\WordPressMonitor\Notification\ChatworkNotifier;
use Olein\WordPressMonitor\Notification\NotificationChannelResult;
use Olein\WordPressMonitor\Notification\NotificationChannelSettings;
use Olein\WordPressMonitor\Notification\NotificationMessage;
use Olein\WordPressMonitor\Notification\NotificationRule;
use Olein\WordPressMonitor\Notification\NotificationSecretEncryptor;
use Olein\WordPressMonitor\Notification\NotificationTextFormatter;
use Olein\WordPressMonitor\Notification\WebhookClient;
use Olein\WordPressMonitor\Notification\WebhookUrlValidator;
use WP_Error;

final class ChatworkNotifierTest extends \WP_UnitTestCase {
	private const TOKEN = 'chatwork-private-token-1234567890';

	private NotificationChannelSettings $settings;

	public function set_up(): void {
		parent::set_up();
		delete_option( NotificationChannelSettings::OPTION );
		$this->settings = new NotificationChannelSettings(
			new NotificationSecretEncryptor( str_repeat( 'c', SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) ),
			new WebhookUrlValidator()
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		delete_option( NotificationChannelSettings::OPTION );
		parent::tear_down();
	}

	public function test_posts_expected_header_and_form_body_to_fixed_endpoint(): void {
		$this->save( '123456789', self::TOKEN );
		$request = array();
		add_filter(
			'pre_http_request',
			function ( $preempt, array $args, string $url ) use ( &$request ) {
				unset( $preempt );
				$request = array(
					'args' => $args,
					'url'  => $url,
				);

				return $this->response( 200 );
			},
			10,
			3
		);

		$result = $this->notifier()->send( $this->message( 'Example [To:123] [toall]' ) );

		$this->assertSame( NotificationChannelResult::SENT, $result->status() );
		$this->assertSame( 'https://api.chatwork.com/v2/rooms/123456789/messages', $request['url'] );
		$this->assertSame( self::TOKEN, $request['args']['headers']['x-chatworktoken'] );
		$this->assertSame( 10, $request['args']['timeout'] );
		$this->assertSame( 0, $request['args']['redirection'] );
		$this->assertSame( 4096, $request['args']['limit_response_size'] );
		$this->assertTrue( $request['args']['reject_unsafe_urls'] );
		$this->assertArrayHasKey( 'body', $request['args']['body'] );
		$this->assertStringNotContainsString( '[To:123]', $request['args']['body']['body'] );
		$this->assertStringNotContainsString( '[toall]', $request['args']['body']['body'] );
		$this->assertStringNotContainsString( self::TOKEN, $request['url'] );
		$this->assertStringNotContainsString( self::TOKEN, $request['args']['body']['body'] );
	}

	public function test_limits_message_to_chatwork_maximum_length(): void {
		$this->save( '42', self::TOKEN );
		$body = '';
		add_filter(
			'pre_http_request',
			function ( $preempt, array $args ) use ( &$body ) {
				unset( $preempt );
				$body = $args['body']['body'];

				return $this->response( 200 );
			},
			10,
			2
		);

		$this->notifier()->send( $this->message( str_repeat( 'a', 70000 ) ) );

		$this->assertSame( 65535, mb_strlen( $body ) );
	}

	public function test_invalid_or_missing_settings_do_not_make_a_request(): void {
		$calls = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$calls ) {
				++$calls;

				return new WP_Error( 'unexpected_request' );
			}
		);

		$result = $this->notifier()->send( $this->message( 'Example' ) );

		$this->assertSame( NotificationChannelResult::FAILED, $result->status() );
		$this->assertSame( 'CHATWORK_SETTINGS_INVALID', $result->error_code() );
		$this->assertSame( 0, $calls );
	}

	/**
	 * @dataProvider failure_provider
	 */
	public function test_normalizes_http_and_transport_failures( int $status, ?string $transport_code, string $expected ): void {
		$this->save( '42', self::TOKEN );
		add_filter(
			'pre_http_request',
			fn() => null === $transport_code ? $this->response( $status ) : new WP_Error( $transport_code, 'private token timed out' )
		);

		$result = $this->notifier()->send( $this->message( 'Example' ) );

		$this->assertSame( NotificationChannelResult::FAILED, $result->status() );
		$this->assertSame( $expected, $result->error_code() );
		$this->assertStringNotContainsString( 'private', (string) $result->error_code() );
	}

	/**
	 * @return array<string,array{int,?string,string}>
	 */
	public function failure_provider(): array {
		return array(
			'bad request'  => array( 400, null, 'HTTP_400' ),
			'auth'         => array( 401, null, 'HTTP_401' ),
			'permission'   => array( 403, null, 'HTTP_403' ),
			'rate limit'   => array( 429, null, 'HTTP_429' ),
			'server error' => array( 503, null, 'HTTP_503' ),
			'timeout'      => array( 0, 'http_request_failed', 'TIMEOUT' ),
		);
	}

	private function notifier(): ChatworkNotifier {
		return new ChatworkNotifier( $this->settings, new WebhookClient( new WebhookUrlValidator() ), new NotificationTextFormatter() );
	}

	private function save( string $room_id, string $api_token ): void {
		update_option(
			NotificationChannelSettings::OPTION,
			$this->settings->sanitize(
				array(
					ChatworkNotifier::CHANNEL_ID => array(
						'enabled'   => '1',
						'room_id'   => $room_id,
						'api_token' => $api_token,
					),
				)
			)
		);
	}

	private function message( string $site_name ): NotificationMessage {
		return new NotificationMessage(
			NotificationRule::OUTAGE,
			$site_name,
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
	private function response( int $status ): array {
		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
