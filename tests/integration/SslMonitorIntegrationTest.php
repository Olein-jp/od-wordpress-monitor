<?php
/**
 * SSL monitor persistence integration test.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\IntegrationTests;

use DateTimeImmutable;
use Olein\WordPressMonitor\Activation\DatabaseMigrator;
use Olein\WordPressMonitor\Monitor\CheckResult;
use Olein\WordPressMonitor\Monitor\Monitoring\SslCertificateClientInterface;
use Olein\WordPressMonitor\Monitor\Monitoring\SslMonitor;
use Olein\WordPressMonitor\Site\Site;
use Olein\WordPressMonitor\Site\SiteRepository;
use WP_Error;

final class SslMonitorIntegrationTest extends \WP_UnitTestCase {
	public function test_persisted_site_can_be_checked_using_common_result_contract(): void {
		global $wpdb;

		( new DatabaseMigrator( $wpdb ) )->migrate();
		$repository = new SiteRepository( $wpdb );
		$site_id    = $repository->create(
			new Site( null, wp_generate_uuid4(), 'SSL integration', 'https://example.com', 'https://example.com/wp-json/od-monitor-agent/v1' )
		);
		$this->assertIsInt( $site_id );

		$client = new class() implements SslCertificateClientInterface {
			public function inspect( string $host, int $port, int $timeout ): array|WP_Error {
				unset( $host, $port, $timeout );
				return array(
					'valid_from' => 1788944400 - DAY_IN_SECONDS,
					'valid_to'   => 1788944400 + ( 90 * DAY_IN_SECONDS ),
				);
			}
		};
		$now    = static fn(): DateTimeImmutable => new DateTimeImmutable( '@1788944400' );
		$result = ( new SslMonitor( $client, 30, 0, null, $now ) )->check( $repository->find( $site_id ) );

		$this->assertInstanceOf( CheckResult::class, $result );
		$this->assertSame( $site_id, $result->site_id() );
		$this->assertSame( CheckResult::STATUS_HEALTHY, $result->status() );
		$this->assertSame( 'ssl', $result->type() );
	}
}
