<?php
/**
 * Agent availability monitor tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use Closure;
use InvalidArgumentException;
use Olein\WordPressMonitor\Activation\DatabaseMigrator;
use Olein\WordPressMonitor\Credential\Credential;
use Olein\WordPressMonitor\Credential\CredentialEncryptor;
use Olein\WordPressMonitor\Credential\CredentialRepository;
use Olein\WordPressMonitor\Credential\CredentialService;
use Olein\WordPressMonitor\Http\AgentClient;
use Olein\WordPressMonitor\Http\HttpClient;
use Olein\WordPressMonitor\Http\UrlValidator;
use Olein\WordPressMonitor\Monitor\CheckResult;
use Olein\WordPressMonitor\Monitor\Monitoring\AgentPingMonitor;
use Olein\WordPressMonitor\Protocol\ResponseValidator;
use Olein\WordPressMonitor\Site\Site;
use Olein\WordPressMonitor\Site\SiteRepository;
use WP_Error;

final class AgentPingMonitorTest extends \WP_UnitTestCase {
	private Site $site;
	private CredentialService $credentials;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;

		( new DatabaseMigrator( $wpdb ) )->migrate();
		$wpdb->query( "DELETE FROM {$wpdb->prefix}odm_credentials" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}odm_sites" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$sites   = new SiteRepository( $wpdb );
		$site_id = $sites->create(
			new Site( null, wp_generate_uuid4(), 'Example', 'https://example.com', 'https://example.com/wp-json/od-monitor-agent/v1' )
		);

		$this->assertIsInt( $site_id );
		$this->site        = $sites->find( $site_id );
		$this->credentials = new CredentialService(
			new CredentialRepository( $wpdb ),
			new CredentialEncryptor( str_repeat( 'k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) )
		);
		$this->assertIsInt( $this->credentials->store( $site_id, new Credential( 'agent-user', 'app-password-secret' ) ) );
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	public function test_valid_credential_returns_healthy_result_with_latency(): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, array $arguments, string $url ) {
				$this->assertSame( 'https://example.com/wp-json/od-monitor-agent/v1/ping', $url );
				$this->assertSame( 'Basic ' . base64_encode( 'agent-user:app-password-secret' ), $arguments['headers']['Authorization'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

				return $this->response( 200, $this->valid_ping() );
			},
			10,
			3
		);

		$times   = array( 100.0, 100.075 );
		$result  = $this->monitor(
			static function () use ( &$times ): float {
				return array_shift( $times );
			}
		)->check( $this->site );
		$content = $result->message() . wp_json_encode( $result->data() );

		$this->assertSame( CheckResult::STATUS_HEALTHY, $result->status() );
		$this->assertNull( $result->error_code() );
		$this->assertSame( 75, $result->duration_ms() );
		$this->assertSame( 'ping', $result->data()['endpoint'] );
		$this->assertSame( '1.0.0', $result->data()['agent_version'] );
		$this->assertStringNotContainsString( 'agent-user', $content );
		$this->assertStringNotContainsString( 'app-password-secret', $content );
		$this->assertStringNotContainsString( 'Authorization', $content );
	}

	/**
	 * @dataProvider http_error_provider
	 */
	public function test_authentication_and_permission_errors_remain_distinct( int $status, string $expected_code ): void {
		add_filter( 'pre_http_request', fn() => $this->response( $status, array() ) );
		$result = $this->monitor()->check( $this->site );

		$this->assertSame( CheckResult::STATUS_CRITICAL, $result->status() );
		$this->assertSame( $expected_code, $result->error_code() );
	}

	/**
	 * @return list<array{int,string}>
	 */
	public function http_error_provider(): array {
		return array(
			array( 401, 'AUTHENTICATION_FAILED' ),
			array( 403, 'PERMISSION_DENIED' ),
		);
	}

	/**
	 * @dataProvider transport_error_provider
	 */
	public function test_timeout_and_connection_errors_are_normalized_without_raw_details( string $raw_message, string $expected_code ): void {
		add_filter( 'pre_http_request', static fn() => new WP_Error( 'http_request_failed', $raw_message ) );
		$result  = $this->monitor()->check( $this->site );
		$content = $result->message() . wp_json_encode( $result->data() );

		$this->assertSame( $expected_code, $result->error_code() );
		$this->assertStringNotContainsString( $raw_message, $content );
		$this->assertStringNotContainsString( 'app-password-secret', $content );
	}

	/**
	 * @return list<array{string,string}>
	 */
	public function transport_error_provider(): array {
		return array(
			array( 'Connection timed out; Authorization: Basic secret', 'TIMEOUT' ),
			array( 'DNS failure for agent-user:app-password-secret', 'CONNECTION_ERROR' ),
		);
	}

	/**
	 * @dataProvider invalid_response_provider
	 *
	 * @param mixed $body Response body.
	 */
	public function test_invalid_agent_responses_are_classified( $body, string $expected_code ): void {
		add_filter(
			'pre_http_request',
			fn() => is_string( $body )
				? $this->raw_response( 200, $body )
				: $this->response( 200, $body )
		);
		$result = $this->monitor()->check( $this->site );

		$this->assertSame( CheckResult::STATUS_CRITICAL, $result->status() );
		$this->assertSame( $expected_code, $result->error_code() );
	}

	public function test_untrusted_agent_version_cannot_copy_a_credential_into_metadata(): void {
		$ping                     = $this->valid_ping();
		$ping['agent']['version'] = 'app-password-secret';
		add_filter( 'pre_http_request', fn() => $this->response( 200, $ping ) );

		$result  = $this->monitor()->check( $this->site );
		$content = $result->message() . wp_json_encode( $result->data() );

		$this->assertSame( 'INVALID_RESPONSE', $result->error_code() );
		$this->assertStringNotContainsString( 'app-password-secret', $content );
	}

	/**
	 * @return list<array{mixed,string}>
	 */
	public function invalid_response_provider(): array {
		return array(
			array( '{broken', 'INVALID_JSON' ),
			array( array( 'schema_version' => '1.0' ), 'INVALID_RESPONSE' ),
			array(
				array(
					'schema_version' => '2.0',
					'success'        => true,
					'agent'          => array(
						'slug'    => 'od-monitor-agent',
						'version' => '1.0.0',
					),
					'timestamp'      => '2026-09-09T09:00:00Z',
				),
				'UNSUPPORTED_SCHEMA',
			),
		);
	}

	public function test_missing_credential_is_reported_without_request(): void {
		global $wpdb;

		$wpdb->query( "DELETE FROM {$wpdb->prefix}odm_credentials" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		add_filter(
			'pre_http_request',
			static function () {
				throw new \RuntimeException( 'A request must not be made without a credential.' );
			}
		);

		$result = $this->monitor()->check( $this->site );

		$this->assertSame( 'CREDENTIAL_NOT_FOUND', $result->error_code() );
		$this->assertSame( array( 'endpoint' => 'ping' ), $result->data() );
	}

	public function test_unsaved_site_is_rejected(): void {
		$site = new Site( null, wp_generate_uuid4(), 'Unsaved', 'https://example.com', 'https://example.com/wp-json/od-monitor-agent/v1' );

		$this->expectException( InvalidArgumentException::class );
		$this->monitor()->check( $site );
	}

	private function monitor( ?Closure $clock = null ): AgentPingMonitor {
		return new AgentPingMonitor(
			new AgentClient( new HttpClient( new UrlValidator( static fn(): array => array( '93.184.216.34' ) ) ), new ResponseValidator() ),
			$this->credentials,
			$clock
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
			'timestamp'      => '2026-09-09T09:00:00Z',
		);
	}

	/**
	 * @param array<string,mixed> $body Response body.
	 * @return array<string,mixed>
	 */
	private function response( int $status, array $body ): array {
		return $this->raw_response( $status, wp_json_encode( $body ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function raw_response( int $status, string $body ): array {
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
