<?php
/**
 * Plugin update collector.
 *
 * @package OD_Monitor_Agent
 */

namespace Olein\MonitorAgent\Collector;

final class PluginCollector {
	/**
	 * Collect installed plugin versions and cached update availability.
	 *
	 * @return list<array{file:string,name:string,current_version:string,latest_version:string,update_available:bool,active:bool}>
	 */
	public function collect(): array {
		if ( ! function_exists( 'get_plugins' ) || ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$updates  = get_site_transient( 'update_plugins' );
		$response = is_object( $updates ) && isset( $updates->response ) && is_array( $updates->response )
			? $updates->response
			: array();
		$plugins  = array();

		foreach ( get_plugins() as $file => $plugin ) {
			$current_version = isset( $plugin['Version'] ) && is_string( $plugin['Version'] ) ? $plugin['Version'] : '';
			$latest_version  = $current_version;
			$offer           = $response[ $file ] ?? null;

			if ( is_object( $offer ) && isset( $offer->new_version ) && is_string( $offer->new_version ) && '' !== $offer->new_version ) {
				$latest_version = version_compare( $offer->new_version, $current_version, '>' ) ? $offer->new_version : $current_version;
			}

			$plugins[] = array(
				'file'             => (string) $file,
				'name'             => isset( $plugin['Name'] ) && is_string( $plugin['Name'] ) ? $plugin['Name'] : '',
				'current_version'  => $current_version,
				'latest_version'   => $latest_version,
				'update_available' => version_compare( $latest_version, $current_version, '>' ),
				'active'           => is_plugin_active( $file ),
			);
		}

		return $plugins;
	}
}
