<?php
/**
 * Safe Site Health collector tests.
 *
 * @package OD_Monitor_Agent
 */

namespace Olein\MonitorAgent\Tests;

use Olein\MonitorAgent\Collector\SiteHealthCollector;
use RuntimeException;
use WP_Error;

final class SiteHealthCollectorTest extends \WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		delete_site_transient( SiteHealthCollector::CACHE_KEY );
	}

	public function tear_down(): void {
		delete_site_transient( SiteHealthCollector::CACHE_KEY );
		remove_all_filters( 'site_status_tests' );
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	public function test_allowlist_is_compatible_with_installed_wordpress(): void {
		$definitions = \WP_Site_Health::get_tests();
		$site_health = \WP_Site_Health::get_instance();

		foreach ( SiteHealthCollector::SAFE_TESTS as $id => $approved ) {
			$this->assertArrayHasKey( $id, $definitions['direct'], $id . ' must remain a direct Core test.' );
			$this->assertSame( $approved['test'], $definitions['direct'][ $id ]['test'] );
			$this->assertTrue( is_callable( array( $site_health, $approved['method'] ) ) );
		}
	}

	public function test_collects_only_allowlisted_tests_without_http_requests(): void {
		$http_requests = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$http_requests ) {
				++$http_requests;
				return new WP_Error( 'unexpected_http_request', 'Unexpected HTTP request.' );
			}
		);

		$result = ( new SiteHealthCollector() )->collect();

		$this->assertNotWPError( $result );
		$this->assertCount( count( SiteHealthCollector::SAFE_TESTS ), $result['tests'] );
		$this->assertSame( 0, $http_requests );
		$this->assertSame( count( $result['tests'] ), array_sum( $result['summary'] ) );

		foreach ( $result['tests'] as $test ) {
			$this->assertArrayHasKey( $test['id'], SiteHealthCollector::SAFE_TESTS );
			$this->assertContains( $test['status'], array( 'good', 'recommended', 'critical' ) );
			$this->assertSame( wp_strip_all_tags( $test['label'] ), $test['label'] );
		}
	}

	public function test_invalid_replaced_and_failing_tests_are_skipped(): void {
		add_filter(
			'site_status_tests',
			static function ( array $tests ): array {
				$tests['direct'] = array(
					'php_extensions' => array( 'test' => 'php_extensions' ),
					'file_uploads'   => array( 'test' => 'file_uploads' ),
					'debug_enabled'  => array( 'test' => 'is_in_debug_mode' ),
					'http_requests'  => array( 'test' => static fn(): array => array() ),
				);
				return $tests;
			}
		);

		$site_health = new class() {
			public function get_test_php_extensions(): array {
				return array(
					'status' => 'good',
					'label'  => '<strong>Healthy</strong>',
				);
			}

			public function get_test_file_uploads(): array {
				return array(
					'status' => 'unknown',
					'label'  => 'Unknown',
				);
			}

			public function get_test_is_in_debug_mode(): array {
				throw new RuntimeException( 'Sensitive details must not escape.' );
			}
		};

		$result = ( new SiteHealthCollector( $site_health ) )->collect();

		$this->assertNotWPError( $result );
		$this->assertSame(
			array(
				array(
					'id'     => 'php_extensions',
					'status' => 'good',
					'label'  => 'Healthy',
				),
			),
			$result['tests']
		);
		$this->assertSame(
			array(
				'critical'    => 0,
				'recommended' => 0,
				'good'        => 1,
			),
			$result['summary']
		);
	}

	public function test_all_invalid_tests_return_unavailable_error(): void {
		add_filter(
			'site_status_tests',
			static function ( array $tests ): array {
				$tests['direct'] = array( 'php_extensions' => array( 'test' => 'php_extensions' ) );
				return $tests;
			}
		);

		$site_health = new class() {
			public function get_test_php_extensions(): string {
				return 'invalid';
			}
		};

		$result = ( new SiteHealthCollector( $site_health ) )->collect();

		$this->assertWPError( $result );
		$this->assertSame( 'od_monitor_agent_site_health_unavailable', $result->get_error_code() );
		$this->assertSame( 503, $result->get_error_data()['status'] );
	}

	public function test_normalized_result_is_reused_from_cache(): void {
		add_filter(
			'site_status_tests',
			static function ( array $tests ): array {
				$tests['direct'] = array( 'php_extensions' => array( 'test' => 'php_extensions' ) );
				return $tests;
			}
		);

		$site_health = new class() {
			public int $calls = 0;

			public function get_test_php_extensions(): array {
				++$this->calls;
				return array(
					'status' => 'good',
					'label'  => 'Healthy',
				);
			}
		};
		$collector   = new SiteHealthCollector( $site_health );
		$first       = $collector->collect();
		$second      = $collector->collect();

		$this->assertNotWPError( $first );
		$this->assertSame( $first, $second );
		$this->assertSame( 1, $site_health->calls );
	}

	public function test_soft_budget_stops_starting_more_tests(): void {
		add_filter(
			'site_status_tests',
			static function ( array $tests ): array {
				$tests['direct'] = array(
					'wordpress_version' => array( 'test' => 'wordpress_version' ),
					'plugin_version'    => array( 'test' => 'plugin_version' ),
				);
				return $tests;
			}
		);

		$site_health = new class() {
			public int $plugin_version_calls = 0;

			public function get_test_wordpress_version(): array {
				return array(
					'status' => 'good',
					'label'  => 'Healthy',
				);
			}

			public function get_test_plugin_version(): array {
				++$this->plugin_version_calls;
				return array(
					'status' => 'good',
					'label'  => 'Plugins are current',
				);
			}
		};
		$times       = array( 0.0, 0.0, SiteHealthCollector::EXECUTION_BUDGET );
		$clock       = static function () use ( &$times ): float {
			return array_shift( $times );
		};
		$result      = ( new SiteHealthCollector( $site_health, $clock ) )->collect();

		$this->assertNotWPError( $result );
		$this->assertCount( 1, $result['tests'] );
		$this->assertSame( 0, $site_health->plugin_version_calls );
	}
}
