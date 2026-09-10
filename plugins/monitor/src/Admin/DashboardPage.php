<?php
/**
 * Monitoring dashboard administration page.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Admin;

final class DashboardPage {
	public function __construct( private readonly StatusOverview $overview ) {
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to view the monitoring dashboard.', 'od-wordpress-monitor' ) );
		}

		$summary = $this->overview->snapshot()['summary'];
		$items   = array(
			array(
				'label'  => __( 'Sites', 'od-wordpress-monitor' ),
				'count'  => $summary['total'],
				'filter' => 'all',
			),
			array(
				'label'  => __( 'Healthy', 'od-wordpress-monitor' ),
				'count'  => $summary['healthy'],
				'filter' => 'healthy',
			),
			array(
				'label'  => __( 'Attention', 'od-wordpress-monitor' ),
				'count'  => $summary['warning'],
				'filter' => 'warning',
			),
			array(
				'label'  => __( 'Problem', 'od-wordpress-monitor' ),
				'count'  => $summary['critical'],
				'filter' => 'critical',
			),
			array(
				'label'  => __( 'Unknown', 'od-wordpress-monitor' ),
				'count'  => $summary['unknown'],
				'filter' => 'unknown',
			),
		);
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Dashboard', 'od-wordpress-monitor' ); ?></h1>
			<p><?php echo esc_html__( 'Current status of all monitored sites.', 'od-wordpress-monitor' ); ?></p>
			<table class="widefat striped">
				<caption class="screen-reader-text"><?php echo esc_html__( 'Monitoring status summary', 'od-wordpress-monitor' ); ?></caption>
				<thead>
					<tr>
						<?php foreach ( $items as $item ) : ?>
							<th scope="col"><?php echo esc_html( $item['label'] ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<tr>
						<?php foreach ( $items as $item ) : ?>
							<td>
								<a href="<?php echo esc_url( $this->sites_url( $item['filter'] ) ); ?>">
									<?php echo esc_html( number_format_i18n( $item['count'] ) ); ?>
									<span class="screen-reader-text"><?php echo esc_html( $item['label'] ); ?></span>
								</a>
							</td>
						<?php endforeach; ?>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function sites_url( string $filter ): string {
		return add_query_arg(
			array(
				'page'   => SitesPage::SLUG,
				'status' => $filter,
			),
			admin_url( 'admin.php' )
		);
	}
}
