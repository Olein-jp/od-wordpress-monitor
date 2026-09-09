<?php
/**
 * Updates endpoint tests.
 *
 * @package OD_Monitor_Agent
 */

namespace Olein\MonitorAgent\Tests;

use Olein\MonitorAgent\Collector\PluginCollector;
use Olein\MonitorAgent\Collector\ThemeCollector;
use Olein\MonitorAgent\Collector\UpdateCollector;
use Olein\MonitorAgent\Rest\UpdatesController;
use WP_REST_Request;

final class UpdatesControllerTest extends \WP_UnitTestCase {
	private UpdateCollector $collector;
	/** @var list<string> */
	private array $active_plugins;

	public function set_up(): void {
		parent::set_up();
		$this->collector      = new UpdateCollector( new PluginCollector(), new ThemeCollector() );
		$this->active_plugins = get_option( 'active_plugins', array() );
	}

	public function tear_down(): void {
		delete_site_transient( 'update_core' );
		delete_site_transient( 'update_plugins' );
		delete_site_transient( 'update_themes' );
		update_option( 'active_plugins', $this->active_plugins );
		parent::tear_down();
	}

	public function test_updates_contains_versions_active_state_and_summary(): void {
		global $wp_version;

		$plugin_file    = 'agent/od-monitor-agent.php';
		$plugin_version = get_plugins()[ $plugin_file ]['Version'];
		$stylesheet     = get_stylesheet();
		$theme_version  = (string) wp_get_theme( $stylesheet )->get( 'Version' );
		update_option( 'active_plugins', array_unique( array_merge( $this->active_plugins, array( $plugin_file ) ) ) );

		set_site_transient( 'update_core', (object) array( 'updates' => array( (object) array( 'current' => $wp_version . '.1' ) ) ) );
		set_site_transient( 'update_plugins', (object) array( 'response' => array( $plugin_file => (object) array( 'new_version' => $plugin_version . '.1' ) ) ) );
		set_site_transient( 'update_themes', (object) array( 'response' => array( $stylesheet => array( 'new_version' => $theme_version . '.1' ) ) ) );

		$controller = new UpdatesController( $this->collector );
		$data       = $controller->get_item( new WP_REST_Request( 'GET', '/od-monitor-agent/v1/updates' ) )->get_data();
		$plugin     = $this->find_item( $data['plugins'], 'file', $plugin_file );
		$theme      = $this->find_item( $data['themes'], 'stylesheet', $stylesheet );

		$this->assertSame( '1.0', $data['schema_version'] );
		$this->assertTrue( $data['wordpress']['update_available'] );
		$this->assertTrue( $plugin['update_available'] );
		$this->assertTrue( $plugin['active'] );
		$this->assertTrue( $theme['update_available'] );
		$this->assertTrue( $theme['active'] );
		$this->assertSame( 1, $data['summary']['wordpress'] );
		$this->assertSame( 1, $data['summary']['plugins'] );
		$this->assertSame( 1, $data['summary']['themes'] );
		$this->assertSame( 3, $data['summary']['total'] );
	}

	public function test_missing_or_stale_transients_are_normalized_safely(): void {
		set_site_transient(
			'update_core',
			(object) array(
				'last_checked' => 1,
				'updates'      => array(),
			)
		);
		set_site_transient(
			'update_plugins',
			(object) array(
				'last_checked' => 1,
				'response'     => 'invalid',
			)
		);
		set_site_transient( 'update_themes', (object) array( 'last_checked' => 1 ) );

		$data = $this->collector->collect();

		$this->assertFalse( $data['wordpress']['update_available'] );
		$this->assertSame( $data['wordpress']['current_version'], $data['wordpress']['latest_version'] );
		$this->assertSame( 0, $data['summary']['total'] );
	}

	/**
	 * Find one collected item by identifier.
	 *
	 * @param list<array<string,mixed>> $items Collected items.
	 * @return array<string,mixed>
	 */
	private function find_item( array $items, string $key, string $value ): array {
		foreach ( $items as $item ) {
			if ( $value === $item[ $key ] ) {
				return $item;
			}
		}

		$this->fail( 'Expected update item was not collected.' );
	}
}
