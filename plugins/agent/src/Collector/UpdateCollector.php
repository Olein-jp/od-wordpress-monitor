<?php
/**
 * WordPress update collector.
 *
 * @package OD_Monitor_Agent
 */

namespace Olein\MonitorAgent\Collector;

final class UpdateCollector {
	public function __construct(
		private readonly PluginCollector $plugin_collector,
		private readonly ThemeCollector $theme_collector
	) {
	}

	/**
	 * Collect cached WordPress, plugin, and theme update information.
	 *
	 * @return array<string,mixed>
	 */
	public function collect(): array {
		$wordpress        = $this->collect_wordpress();
		$plugins          = $this->plugin_collector->collect();
		$themes           = $this->theme_collector->collect();
		$summary          = array(
			'wordpress' => $wordpress['update_available'] ? 1 : 0,
			'plugins'   => count( array_filter( $plugins, static fn( array $plugin ): bool => $plugin['update_available'] ) ),
			'themes'    => count( array_filter( $themes, static fn( array $theme ): bool => $theme['update_available'] ) ),
		);
		$summary['total'] = array_sum( $summary );

		return array(
			'wordpress' => $wordpress,
			'plugins'   => $plugins,
			'themes'    => $themes,
			'summary'   => $summary,
		);
	}

	/**
	 * Normalize the cached WordPress core update offer.
	 *
	 * @return array{current_version:string,latest_version:string,update_available:bool}
	 */
	private function collect_wordpress(): array {
		global $wp_version;

		$current_version = (string) $wp_version;
		$latest_version  = $current_version;
		$updates         = get_site_transient( 'update_core' );
		$offers          = is_object( $updates ) && isset( $updates->updates ) && is_array( $updates->updates )
			? $updates->updates
			: array();

		foreach ( $offers as $offer ) {
			if (
				is_object( $offer ) &&
				isset( $offer->current ) &&
				is_string( $offer->current ) &&
				version_compare( $offer->current, $latest_version, '>' )
			) {
				$latest_version = $offer->current;
			}
		}

		return array(
			'current_version'  => $current_version,
			'latest_version'   => $latest_version,
			'update_available' => version_compare( $latest_version, $current_version, '>' ),
		);
	}
}
