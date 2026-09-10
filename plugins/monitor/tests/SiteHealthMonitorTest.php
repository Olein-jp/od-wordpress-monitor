<?php
/**
 * Site Health monitor tests.
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
use Olein\WordPressMonitor\Monitor\Monitoring\SiteHealthMonitor;
use Olein\WordPressMonitor\Protocol\ResponseValidator;
use Olein\WordPressMonitor\Site\Site;
use Olein\WordPressMonitor\Site\SiteRepository;
use WP_Error;

final class SiteHealthMonitorTest extends \WP_UnitTestCase {
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

	/**
	 * @dataProvider status_provider
	 */
	public function test_maps_summary_to_status( int $critical, int $recommended, int $good, string $expected ): void {
		$this->mock_response( 200, $this->site_health_response( $critical, $recommended, $good ) );
		$result = $this->monitor()->check( $this->site );

		$this->assertSame( $expected, $result->status() );
		$this->assertNull( $result->error_code() );
		$this->assertSame( $critical, $result->data()['critical'] );
		$this->assertSame( $recommended, $result->data()['recommended'] );
		$this->assertSame( $good, $result->data()['good'] );
	}

	/**
	 * @return list<array{int,int,int,string}>
	 */
	public function status_provider(): array {
		return array(
			array( 1, 1, 1, CheckResult::STATUS_CRITICAL ),
			array( 0, 1, 1, CheckResult::STATUS_WARNING ),
			array( 0, 0, 2, CheckResult::STATUS_HEALTHY ),
		);
	}

	public function test_keeps_only_counts_and_highest_severity_test_identifier(): void {
		$response                      = $this->site_health_response( 1, 1, 1 );
		$response['tests'][0]['label'] = 'Database details that must not be retained';
		$response['tests'][1]['label'] = 'Recommended details that must not be retained';
		$response['tests'][2]['label'] = 'Good details that must not be retained';
		$this->mock_response( 200, $response );

		$result  = $this->monitor()->check( $this->site );
		$content = wp_json_encode( $result->data() );

		$this->assertSame( 'test_critical_0', $result->data()['representative_test_id'] );
		$this->assertSame( 'critical', $result->data()['representative_test_status'] );
		$this->assertStringNotContainsString( 'details', $content );
		$this->assertStringNotContainsString( 'app-password-secret', $content );
	}

	public function test_agent_error_is_critical_without_raw_details(): void {
		add_filter( 'pre_http_request', static fn() => new WP_Error( 'http_request_failed', 'Authorization: Basic raw-secret timed out' ) );
		$result  = $this->monitor()->check( $this->site );
		$content = $result->message() . wp_json_encode( $result->data() );

		$this->assertSame( CheckResult::STATUS_CRITICAL, $result->status() );
		$this->assertSame( 'TIMEOUT', $result->error_code() );
		$this->assertSame( array(), $result->data() );
		$this->assertStringNotContainsString( 'raw-secret', $content );
	}

	public function test_records_duration(): void {
		$this->mock_response( 200, $this->site_health_response( 0, 0, 1 ) );
		$times  = array( 100.0, 100.05 );
		$result = $this->monitor(
			static function () use ( &$times ): float {
				return array_shift( $times );
			}
		)->check( $this->site );

		$this->assertSame( 50, $result->duration_ms() );
	}

	public function test_unsaved_site_is_rejected(): void {
		$site = new Site( null, wp_generate_uuid4(), 'Unsaved', 'https://example.com', 'https://example.com/wp-json/od-monitor-agent/v1' );

		$this->expectException( InvalidArgumentException::class );
		$this->monitor()->check( $site );
	}

	private function monitor( ?Closure $clock = null ): SiteHealthMonitor {
		return new SiteHealthMonitor(
			new AgentClient( new HttpClient( new UrlValidator( static fn(): array => array( '93.184.216.34' ) ) ), new ResponseValidator() ),
			$this->credentials,
			$clock
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function site_health_response( int $critical, int $recommended, int $good ): array {
		$tests = array();

		foreach ( compact( 'critical', 'recommended', 'good' ) as $status => $count ) {
			for ( $index = 0; $index < $count; ++$index ) {
				$tests[] = array(
					'id'     => 'test_' . $status . '_' . $index,
					'status' => $status,
					'label'  => ucfirst( $status ) . ' test',
				);
			}
		}

		return array(
			'schema_version' => '1.0',
			'summary'        => compact( 'critical', 'recommended', 'good' ),
			'tests'          => $tests,
			'timestamp'      => '2026-09-10T09:00:00Z',
		);
	}

	/**
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
}
