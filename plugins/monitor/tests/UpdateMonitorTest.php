<?php
/**
 * Update availability monitor tests.
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
use Olein\WordPressMonitor\Monitor\CheckResult;
use Olein\WordPressMonitor\Monitor\Monitoring\UpdateMonitor;
use Olein\WordPressMonitor\Protocol\ResponseValidator;
use Olein\WordPressMonitor\Site\Site;
use Olein\WordPressMonitor\Site\SiteRepository;
use WP_Error;

final class UpdateMonitorTest extends \WP_UnitTestCase {
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

	public function test_no_updates_is_healthy(): void {
		$this->mock_response( 200, $this->updates_response( 0, 0, 0 ) );
		$times  = array( 100.0, 100.05 );
		$result = $this->monitor(
			static function () use ( &$times ): float {
				return array_shift( $times );
			}
		)->check( $this->site );

		$this->assertSame( CheckResult::STATUS_HEALTHY, $result->status() );
		$this->assertNull( $result->error_code() );
		$this->assertSame( 50, $result->duration_ms() );
		$this->assertSame( 0, $result->data()['total_updates'] );
		$this->assertSame( array(), $result->data()['update_types'] );
	}

	/**
	 * @dataProvider available_updates_provider
	 *
	 * @param list<string> $expected_types Expected update types.
	 */
	public function test_available_updates_are_warning_with_counts( int $wordpress, int $plugins, int $themes, array $expected_types ): void {
		$this->mock_response( 200, $this->updates_response( $wordpress, $plugins, $themes ) );
		$result = $this->monitor()->check( $this->site );

		$this->assertSame( CheckResult::STATUS_WARNING, $result->status() );
		$this->assertNull( $result->error_code() );
		$this->assertSame( $wordpress + $plugins + $themes, $result->data()['total_updates'] );
		$this->assertSame( $wordpress, $result->data()['wordpress_updates'] );
		$this->assertSame( $plugins, $result->data()['plugin_updates'] );
		$this->assertSame( $themes, $result->data()['theme_updates'] );
		$this->assertSame( $expected_types, $result->data()['update_types'] );
	}

	/**
	 * @return list<array{int,int,int,list<string>}>
	 */
	public function available_updates_provider(): array {
		return array(
			array( 1, 0, 0, array( 'wordpress' ) ),
			array( 0, 2, 0, array( 'plugins' ) ),
			array( 0, 0, 1, array( 'themes' ) ),
			array( 1, 2, 1, array( 'wordpress', 'plugins', 'themes' ) ),
		);
	}

	/**
	 * @dataProvider request_error_provider
	 */
	public function test_request_errors_are_classified( int $status, string $expected_code ): void {
		$this->mock_response( $status, array() );
		$result = $this->monitor()->check( $this->site );

		$this->assertSame( CheckResult::STATUS_CRITICAL, $result->status() );
		$this->assertSame( $expected_code, $result->error_code() );
		$this->assertSame( array( 'endpoint' => 'updates' ), $result->data() );
	}

	/**
	 * @return list<array{int,string}>
	 */
	public function request_error_provider(): array {
		return array(
			array( 401, 'AUTHENTICATION_FAILED' ),
			array( 403, 'PERMISSION_DENIED' ),
			array( 404, 'AGENT_NOT_FOUND' ),
		);
	}

	/**
	 * @dataProvider transport_error_provider
	 */
	public function test_transport_errors_do_not_retain_raw_or_secret_details( string $raw_message, string $expected_code ): void {
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

	public function test_invalid_summary_is_rejected(): void {
		$response                       = $this->updates_response( 0, 1, 0 );
		$response['summary']['plugins'] = 0;
		$this->mock_response( 200, $response );

		$result = $this->monitor()->check( $this->site );

		$this->assertSame( CheckResult::STATUS_CRITICAL, $result->status() );
		$this->assertSame( 'INVALID_RESPONSE', $result->error_code() );
	}

	public function test_invalid_json_is_rejected(): void {
		add_filter( 'pre_http_request', fn() => $this->raw_response( 200, '{broken' ) );

		$result = $this->monitor()->check( $this->site );

		$this->assertSame( CheckResult::STATUS_CRITICAL, $result->status() );
		$this->assertSame( 'INVALID_JSON', $result->error_code() );
	}

	public function test_unsupported_schema_is_rejected(): void {
		$response                   = $this->updates_response( 0, 0, 0 );
		$response['schema_version'] = '2.0';
		$this->mock_response( 200, $response );

		$result = $this->monitor()->check( $this->site );

		$this->assertSame( 'UNSUPPORTED_SCHEMA', $result->error_code() );
	}

	public function test_update_item_names_and_credentials_are_not_retained(): void {
		$response                       = $this->updates_response( 0, 1, 0 );
		$response['plugins'][0]['name'] = 'app-password-secret';
		add_filter(
			'pre_http_request',
			function ( $preempt, array $arguments ) use ( $response ) {
				$this->assertStringContainsString( 'app-password-secret', base64_decode( substr( $arguments['headers']['Authorization'], 6 ), true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
				return $this->response( 200, $response );
			},
			10,
			2
		);

		$result  = $this->monitor()->check( $this->site );
		$content = $result->message() . wp_json_encode( $result->data() );

		$this->assertSame( CheckResult::STATUS_WARNING, $result->status() );
		$this->assertStringNotContainsString( 'app-password-secret', $content );
		$this->assertStringNotContainsString( 'agent-user', $content );
		$this->assertStringNotContainsString( 'Authorization', $content );
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
	}

	public function test_unsaved_site_is_rejected(): void {
		$site = new Site( null, wp_generate_uuid4(), 'Unsaved', 'https://example.com', 'https://example.com/wp-json/od-monitor-agent/v1' );

		$this->expectException( InvalidArgumentException::class );
		$this->monitor()->check( $site );
	}

	private function monitor( ?Closure $clock = null ): UpdateMonitor {
		return new UpdateMonitor(
			new AgentClient( new HttpClient(), new ResponseValidator() ),
			$this->credentials,
			$clock
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function updates_response( int $wordpress, int $plugins, int $themes ): array {
		$plugin_updates = array();
		$theme_updates  = array();

		for ( $index = 0; $index < $plugins; ++$index ) {
			$plugin_updates[] = array(
				'file'             => 'plugin-' . $index . '/plugin.php',
				'name'             => 'Plugin ' . $index,
				'current_version'  => '1.0.0',
				'latest_version'   => '1.1.0',
				'update_available' => true,
				'active'           => true,
			);
		}

		for ( $index = 0; $index < $themes; ++$index ) {
			$theme_updates[] = array(
				'stylesheet'       => 'theme-' . $index,
				'name'             => 'Theme ' . $index,
				'current_version'  => '1.0.0',
				'latest_version'   => '1.1.0',
				'update_available' => true,
				'active'           => 0 === $index,
			);
		}

		return array(
			'schema_version' => '1.0',
			'wordpress'      => array(
				'current_version'  => '7.1',
				'latest_version'   => $wordpress > 0 ? '7.1.1' : '7.1',
				'update_available' => $wordpress > 0,
			),
			'plugins'        => $plugin_updates,
			'themes'         => $theme_updates,
			'summary'        => array(
				'wordpress' => $wordpress,
				'plugins'   => $plugins,
				'themes'    => $themes,
				'total'     => $wordpress + $plugins + $themes,
			),
			'timestamp'      => '2026-09-09T09:00:00Z',
		);
	}

	/**
	 * Register a short-circuited WordPress HTTP response.
	 *
	 * @param array<string,mixed> $body Response body.
	 */
	private function mock_response( int $status, array $body ): void {
		add_filter( 'pre_http_request', fn() => $this->response( $status, $body ) );
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
