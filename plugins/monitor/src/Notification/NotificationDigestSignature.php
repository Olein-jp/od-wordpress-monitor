<?php
/**
 * Stable signatures for daily digest content.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Notification;

final class NotificationDigestSignature {
	/**
	 * @param array<string|int,mixed> $metadata Sanitized current status metadata.
	 */
	public function updates( array $metadata ): ?string {
		$inventory = isset( $metadata['software_inventory'] ) && is_array( $metadata['software_inventory'] )
			? $metadata['software_inventory']
			: array();
		$items     = array();

		if ( isset( $inventory['theme'] ) && is_array( $inventory['theme'] ) ) {
			$this->add_update_item( $items, $inventory['theme'] );
		}

		if ( isset( $inventory['plugins'] ) && is_array( $inventory['plugins'] ) ) {
			foreach ( $inventory['plugins'] as $plugin ) {
				if ( is_array( $plugin ) ) {
					$this->add_update_item( $items, $plugin );
				}
			}
		}

		sort( $items, SORT_STRING );
		$core_updates = isset( $metadata['wordpress_updates'] ) && is_int( $metadata['wordpress_updates'] )
			? $metadata['wordpress_updates']
			: 0;

		if ( 0 === $core_updates && array() === $items ) {
			return null;
		}

		return hash(
			'sha256',
			(string) wp_json_encode(
				array(
					'core'  => $core_updates,
					'items' => $items,
				)
			)
		);
	}

	/**
	 * @param array<string|int,mixed> $metadata Sanitized current status metadata.
	 */
	public function site_health( array $metadata ): ?string {
		if ( ! isset( $metadata['issues'] ) || ! is_array( $metadata['issues'] ) ) {
			return null;
		}

		$items = array();
		foreach ( $metadata['issues'] as $issue ) {
			if (
				is_array( $issue )
				&& isset( $issue['id'], $issue['status'] )
				&& is_string( $issue['id'] )
				&& is_string( $issue['status'] )
				&& 'recommended' === $issue['status']
			) {
				$items[] = $issue['id'] . '|' . $issue['status'];
			}
		}

		sort( $items, SORT_STRING );

		return array() === $items ? null : hash( 'sha256', (string) wp_json_encode( $items ) );
	}

	/**
	 * @param list<string>            $items Normalized items.
	 * @param array<string|int,mixed> $item  Sanitized inventory item.
	 */
	private function add_update_item( array &$items, array $item ): void {
		if (
			true !== ( $item['update_available'] ?? false )
			|| ! isset( $item['id'], $item['current_version'], $item['latest_version'] )
		) {
			return;
		}

		$id      = is_string( $item['id'] ) ? $item['id'] : '';
		$current = is_string( $item['current_version'] ) ? $item['current_version'] : '';
		$latest  = isset( $item['latest_version'] ) && is_string( $item['latest_version'] ) ? $item['latest_version'] : '';

		if ( '' !== $id && '' !== $latest ) {
			$items[] = $id . '|' . $current . '|' . $latest;
		}
	}
}
