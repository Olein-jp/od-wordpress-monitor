<?php
/**
 * Monitoring dashboard administration page.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Admin;

use Olein\WordPressMonitor\Scheduler\SchedulerHeartbeat;

final class DashboardPage {
	public function __construct(
		private readonly StatusOverview $overview,
		private readonly SchedulerHeartbeat $heartbeat
	) {
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to view the monitoring dashboard.', 'od-wordpress-monitor' ) );
		}

		$summary = $this->overview->snapshot()['summary'];
		$jobs    = $this->heartbeat->snapshot();
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

			<h2><?php echo esc_html__( 'Scheduler Health', 'od-wordpress-monitor' ); ?></h2>
			<p><?php echo esc_html__( 'A stale job has missed at least two expected execution intervals or is no longer scheduled.', 'od-wordpress-monitor' ); ?></p>
			<table class="widefat striped">
				<caption class="screen-reader-text"><?php echo esc_html__( 'Scheduler job health and latest execution', 'od-wordpress-monitor' ); ?></caption>
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html__( 'Job', 'od-wordpress-monitor' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Health', 'od-wordpress-monitor' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Last started', 'od-wordpress-monitor' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Last completed', 'od-wordpress-monitor' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Last result', 'od-wordpress-monitor' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Processed', 'od-wordpress-monitor' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Next run', 'od-wordpress-monitor' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $jobs as $job ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $this->job_label( $job['job'] ) ); ?></th>
							<td><strong><?php echo esc_html( $this->health_label( $job['health'] ) ); ?></strong></td>
							<td><?php $this->render_timestamp( $job['last_started_at'] ); ?></td>
							<td><?php $this->render_timestamp( $job['last_completed_at'] ); ?></td>
							<td><?php echo esc_html( $this->result_label( $job['result'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $job['processed'] ) ); ?></td>
							<td><?php $this->render_timestamp( $job['next_run'] ); ?></td>
						</tr>
					<?php endforeach; ?>
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

	private function job_label( string $job ): string {
		return match ( $job ) {
			'http'         => __( 'HTTP', 'od-wordpress-monitor' ),
			'agent_ping'   => __( 'Agent ping', 'od-wordpress-monitor' ),
			'agent_status' => __( 'Agent status', 'od-wordpress-monitor' ),
			'updates'      => __( 'Updates', 'od-wordpress-monitor' ),
			'site_health'  => __( 'Site Health', 'od-wordpress-monitor' ),
			'ssl'          => __( 'SSL', 'od-wordpress-monitor' ),
			'cleanup'      => __( 'Check history cleanup', 'od-wordpress-monitor' ),
			default        => __( 'Unknown job', 'od-wordpress-monitor' ),
		};
	}

	private function health_label( string $health ): string {
		return SchedulerHeartbeat::HEALTH_HEALTHY === $health
			? __( 'Healthy', 'od-wordpress-monitor' )
			: __( 'Stale', 'od-wordpress-monitor' );
	}

	private function result_label( string $result ): string {
		return match ( $result ) {
			SchedulerHeartbeat::RESULT_RUNNING => __( 'Running', 'od-wordpress-monitor' ),
			SchedulerHeartbeat::RESULT_SUCCESS => __( 'Succeeded', 'od-wordpress-monitor' ),
			SchedulerHeartbeat::RESULT_FAILED  => __( 'Failed', 'od-wordpress-monitor' ),
			default                            => __( 'Not run', 'od-wordpress-monitor' ),
		};
	}

	private function render_timestamp( ?int $timestamp ): void {
		if ( null === $timestamp ) {
			echo esc_html( '—' );
			return;
		}

		$format = sprintf( '%s %s', get_option( 'date_format' ), get_option( 'time_format' ) );
		printf(
			'<time datetime="%1$s">%2$s</time>',
			esc_attr( gmdate( DATE_ATOM, $timestamp ) ),
			esc_html( wp_date( $format, $timestamp ) )
		);
	}
}
