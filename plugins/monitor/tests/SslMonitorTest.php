<?php
/**
 * SSL certificate monitor tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use Olein\WordPressMonitor\Monitor\CheckResult;
use Olein\WordPressMonitor\Monitor\Monitoring\SslCertificateClientInterface;
use Olein\WordPressMonitor\Monitor\Monitoring\SslMonitor;
use Olein\WordPressMonitor\Site\Site;
use WP_Error;

final class SslMonitorTest extends \WP_UnitTestCase {
	public const NOW = 1788944400;

	private Site $site;

	public function set_up(): void {
		parent::set_up();
		$this->site = new Site( 24, wp_generate_uuid4(), 'Example', 'https://example.com/path?ignored=yes', 'https://example.com/wp-json/od-monitor-agent/v1' );
	}

	public function test_certificate_with_sufficient_lifetime_is_healthy(): void {
		$result = $this->monitor_with_certificate( self::NOW - DAY_IN_SECONDS, self::NOW + ( 31 * DAY_IN_SECONDS ) )->check( $this->site );

		$this->assertSame( CheckResult::STATUS_HEALTHY, $result->status() );
		$this->assertNull( $result->error_code() );
		$this->assertSame( 'ssl', $result->type() );
		$this->assertSame( 24, $result->site_id() );
		$this->assertSame( 50, $result->duration_ms() );
		$this->assertSame( 'example.com', $result->data()['host'] );
		$this->assertSame( 443, $result->data()['port'] );
		$this->assertSame( 31, $result->data()['days_left'] );
		$this->assertSame( gmdate( 'Y-m-d\TH:i:s\Z', self::NOW + ( 31 * DAY_IN_SECONDS ) ), $result->data()['valid_to'] );
		$this->assertSame( 'UTC', $result->started_at()->getTimezone()->getName() );
	}

	public function test_certificate_inside_warning_threshold_is_warning(): void {
		$result = $this->monitor_with_certificate( self::NOW - DAY_IN_SECONDS, self::NOW + ( 30 * DAY_IN_SECONDS ) )->check( $this->site );

		$this->assertSame( CheckResult::STATUS_WARNING, $result->status() );
		$this->assertSame( 'CERTIFICATE_EXPIRING', $result->error_code() );
		$this->assertSame( 30, $result->data()['warning_days'] );
	}

	public function test_configured_failure_threshold_is_critical(): void {
		$result = $this->monitor_with_certificate( self::NOW - DAY_IN_SECONDS, self::NOW + ( 7 * DAY_IN_SECONDS ), 30, 7 )->check( $this->site );

		$this->assertSame( CheckResult::STATUS_CRITICAL, $result->status() );
		$this->assertSame( 'CERTIFICATE_EXPIRING', $result->error_code() );
		$this->assertSame( 7, $result->data()['failure_days'] );
	}

	public function test_expired_certificate_is_critical(): void {
		$result = $this->monitor_with_certificate( self::NOW - ( 60 * DAY_IN_SECONDS ), self::NOW - 1 )->check( $this->site );

		$this->assertSame( CheckResult::STATUS_CRITICAL, $result->status() );
		$this->assertSame( 'CERTIFICATE_EXPIRED', $result->error_code() );
		$this->assertSame( -1, $result->data()['days_left'] );
	}

	public function test_not_yet_valid_certificate_is_critical(): void {
		$result = $this->monitor_with_certificate( self::NOW + 1, self::NOW + ( 90 * DAY_IN_SECONDS ) )->check( $this->site );

		$this->assertSame( CheckResult::STATUS_CRITICAL, $result->status() );
		$this->assertSame( 'CERTIFICATE_NOT_YET_VALID', $result->error_code() );
	}

	/**
	 * @dataProvider client_error_provider
	 */
	public function test_certificate_validation_and_transport_errors_are_normalized( string $code, string $raw_message ): void {
		$client = new class( $code, $raw_message ) implements SslCertificateClientInterface {
			public function __construct( private readonly string $code, private readonly string $raw_message ) {
			}

			public function inspect( string $host, int $port, int $timeout ): array|WP_Error {
				unset( $host, $port, $timeout );
				return new WP_Error( $this->code, $this->raw_message );
			}
		};
		$result = $this->monitor( $client )->check( $this->site );

		$this->assertSame( CheckResult::STATUS_CRITICAL, $result->status() );
		$this->assertSame( $code, $result->error_code() );
		$this->assertStringNotContainsString( $raw_message, $result->message() );
	}

	/**
	 * @return list<array{string,string}>
	 */
	public function client_error_provider(): array {
		return array(
			array( 'CERTIFICATE_VALIDATION_FAILED', 'Peer certificate name does not match the registered host.' ),
			array( 'CERTIFICATE_VALIDATION_FAILED', 'Certificate verify failed for an unknown trust authority.' ),
			array( 'TIMEOUT', 'TLS connection timed out with internal network details.' ),
		);
	}

	public function test_https_custom_port_is_passed_to_client(): void {
		$called = false;
		$client = new class( $called ) implements SslCertificateClientInterface {
			public function __construct( private bool &$called ) {
			}

			public function inspect( string $host, int $port, int $timeout ): array|WP_Error {
				$this->called = true;
				\PHPUnit\Framework\Assert::assertSame( 'example.com', $host );
				\PHPUnit\Framework\Assert::assertSame( 8080, $port );
				\PHPUnit\Framework\Assert::assertSame( SslMonitor::TIMEOUT, $timeout );
				return array(
					'valid_from' => SslMonitorTest::NOW - DAY_IN_SECONDS,
					'valid_to'   => SslMonitorTest::NOW + ( 90 * DAY_IN_SECONDS ),
				);
			}
		};
		$site   = new Site( 24, wp_generate_uuid4(), 'Custom port', 'https://example.com:8080/', 'https://example.com/wp-json/od-monitor-agent/v1' );

		$this->monitor( $client )->check( $site );
		$this->assertTrue( $called );
	}

	/**
	 * @dataProvider invalid_url_provider
	 */
	public function test_invalid_or_unsafe_url_is_rejected_without_connecting( string $url ): void {
		$client = new class() implements SslCertificateClientInterface {
			public function inspect( string $host, int $port, int $timeout ): array|WP_Error {
				unset( $host, $port, $timeout );
				throw new \RuntimeException( 'Invalid targets must not be connected.' );
			}
		};
		$site   = new Site( 24, wp_generate_uuid4(), 'Invalid', $url, 'https://example.com/wp-json/od-monitor-agent/v1' );
		$result = $this->monitor( $client )->check( $site );

		$this->assertSame( CheckResult::STATUS_CRITICAL, $result->status() );
		$this->assertSame( 'INVALID_URL', $result->error_code() );
		$this->assertArrayNotHasKey( 'host', $result->data() );
	}

	/**
	 * @return list<array{string}>
	 */
	public function invalid_url_provider(): array {
		return array(
			array( 'http://example.com' ),
			array( 'https://127.0.0.1' ),
			array( 'https://user:password@example.com' ),
			array( 'not-a-url' ),
		);
	}

	public function test_unsaved_site_is_rejected(): void {
		$site = new Site( null, wp_generate_uuid4(), 'Unsaved', 'https://example.com', 'https://example.com/wp-json/od-monitor-agent/v1' );

		$this->expectException( InvalidArgumentException::class );
		$this->monitor_with_certificate( self::NOW, self::NOW + DAY_IN_SECONDS )->check( $site );
	}

	/**
	 * @dataProvider invalid_threshold_provider
	 */
	public function test_invalid_thresholds_are_rejected( int $warning_days, int $failure_days ): void {
		$client = new class() implements SslCertificateClientInterface {
			public function inspect( string $host, int $port, int $timeout ): array|WP_Error {
				unset( $host, $port, $timeout );
				return new WP_Error( 'UNUSED' );
			}
		};

		$this->expectException( InvalidArgumentException::class );
		new SslMonitor( $client, $warning_days, $failure_days );
	}

	/**
	 * @return list<array{int,int}>
	 */
	public function invalid_threshold_provider(): array {
		return array(
			array( 0, 0 ),
			array( 7, 7 ),
			array( 7, 8 ),
			array( 30, -1 ),
		);
	}

	private function monitor_with_certificate( int $valid_from, int $valid_to, int $warning_days = 30, int $failure_days = 0 ): SslMonitor {
		$client = new class( $valid_from, $valid_to ) implements SslCertificateClientInterface {
			public function __construct( private readonly int $valid_from, private readonly int $valid_to ) {
			}

			public function inspect( string $host, int $port, int $timeout ): array|WP_Error {
				unset( $host, $port, $timeout );
				return array(
					'valid_from' => $this->valid_from,
					'valid_to'   => $this->valid_to,
				);
			}
		};

		return $this->monitor( $client, $warning_days, $failure_days );
	}

	private function monitor( SslCertificateClientInterface $client, int $warning_days = 30, int $failure_days = 0 ): SslMonitor {
		$times = array( 100.0, 100.05 );

		return new SslMonitor(
			$client,
			$warning_days,
			$failure_days,
			static function () use ( &$times ): float {
				return array_shift( $times );
			},
			static fn(): DateTimeImmutable => ( new DateTimeImmutable( '@' . self::NOW ) )->setTimezone( new \DateTimeZone( 'Asia/Tokyo' ) )
		);
	}
}
