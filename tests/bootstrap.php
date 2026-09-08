<?php
/**
 * PHPUnit bootstrap for the WordPress integration test suite.
 *
 * @package OD_WordPress_Monitor
 */

$tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/wordpress-phpunit';

if ( ! file_exists( $tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test suite not found. Set WP_TESTS_DIR or run npm run test:php.\n" );
	exit( 1 );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once $tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/plugins/agent/od-monitor-agent.php';
		require dirname( __DIR__ ) . '/plugins/monitor/od-wordpress-monitor.php';
	}
);

require $tests_dir . '/includes/bootstrap.php';
