<?php
/**
 * Safe HTTP redirect tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use Olein\WordPressMonitor\Http\HttpClient;
use Olein\WordPressMonitor\Http\UrlValidator;

final class HttpClientSecurityTest extends \WP_UnitTestCase {
	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	public function test_redirect_to_private_dns_result_is_rejected_before_second_request(): void {
		$calls     = 0;
		$validator = new UrlValidator(
			static fn( string $host ): array => 'private.example.com' === $host ? array( '10.0.0.7' ) : array( '93.184.216.34' )
		);
		add_filter(
			'pre_http_request',
			function () use ( &$calls ): array {
				++$calls;
				return $this->response( 302, array( 'location' => 'https://private.example.com/admin' ) );
			}
		);

		$result = ( new HttpClient( $validator ) )->get( 'https://public.example.com', array( 'redirection' => 3 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'UNSAFE_REDIRECT', $result->get_error_code() );
		$this->assertSame( 1, $calls );
		$this->assertStringNotContainsString( '10.0.0.7', $result->get_error_message() );
	}

	public function test_dns_change_is_revalidated_before_following_redirect(): void {
		$lookups   = 0;
		$calls     = 0;
		$validator = new UrlValidator(
			static function () use ( &$lookups ): array {
				++$lookups;
				return 1 === $lookups ? array( '93.184.216.34' ) : array( '127.0.0.1' );
			}
		);
		add_filter(
			'pre_http_request',
			function () use ( &$calls ): array {
				++$calls;
				return $this->response( 302, array( 'location' => '/next' ) );
			}
		);

		$result = ( new HttpClient( $validator ) )->get( 'https://rebind.example.com', array( 'redirection' => 3 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'UNSAFE_REDIRECT', $result->get_error_code() );
		$this->assertSame( 1, $calls );
		$this->assertSame( 2, $lookups );
	}

	public function test_redirect_limit_is_capped_at_three(): void {
		$calls  = 0;
		$client = $this->client();
		add_filter(
			'pre_http_request',
			function () use ( &$calls ): array {
				++$calls;
				return $this->response( 302, array( 'location' => '/hop-' . $calls ) );
			}
		);

		$result = $client->get( 'https://example.com', array( 'redirection' => 99 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'REDIRECT_LIMIT', $result->get_error_code() );
		$this->assertSame( 4, $calls );
	}

	public function test_authorization_is_not_forwarded_to_another_origin(): void {
		$calls = 0;
		add_filter(
			'pre_http_request',
			function () use ( &$calls ): array {
				++$calls;
				return $this->response( 302, array( 'location' => 'https://other.example.com/collect' ) );
			}
		);

		$result = $this->client()->get(
			'https://example.com/agent',
			array(
				'redirection' => 3,
				'headers'     => array( 'Authorization' => 'Basic must-not-leak' ),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'UNSAFE_REDIRECT', $result->get_error_code() );
		$this->assertSame( 1, $calls );
		$this->assertStringNotContainsString( 'must-not-leak', $result->get_error_message() );
	}

	private function client(): HttpClient {
		return new HttpClient( new UrlValidator( static fn(): array => array( '93.184.216.34' ) ) );
	}

	/**
	 * @param array<string,string> $headers Response headers.
	 * @return array<string,mixed>
	 */
	private function response( int $status, array $headers ): array {
		return array(
			'headers'  => $headers,
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
