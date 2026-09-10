<?php
/**
 * Outbound URL policy tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use Olein\WordPressMonitor\Http\UrlValidator;

final class UrlValidatorTest extends \WP_UnitTestCase {
	public function test_public_https_url_is_normalized_and_allowed(): void {
		$validator = new UrlValidator(
			static function ( string $host ): array {
				\PHPUnit\Framework\Assert::assertSame( 'example.com', $host );
				return array( '93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946' );
			}
		);

		$this->assertSame( 'https://example.com/path?key=value', $validator->validate( ' HTTPS://Example.COM:443/path?key=value#fragment ' ) );
	}

	public function test_http_is_rejected_before_dns_resolution(): void {
		$validator = new UrlValidator(
			static function (): array {
				throw new \RuntimeException( 'DNS must not run for an HTTP URL.' );
			}
		);
		$result    = $validator->validate( 'http://example.com' );

		$this->assertWPError( $result );
		$this->assertSame( 'HTTPS_REQUIRED', $result->get_error_code() );
	}

	/**
	 * @dataProvider blocked_url_provider
	 */
	public function test_local_private_link_local_and_metadata_targets_are_rejected( string $url ): void {
		$validator = new UrlValidator( static fn(): array => array( '169.254.169.254' ) );
		$result    = $validator->validate( $url );

		$this->assertWPError( $result );
		$this->assertSame( 'INVALID_URL', $result->get_error_code() );
		$this->assertStringNotContainsString( '169.254.169.254', $result->get_error_message() );
	}

	/**
	 * @return list<array{string}>
	 */
	public function blocked_url_provider(): array {
		return array(
			array( 'https://localhost' ),
			array( 'https://service.localhost' ),
			array( 'https://metadata.google.internal' ),
			array( 'https://127.0.0.1' ),
			array( 'https://10.0.0.1' ),
			array( 'https://172.16.0.1' ),
			array( 'https://192.168.0.1' ),
			array( 'https://169.254.169.254/latest/meta-data/' ),
			array( 'https://[::1]' ),
			array( 'https://[fc00::1]' ),
			array( 'https://[fe80::1]' ),
			array( 'https://[::ffff:127.0.0.1]' ),
		);
	}

	public function test_every_resolved_address_must_be_public(): void {
		$validator = new UrlValidator( static fn(): array => array( '93.184.216.34', '10.0.0.5' ) );

		$this->assertWPError( $validator->validate( 'https://mixed.example.com' ) );
	}

	public function test_dns_failure_is_rejected_without_exposing_details(): void {
		$validator = new UrlValidator( static fn(): array => array() );
		$result    = $validator->validate( 'https://secret-host.example.com' );

		$this->assertWPError( $result );
		$this->assertSame( 'INVALID_URL', $result->get_error_code() );
		$this->assertStringNotContainsString( 'secret-host', $result->get_error_message() );
	}

	public function test_dns_is_checked_again_when_resolution_changes(): void {
		$addresses = array( array( '93.184.216.34' ), array( '127.0.0.1' ) );
		$validator = new UrlValidator(
			static function () use ( &$addresses ): array {
				return array_shift( $addresses );
			}
		);

		$this->assertSame( 'https://rebind.example.com', $validator->validate( 'https://rebind.example.com' ) );
		$this->assertWPError( $validator->validate( 'https://rebind.example.com' ) );
	}
}
