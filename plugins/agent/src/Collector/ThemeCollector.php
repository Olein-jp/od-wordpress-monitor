<?php
/**
 * Theme update collector.
 *
 * @package OD_Monitor_Agent
 */

namespace Olein\MonitorAgent\Collector;

final class ThemeCollector {
	/**
	 * Collect installed theme versions and cached update availability.
	 *
	 * @return list<array{stylesheet:string,name:string,current_version:string,latest_version:string,update_available:bool,active:bool}>
	 */
	public function collect(): array {
		$updates  = get_site_transient( 'update_themes' );
		$response = is_object( $updates ) && isset( $updates->response ) && is_array( $updates->response )
			? $updates->response
			: array();
		$themes   = array();
		$active   = get_stylesheet();

		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$current_version = (string) $theme->get( 'Version' );
			$latest_version  = $current_version;
			$offer           = $response[ $stylesheet ] ?? null;

			if ( is_array( $offer ) && isset( $offer['new_version'] ) && is_string( $offer['new_version'] ) && '' !== $offer['new_version'] ) {
				$latest_version = version_compare( $offer['new_version'], $current_version, '>' ) ? $offer['new_version'] : $current_version;
			}

			$themes[] = array(
				'stylesheet'       => (string) $stylesheet,
				'name'             => (string) $theme->get( 'Name' ),
				'current_version'  => $current_version,
				'latest_version'   => $latest_version,
				'update_available' => version_compare( $latest_version, $current_version, '>' ),
				'active'           => $active === $stylesheet,
			);
		}

		return $themes;
	}
}
