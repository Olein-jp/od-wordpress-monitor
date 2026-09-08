<?php
/**
 * Agent role management.
 *
 * @package OD_Monitor_Agent
 */

namespace Olein\MonitorAgent\Auth;

final class Role {
	public const NAME = 'od_monitor_agent';

	/**
	 * Create or repair the least-privilege Agent role.
	 */
	public function register(): void {
		$role = get_role( self::NAME );

		if ( null === $role ) {
			add_role(
				self::NAME,
				__( 'OD Monitor Agent', 'od-monitor-agent' ),
				array(
					'read'           => true,
					Capability::READ => true,
				)
			);
			return;
		}

		foreach ( array_keys( $role->capabilities ) as $capability ) {
			if ( ! in_array( $capability, array( 'read', Capability::READ ), true ) ) {
				$role->remove_cap( $capability );
			}
		}

		$role->add_cap( 'read' );
		$role->add_cap( Capability::READ );
	}
}
