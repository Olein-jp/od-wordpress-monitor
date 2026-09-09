<?php
/**
 * Agent HTTP client tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use Olein\WordPressMonitor\Credential\Credential;
use Olein\WordPressMonitor\Http\AgentClient;
use Olein\WordPressMonitor\Http\HttpClient;
use Olein\WordPressMonitor\Protocol\ResponseValidator;
use Olein\WordPressMonitor\Site\Site;
use WP_Error;

final class AgentClientTest extends \WP_UnitTestCase {
	private AgentClient $client;
	private Site $site;
	private Credential $credential;

	public function set_up(): void {
		parent::set_up();
		$this->client     = new AgentClient( new HttpClient(), new ResponseValidator() );
		$this->site       = new Site( null, wp_generate_uuid4(), 'Example', 'https://example.com', 'https://example.com/wp-json/od-monitor-agent/v1' );
		$this->credential = new Credential( 'agent-user', 'app password' );
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	public function test_successful_ping(): void {
		$this->mock_response( 200, $this->valid_ping() );
		$result = $this->client->ping( $this->site, $this->credential );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
	}

	public function test_successful_updates_request(): void {
		$this->mock_response( 200, $this->valid_updates() );
		$result = $this->client->updates( $this->site, $this->credential );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['summary']['total'] );
	}

	public function test_request_uses_basic_authentication_and_configured_timeout(): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, array $arguments, string $url ) {
				$this->assertSame( 'https://example.com/wp-json/od-monitor-agent/v1/ping', $url );
				$this->assertSame( AgentClient::TIMEOUT, $arguments['timeout'] );
				$this->assertSame( 'Basic ' . base64_encode( 'agent-user:app password' ), $arguments['headers']['Authorization'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( $this->valid_ping() ),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);

		$this->assertIsArray( $this->client->ping( $this->site, $this->credential ) );
	}

	public function test_rejects_non_https_agent_url(): void {
		$site   = new Site( null, wp_generate_uuid4(), 'Example', 'http://example.com', 'http://example.com/wp-json/od-monitor-agent/v1' );
		$result = $this->client->ping( $site, $this->credential );

		$this->assertWPError( $result );
		$this->assertSame( 'HTTPS_REQUIRED', $result->get_error_code() );
	}

	/**
	 * @dataProvider error_status_provider
	 */
	public function test_http_status_mapping( int $status, string $expected ): void {
		$this->mock_response( $status, array() );
		$result = $this->client->ping( $this->site, $this->credential );

		$this->assertWPError( $result );
		$this->assertSame( $expected, $result->get_error_code() );
	}

	/**
	 * @return list<array{int,string}>
	 */
	public function error_status_provider(): array {
		return array(
			array( 401, 'AUTHENTICATION_FAILED' ),
			array( 403, 'PERMISSION_DENIED' ),
			array( 404, 'AGENT_NOT_FOUND' ),
		);
	}

	public function test_timeout(): void {
		add_filter( 'pre_http_request', static fn() => new WP_Error( 'http_request_failed', 'Connection timed out' ) );
		$result = $this->client->ping( $this->site, $this->credential );

		$this->assertSame( 'TIMEOUT', $result->get_error_code() );
	}

	public function test_generic_wp_error(): void {
		add_filter( 'pre_http_request', static fn() => new WP_Error( 'http_request_failed', 'DNS failure' ) );
		$result = $this->client->ping( $this->site, $this->credential );

		$this->assertSame( 'CONNECTION_ERROR', $result->get_error_code() );
	}

	public function test_malformed_json(): void {
		add_filter(
			'pre_http_request',
			static fn() => array(
				'headers'  => array(),
				'body'     => '{broken',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			)
		);
		$result = $this->client->ping( $this->site, $this->credential );

		$this->assertSame( 'INVALID_JSON', $result->get_error_code() );
	}

	public function test_unsupported_schema(): void {
		$body                   = $this->valid_ping();
		$body['schema_version'] = '2.0';
		$this->mock_response( 200, $body );
		$result = $this->client->ping( $this->site, $this->credential );

		$this->assertSame( 'UNSUPPORTED_SCHEMA', $result->get_error_code() );
	}

	/**
	 * Register a short-circuited WordPress HTTP response.
	 *
	 * @param array<string,mixed> $body Response body.
	 */
	private function mock_response( int $status, array $body ): void {
		add_filter(
			'pre_http_request',
			static fn() => array(
				'headers'  => array(),
				'body'     => wp_json_encode( $body ),
				'response' => array(
					'code'    => $status,
					'message' => '',
				),
				'cookies'  => array(),
				'filename' => null,
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function valid_ping(): array {
		return array(
			'schema_version' => '1.0',
			'success'        => true,
			'agent'          => array(
				'slug'    => 'od-monitor-agent',
				'version' => '1.0.0',
			),
			'timestamp'      => '2026-09-08T09:00:00Z',
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function valid_updates(): array {
		return array(
			'schema_version' => '1.0',
			'wordpress'      => array(
				'current_version'  => '7.1',
				'latest_version'   => '7.1.1',
				'update_available' => true,
			),
			'plugins'        => array(),
			'themes'         => array(),
			'summary'        => array(
				'wordpress' => 1,
				'plugins'   => 0,
				'themes'    => 0,
				'total'     => 1,
			),
			'timestamp'      => '2026-09-09T09:00:00Z',
		);
	}
}
