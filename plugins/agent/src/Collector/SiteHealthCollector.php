<?php
/**
 * Safe WordPress Site Health collector.
 *
 * @package OD_Monitor_Agent
 */

namespace Olein\MonitorAgent\Collector;

use Closure;
use Throwable;
use WP_Error;

final class SiteHealthCollector {
	public const CACHE_KEY        = 'od_monitor_agent_site_health_v2';
	public const CACHE_TTL        = 15 * MINUTE_IN_SECONDS;
	public const EXECUTION_BUDGET = 10.0;

	/**
	 * Core Site Health tests approved for synchronous monitoring.
	 *
	 * @var array<string,array{test:string,method:string}>
	 */
	public const SAFE_TESTS = array(
		'wordpress_version'         => array(
			'test'   => 'wordpress_version',
			'method' => 'get_test_wordpress_version',
		),
		'plugin_version'            => array(
			'test'   => 'plugin_version',
			'method' => 'get_test_plugin_version',
		),
		'theme_version'             => array(
			'test'   => 'theme_version',
			'method' => 'get_test_theme_version',
		),
		'php_extensions'            => array(
			'test'   => 'php_extensions',
			'method' => 'get_test_php_extensions',
		),
		'php_default_timezone'      => array(
			'test'   => 'php_default_timezone',
			'method' => 'get_test_php_default_timezone',
		),
		'sql_server'                => array(
			'test'   => 'sql_server',
			'method' => 'get_test_sql_server',
		),
		'ssl_support'               => array(
			'test'   => 'ssl_support',
			'method' => 'get_test_ssl_support',
		),
		'scheduled_events'          => array(
			'test'   => 'scheduled_events',
			'method' => 'get_test_scheduled_events',
		),
		'http_requests'             => array(
			'test'   => 'http_requests',
			'method' => 'get_test_http_requests',
		),
		'debug_enabled'             => array(
			'test'   => 'is_in_debug_mode',
			'method' => 'get_test_is_in_debug_mode',
		),
		'file_uploads'              => array(
			'test'   => 'file_uploads',
			'method' => 'get_test_file_uploads',
		),
		'plugin_theme_auto_updates' => array(
			'test'   => 'plugin_theme_auto_updates',
			'method' => 'get_test_plugin_theme_auto_updates',
		),
	);

	private Closure $clock;

	public function __construct(
		private readonly ?object $site_health = null,
		?Closure $clock = null
	) {
		$this->clock = $clock ?? static fn(): float => microtime( true );
	}

	/**
	 * Collect and normalize the approved Site Health tests.
	 *
	 * @return array{summary:array{critical:int,recommended:int,good:int},tests:list<array{id:string,status:string,label:string}>,timestamp:string}|WP_Error
	 */
	public function collect() {
		$cached = get_site_transient( self::CACHE_KEY );

		if ( $this->valid_payload( $cached ) ) {
			return $cached;
		}

		$site_health = $this->site_health ?? $this->load_site_health();

		if ( is_wp_error( $site_health ) ) {
			return $site_health;
		}

		try {
			$definitions = \WP_Site_Health::get_tests();
		} catch ( Throwable ) {
			return $this->unavailable_error();
		}

		$direct  = isset( $definitions['direct'] ) && is_array( $definitions['direct'] ) ? $definitions['direct'] : array();
		$started = ( $this->clock )();
		$tests   = array();
		$summary = array(
			'critical'    => 0,
			'recommended' => 0,
			'good'        => 0,
		);

		foreach ( self::SAFE_TESTS as $id => $approved ) {
			if ( ( $this->clock )() - $started >= self::EXECUTION_BUDGET ) {
				break;
			}

			$definition = $direct[ $id ] ?? null;

			if (
				! is_array( $definition ) ||
				! isset( $definition['test'] ) ||
				$approved['test'] !== $definition['test'] ||
				! is_callable( array( $site_health, $approved['method'] ) )
			) {
				continue;
			}

			try {
				$result = $site_health->{$approved['method']}();
			} catch ( Throwable ) {
				continue;
			}

			$normalized = $this->normalize_test( $id, $result );

			if ( null === $normalized ) {
				continue;
			}

			$tests[] = $normalized;
			++$summary[ $normalized['status'] ];
		}

		if ( array() === $tests ) {
			return $this->unavailable_error();
		}

		$payload = array(
			'summary'   => $summary,
			'tests'     => $tests,
			'timestamp' => gmdate( 'Y-m-d\TH:i:s\Z' ),
		);

		set_site_transient( self::CACHE_KEY, $payload, self::CACHE_TTL );

		return $payload;
	}

	/**
	 * Load the Core Site Health singleton without using private APIs.
	 *
	 * @return object|WP_Error
	 */
	private function load_site_health() {
		if ( ! class_exists( 'WP_Site_Health' ) ) {
			$core_file = ABSPATH . 'wp-admin/includes/class-wp-site-health.php';

			if ( file_exists( $core_file ) ) {
				require_once $core_file;
			}
		}

		if ( ! class_exists( 'WP_Site_Health' ) ) {
			return $this->unavailable_error();
		}

		if ( ! function_exists( 'get_core_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return \WP_Site_Health::get_instance();
	}

	/**
	 * Reduce a Core result to the public protocol fields.
	 *
	 * @param mixed $result Core test result.
	 * @return array{id:string,status:string,label:string}|null
	 */
	private function normalize_test( string $id, $result ): ?array {
		if (
			! is_array( $result ) ||
			! isset( $result['status'], $result['label'] ) ||
			! is_string( $result['status'] ) ||
			! in_array( $result['status'], array( 'good', 'recommended', 'critical' ), true ) ||
			! is_string( $result['label'] )
		) {
			return null;
		}

		$label = sanitize_text_field( wp_strip_all_tags( $result['label'], true ) );

		if ( '' === $label ) {
			return null;
		}

		return array(
			'id'     => $id,
			'status' => $result['status'],
			'label'  => $label,
		);
	}

	/**
	 * Validate a cached normalized payload before returning it.
	 *
	 * @param mixed $payload Cached value.
	 */
	private function valid_payload( $payload ): bool {
		if (
			! is_array( $payload ) ||
			! isset( $payload['summary'], $payload['tests'], $payload['timestamp'] ) ||
			array() !== array_diff( array_keys( $payload ), array( 'summary', 'tests', 'timestamp' ) ) ||
			! is_array( $payload['summary'] ) ||
			! is_array( $payload['tests'] ) ||
			! array_is_list( $payload['tests'] ) ||
			! is_string( $payload['timestamp'] ) ||
			! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $payload['timestamp'] ) ||
			false === strtotime( $payload['timestamp'] )
		) {
			return false;
		}

		$counts = array(
			'critical'    => 0,
			'recommended' => 0,
			'good'        => 0,
		);
		$seen   = array();

		foreach ( $payload['tests'] as $test ) {
			if (
				! is_array( $test ) ||
				! isset( $test['id'], $test['status'], $test['label'] ) ||
				array() !== array_diff( array_keys( $test ), array( 'id', 'status', 'label' ) ) ||
				! is_string( $test['id'] ) ||
				! isset( self::SAFE_TESTS[ $test['id'] ] ) ||
				isset( $seen[ $test['id'] ] ) ||
				! is_string( $test['status'] ) ||
				! isset( $counts[ $test['status'] ] ) ||
				! is_string( $test['label'] ) ||
				'' === $test['label'] ||
				str_contains( $test['label'], '<' ) ||
				str_contains( $test['label'], '>' )
			) {
				return false;
			}

			$seen[ $test['id'] ] = true;
			++$counts[ $test['status'] ];
		}

		if ( array() === $payload['tests'] || 3 !== count( $payload['summary'] ) ) {
			return false;
		}

		foreach ( $counts as $status => $count ) {
			if ( ! isset( $payload['summary'][ $status ] ) || $count !== $payload['summary'][ $status ] ) {
				return false;
			}
		}

		return true;
	}

	private function unavailable_error(): WP_Error {
		return new WP_Error(
			'od_monitor_agent_site_health_unavailable',
			__( 'Site Health data is unavailable.', 'od-monitor-agent' ),
			array( 'status' => 503 )
		);
	}
}
