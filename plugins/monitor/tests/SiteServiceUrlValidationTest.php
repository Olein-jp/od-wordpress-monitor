<?php
/**
 * Site registration URL boundary tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use Olein\WordPressMonitor\Activation\DatabaseMigrator;
use Olein\WordPressMonitor\Credential\CredentialEncryptor;
use Olein\WordPressMonitor\Credential\CredentialRepository;
use Olein\WordPressMonitor\Credential\CredentialService;
use Olein\WordPressMonitor\Http\AgentClient;
use Olein\WordPressMonitor\Http\HttpClient;
use Olein\WordPressMonitor\Http\UrlValidator;
use Olein\WordPressMonitor\Protocol\ResponseValidator;
use Olein\WordPressMonitor\Site\SiteRepository;
use Olein\WordPressMonitor\Site\SiteService;
use Olein\WordPressMonitor\Support\UUID;

final class SiteServiceUrlValidationTest extends \WP_UnitTestCase {
	private SiteRepository $sites;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;

		( new DatabaseMigrator( $wpdb ) )->migrate();
		$this->sites = new SiteRepository( $wpdb );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}odm_sites" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	/**
	 * @dataProvider blocked_registration_url_provider
	 */
	public function test_registration_rejects_unsafe_url_before_agent_request( string $url, string $expected_code ): void {
		global $wpdb;
		$validator = new UrlValidator( static fn(): array => array( '10.0.0.8' ) );
		$service   = new SiteService(
			$this->sites,
			new CredentialService( new CredentialRepository( $wpdb ), new CredentialEncryptor() ),
			new AgentClient( new HttpClient( $validator ), new ResponseValidator() ),
			new UUID(),
			$validator
		);
		add_filter(
			'pre_http_request',
			static function () {
				throw new \RuntimeException( 'Unsafe registration must not make an HTTP request.' );
			}
		);

		$result = $service->register( 'Unsafe', $url, 'agent-user', 'must-not-leak' );

		$this->assertWPError( $result );
		$this->assertSame( $expected_code, $result->get_error_code() );
		$this->assertSame( array(), $this->sites->all() );
		$this->assertStringNotContainsString( 'must-not-leak', $result->get_error_message() );
		$this->assertStringNotContainsString( '10.0.0.8', $result->get_error_message() );
	}

	/**
	 * @return list<array{string,string}>
	 */
	public function blocked_registration_url_provider(): array {
		return array(
			array( 'http://example.com', 'HTTPS_REQUIRED' ),
			array( 'https://localhost', 'INVALID_URL' ),
			array( 'https://metadata.example.com', 'INVALID_URL' ),
		);
	}
}
