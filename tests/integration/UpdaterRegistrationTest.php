<?php
/**
 * GitHub updater registration integration tests.
 *
 * @package OD_WordPress_Monitor
 */

final class UpdaterRegistrationTest extends WP_UnitTestCase {
	public function test_both_plugins_lock_the_same_updater_version(): void {
		$root            = dirname( __DIR__, 2 );
		$agent_lock      = $this->read_composer_lock( $root . '/plugins/agent/composer.lock' );
		$monitor_lock    = $this->read_composer_lock( $root . '/plugins/monitor/composer.lock' );
		$agent_version   = $this->find_package_version( $agent_lock, 'inc2734/wp-github-plugin-updater' );
		$monitor_version = $this->find_package_version( $monitor_lock, 'inc2734/wp-github-plugin-updater' );

		$this->assertNotSame( '', $agent_version );
		$this->assertSame( $agent_version, $monitor_version );
	}

	public function test_plugins_declare_distinct_update_uris(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$root    = dirname( __DIR__, 2 );
		$agent   = get_plugin_data( $root . '/plugins/agent/od-monitor-agent.php', false, false );
		$monitor = get_plugin_data( $root . '/plugins/monitor/od-wordpress-monitor.php', false, false );

		$this->assertSame( 'https://github.com/Olein-jp/od-monitor-agent-release', $agent['UpdateURI'] );
		$this->assertSame( 'https://github.com/Olein-jp/od-wordpress-monitor-release', $monitor['UpdateURI'] );
	}

	public function test_both_plugins_register_github_updater_callbacks(): void {
		global $wp_filter;

		$root               = dirname( __DIR__, 2 );
		$registered_plugins = array();
		$callbacks          = $wp_filter['pre_set_site_transient_update_plugins']->callbacks ?? array();
		$expected_plugins   = array(
			plugin_basename( $root . '/plugins/agent/od-monitor-agent.php' ),
			plugin_basename( $root . '/plugins/monitor/od-wordpress-monitor.php' ),
		);

		foreach ( $callbacks as $priority_callbacks ) {
			foreach ( $priority_callbacks as $callback ) {
				$function = $callback['function'] ?? null;

				if ( ! is_array( $function ) || ! $function[0] instanceof Inc2734\WP_GitHub_Plugin_Updater\Bootstrap ) {
					continue;
				}

				$reflection = new ReflectionProperty( $function[0], 'plugin_name' );
				$reflection->setAccessible( true );
				$registered_plugins[] = $reflection->getValue( $function[0] );
			}
		}

		sort( $registered_plugins );
		sort( $expected_plugins );

		$this->assertSame( $expected_plugins, $registered_plugins );
	}

	/**
	 * Read a Composer lock file.
	 *
	 * @return array<string,mixed>
	 */
	private function read_composer_lock( string $file ): array {
		$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertNotFalse( $contents );

		$data = json_decode( $contents, true );
		$this->assertIsArray( $data );

		return $data;
	}

	/**
	 * Find a package version in decoded Composer lock data.
	 *
	 * @param array<string,mixed> $lock Composer lock data.
	 */
	private function find_package_version( array $lock, string $package_name ): string {
		foreach ( $lock['packages'] ?? array() as $package ) {
			if ( ( $package['name'] ?? '' ) === $package_name ) {
				return (string) ( $package['version'] ?? '' );
			}
		}

		return '';
	}
}
