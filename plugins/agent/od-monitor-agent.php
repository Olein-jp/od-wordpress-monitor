<?php
/**
 * Plugin Name:       OD Monitor Agent
 * Description:       Exposes a secure, read-only status API for OD WordPress Monitor.
 * Version:           1.0.0
 * Requires at least: 6.8
 * Requires PHP:      8.1
 * Author:            Koji Kuno
 * Author URI:        https://olein-design.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       od-monitor-agent
 *
 * @package OD_Monitor_Agent
 */

defined( 'ABSPATH' ) || exit;

define( 'OD_MONITOR_AGENT_VERSION', '1.0.0' );

$od_monitor_agent_autoloader = __DIR__ . '/vendor/autoload.php';

if ( ! file_exists( $od_monitor_agent_autoloader ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'OD Monitor Agent requires its Composer dependencies. Run composer install in the plugin directory.', 'od-monitor-agent' );
			echo '</p></div>';
		}
	);
	return;
}

require_once $od_monitor_agent_autoloader;

register_activation_hook( __FILE__, array( Olein\MonitorAgent\Activation\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Olein\MonitorAgent\Activation\Deactivator::class, 'deactivate' ) );

( new Olein\MonitorAgent\Plugin() )->register_hooks();
