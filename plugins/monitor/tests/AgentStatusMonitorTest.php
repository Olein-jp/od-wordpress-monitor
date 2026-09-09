<?php
/**
 * Agent status monitor tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use Olein\WordPressMonitor\Activation\DatabaseMigrator;
use Olein\WordPressMonitor\Credential\Credential;
use Olein\WordPressMonitor\Credential\CredentialEncryptor;
use Olein\WordPressMonitor\Credential\CredentialRepository;
use Olein\WordPressMonitor\Credential\CredentialService;
use Olein\WordPressMonitor\Http\AgentClient;
use Olein\WordPressMonitor\Http\HttpClient;
use Olein\WordPressMonitor\Monitor\CheckResult;
use Olein\WordPressMonitor\Monitor\Monitoring\AgentStatusMonitor;
use Olein\WordPressMonitor\Protocol\ResponseValidator;
use Olein\WordPressMonitor\Site\Site;
use Olein\WordPressMonitor\Site\SiteRepository;

final class AgentStatusMonitorTest extends \WP_UnitTestCase {
	private Site $site;
	private CredentialService $credentials;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;

		( new DatabaseMigrator( $wpdb ) )->migrate();
		$wpdb->query( "DELETE FROM {$wpdb->prefix}odm_credentials" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}odm_sites" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$sites   = new SiteRepository( $wpdb );
		$site_id = $sites->create( new Site( null, wp_generate_uuid4(), 'Example', 'https://example.com', 'https://example.com/agent' ) );
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

	public function test_valid_status_is_normalized_without_site_identity_data(): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, array $arguments, string $url ) {
				unset( $preempt, $arguments );
				$this->assertSame( 'https://example.com/agent/status', $url );

				return $this->response( 200, $this->valid_status() );
			},
			10,
			3
		);

		$result = $this->monitor()->check( $this->site );

		$this->assertSame( CheckResult::STATUS_HEALTHY, $result->status() );
		$this->assertNull( $result->error_code() );
		$this->assertSame( 'status', $result->data()['endpoint'] );
		$this->assertSame( '6.9', $result->data()['wordpress'] );
		$this->assertSame( '8.3.0', $result->data()['php'] );
		$this->assertArrayNotHasKey( 'site', $result->data() );
	}

	public function test_missing_credential_is_critical_without_request(): void {
		global $wpdb;

		$wpdb->query( "DELETE FROM {$wpdb->prefix}odm_credentials" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		add_filter(
			'pre_http_request',
			static function () {
				throw new \RuntimeException( 'Request must not run.' );
			}
		);

		$result = $this->monitor()->check( $this->site );

		$this->assertSame( CheckResult::STATUS_CRITICAL, $result->status() );
		$this->assertSame( 'CREDENTIAL_NOT_FOUND', $result->error_code() );
		$this->assertSame( array( 'endpoint' => 'status' ), $result->data() );
	}

	private function monitor(): AgentStatusMonitor {
		return new AgentStatusMonitor(
			new AgentClient( new HttpClient(), new ResponseValidator() ),
			$this->credentials
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function valid_status(): array {
		return array(
			'schema_version' => '1.0',
			'site'           => array(
				'url'      => 'https://example.com',
				'home_url' => 'https://example.com',
				'name'     => 'Example',
			),
			'wordpress'      => array(
				'version'     => '6.9',
				'multisite'   => false,
				'environment' => 'production',
			),
			'server'         => array( 'php_version' => '8.3.0' ),
			'agent'          => array( 'version' => '1.0.0' ),
			'timestamp'      => '2026-09-09T00:00:00Z',
		);
	}

	/**
	 * @param array<string,mixed> $body Response body.
	 * @return array<string,mixed>
	 */
	private function response( int $status, array $body ): array {
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $body ),
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
