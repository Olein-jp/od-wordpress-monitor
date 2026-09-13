<?php
/**
 * Plugin Name:       OD WordPress Monitor
 * Description:       Registers and checks WordPress sites running OD Monitor Agent.
 * Version:           1.0.11
 * Requires at least: 6.8
 * Requires PHP:      8.1
 * Update URI:        https://github.com/Olein-jp/od-wordpress-monitor-release
 * Author:            Koji Kuno
 * Author URI:        https://olein-design.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       od-wordpress-monitor
 * Domain Path:       /languages
 *
 * @package OD_WordPress_Monitor
 */

defined( 'ABSPATH' ) || exit;

define( 'OD_WORDPRESS_MONITOR_VERSION', '1.0.11' );

/**
 * Load the bundled translations before plugin services use translatable strings.
 */
function od_wordpress_monitor_load_textdomain(): void {
	load_plugin_textdomain(
		'od-wordpress-monitor',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);
}

add_action( 'plugins_loaded', 'od_wordpress_monitor_load_textdomain', -100 );

$od_wordpress_monitor_autoloader = __DIR__ . '/vendor/autoload.php';

if ( ! file_exists( $od_wordpress_monitor_autoloader ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'OD WordPress Monitor requires its Composer dependencies. Run composer install in the plugin directory.', 'od-wordpress-monitor' );
			echo '</p></div>';
		}
	);
	return;
}

require_once $od_wordpress_monitor_autoloader;

new Inc2734\WP_GitHub_Plugin_Updater\Bootstrap(
	plugin_basename( __FILE__ ),
	'Olein-jp',
	'od-wordpress-monitor-release',
	array(
		'requires'     => '6.8',
		'requires_php' => '8.1',
	)
);

register_activation_hook( __FILE__, array( Olein\WordPressMonitor\Activation\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Olein\WordPressMonitor\Activation\Activator::class, 'deactivate' ) );

( new Olein\WordPressMonitor\Plugin() )->register_hooks();
