<?php
/**
 * HTTP uptime monitor tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use InvalidArgumentException;
use Olein\WordPressMonitor\Http\HttpClient;
use Olein\WordPressMonitor\Monitor\CheckResult;
use Olein\WordPressMonitor\Monitor\Monitoring\HttpMonitor;
use Olein\WordPressMonitor\Site\Site;
use WP_Error;

final class HttpMonitorTest extends \WP_UnitTestCase {
	private Site $site;

	public function set_up(): void {
		parent::set_up();
		$this->site = new Site( 12, wp_generate_uuid4(), 'Example', 'https://example.com/?token=not-persisted', 'https://example.com/wp-json/od-monitor-agent/v1' );
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	public function test_successful_response_is_healthy_and_minimal(): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, array $arguments, string $url ) {
				$this->assertSame( $this->site->site_url(), $url );
				$this->assertSame( HttpMonitor::TIMEOUT, $arguments['timeout'] );
				$this->assertSame( 0, $arguments['redirection'] );
				$this->assertSame( 1, $arguments['limit_response_size'] );
				$this->assertTrue( $arguments['reject_unsafe_urls'] );

				return $this->response( 200, array(), 'response body must not be retained' );
			},
			10,
			3
		);

		$times   = array( 100.0, 100.125 );
		$monitor = new HttpMonitor(
			new HttpClient(),
			static function () use ( &$times ): float {
				return array_shift( $times );
			}
		);
		$result  = $monitor->check( $this->site );

		$this->assertSame( CheckResult::STATUS_HEALTHY, $result->status() );
		$this->assertNull( $result->error_code() );
		$this->assertSame( 125, $result->duration_ms() );
		$this->assertSame( 200, $result->data()['http_status'] );
		$this->assertSame( 'https://example.com/', $result->data()['final_url'] );
		$this->assertSame( 0, $result->data()['redirect_count'] );
		$this->assertStringNotContainsString( 'response body', wp_json_encode( $result->data() ) );
		$this->assertStringNotContainsString( 'token=', wp_json_encode( $result->data() ) );
	}

	/**
	 * @dataProvider non_success_status_provider
	 */
	public function test_non_success_status_is_critical( int $status_code ): void {
		add_filter( 'pre_http_request', fn() => $this->response( $status_code ) );
		$result = ( new HttpMonitor( new HttpClient() ) )->check( $this->site );

		$this->assertSame( CheckResult::STATUS_CRITICAL, $result->status() );
		$this->assertSame( 'HTTP_STATUS', $result->error_code() );
		$this->assertSame( $status_code, $result->data()['http_status'] );
	}

	/**
	 * @return list<array{int}>
	 */
	public function non_success_status_provider(): array {
		return array(
			array( 404 ),
			array( 503 ),
		);
	}

	/**
	 * @dataProvider transport_error_provider
	 */
	public function test_transport_errors_are_classified( string $message, string $expected_code ): void {
		add_filter( 'pre_http_request', static fn() => new WP_Error( 'http_request_failed', $message ) );
		$result = ( new HttpMonitor( new HttpClient() ) )->check( $this->site );

		$this->assertSame( CheckResult::STATUS_CRITICAL, $result->status() );
		$this->assertSame( $expected_code, $result->error_code() );
		$this->assertStringNotContainsString( $message, $result->message() );
	}

	/**
	 * @return list<array{string,string}>
	 */
	public function transport_error_provider(): array {
		return array(
			array( 'Connection timed out with secret detail', 'TIMEOUT' ),
			array( 'DNS failure with secret detail', 'CONNECTION_ERROR' ),
		);
	}

	public function test_safe_redirect_is_followed_and_reported(): void {
		$calls = 0;
		add_filter(
			'pre_http_request',
			function () use ( &$calls ) {
				++$calls;

				return 1 === $calls
					? $this->response( 302, array( 'location' => 'https://example.org/final?secret=hidden' ) )
					: $this->response( 204 );
			}
		);

		$result = ( new HttpMonitor( new HttpClient() ) )->check( $this->site );

		$this->assertSame( CheckResult::STATUS_HEALTHY, $result->status() );
		$this->assertSame( 1, $result->data()['redirect_count'] );
		$this->assertSame( 'https://example.org/final', $result->data()['final_url'] );
		$this->assertSame( 2, $calls );
	}

	public function test_unsafe_redirect_is_rejected_without_following(): void {
		$calls = 0;
		add_filter(
			'pre_http_request',
			function () use ( &$calls ) {
				++$calls;
				return $this->response( 302, array( 'location' => 'http://127.0.0.1/private' ) );
			}
		);

		$result = ( new HttpMonitor( new HttpClient() ) )->check( $this->site );

		$this->assertSame( 'UNSAFE_REDIRECT', $result->error_code() );
		$this->assertSame( 1, $calls );
	}

	public function test_redirect_limit_is_enforced(): void {
		$calls = 0;
		add_filter(
			'pre_http_request',
			function () use ( &$calls ) {
				++$calls;
				return $this->response( 302, array( 'location' => '/redirect-' . $calls ) );
			}
		);

		$result = ( new HttpMonitor( new HttpClient() ) )->check( $this->site );

		$this->assertSame( 'REDIRECT_LIMIT', $result->error_code() );
		$this->assertSame( HttpMonitor::MAX_REDIRECTS, $result->data()['redirect_count'] );
		$this->assertSame( HttpMonitor::MAX_REDIRECTS + 1, $calls );
	}

	/**
	 * @dataProvider invalid_url_provider
	 */
	public function test_invalid_url_is_rejected_without_request( string $url ): void {
		add_filter(
			'pre_http_request',
			static function () {
				throw new \RuntimeException( 'Unsafe URL must not be requested.' );
			}
		);
		$site   = new Site( 12, wp_generate_uuid4(), 'Invalid', $url, 'https://example.com/wp-json/od-monitor-agent/v1' );
		$result = ( new HttpMonitor( new HttpClient() ) )->check( $site );

		$this->assertSame( 'INVALID_URL', $result->error_code() );
		$this->assertArrayNotHasKey( 'final_url', $result->data() );
	}

	/**
	 * @return list<array{string}>
	 */
	public function invalid_url_provider(): array {
		return array(
			array( 'not-a-url' ),
			array( 'ftp://example.com/file' ),
			array( 'http://127.0.0.1/admin' ),
		);
	}

	public function test_unsaved_site_is_rejected(): void {
		$site = new Site( null, wp_generate_uuid4(), 'Unsaved', 'https://example.com', 'https://example.com/wp-json/od-monitor-agent/v1' );

		$this->expectException( InvalidArgumentException::class );
		( new HttpMonitor( new HttpClient() ) )->check( $site );
	}

	/**
	 * Build a WordPress HTTP API response.
	 *
	 * @param array<string,string> $headers Response headers.
	 * @return array<string,mixed>
	 */
	private function response( int $status, array $headers = array(), string $body = '' ): array {
		return array(
			'headers'  => $headers,
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
