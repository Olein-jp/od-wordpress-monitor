<?php
/**
 * Agent composition root.
 *
 * @package OD_Monitor_Agent
 */

namespace Olein\MonitorAgent;

use Olein\MonitorAgent\Collector\ServerCollector;
use Olein\MonitorAgent\Collector\SiteCollector;
use Olein\MonitorAgent\Collector\PluginCollector;
use Olein\MonitorAgent\Collector\ThemeCollector;
use Olein\MonitorAgent\Collector\UpdateCollector;
use Olein\MonitorAgent\Collector\WordPressCollector;
use Olein\MonitorAgent\Rest\PingController;
use Olein\MonitorAgent\Rest\StatusController;
use Olein\MonitorAgent\Rest\UpdatesController;

final class Plugin {
	/**
	 * Register Agent hooks.
	 */
	public function register_hooks(): void {
		$ping_controller    = new PingController();
		$status_controller  = new StatusController(
			new SiteCollector(),
			new WordPressCollector(),
			new ServerCollector()
		);
		$updates_controller = new UpdatesController(
			new UpdateCollector( new PluginCollector(), new ThemeCollector() )
		);

		add_action( 'rest_api_init', array( $ping_controller, 'register_routes' ) );
		add_action( 'rest_api_init', array( $status_controller, 'register_routes' ) );
		add_action( 'rest_api_init', array( $updates_controller, 'register_routes' ) );
	}
}
