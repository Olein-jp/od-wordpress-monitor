<?php
/**
 * Sites list administration page.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Admin;

use DateTimeImmutable;
use Olein\WordPressMonitor\Site\Site;
use Olein\WordPressMonitor\Site\SiteService;
use Olein\WordPressMonitor\Status\SiteStatus;

final class SitesPage {
	public const SLUG = 'od-wordpress-monitor-sites';

	public function __construct(
		private readonly StatusOverview $overview,
		private readonly SiteService $service
	) {
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage monitored sites.', 'od-wordpress-monitor' ) );
		}

		$requested_filter = isset( $_GET['status'] ) && is_string( $_GET['status'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
			? sanitize_key( wp_unslash( $_GET['status'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
			: StatusOverview::FILTER_ALL;
		$snapshot         = $this->overview->snapshot( $requested_filter );

		$this->render_notice();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html__( 'Sites', 'od-wordpress-monitor' ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=od-wordpress-monitor-add' ) ); ?>"><?php echo esc_html__( 'Add Site', 'od-wordpress-monitor' ); ?></a>
			<hr class="wp-header-end">
			<?php $this->render_filters( $snapshot['summary'], $snapshot['filter'] ); ?>
			<table class="widefat striped">
				<caption class="screen-reader-text"><?php echo esc_html__( 'Monitored sites and their current status', 'od-wordpress-monitor' ); ?></caption>
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html__( 'Site', 'od-wordpress-monitor' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Overall', 'od-wordpress-monitor' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'HTTP', 'od-wordpress-monitor' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Agent', 'od-wordpress-monitor' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Updates', 'od-wordpress-monitor' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Site Health', 'od-wordpress-monitor' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'SSL', 'od-wordpress-monitor' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Last Check', 'od-wordpress-monitor' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( array() === $snapshot['rows'] ) : ?>
					<tr><td colspan="8">
						<?php
						echo esc_html(
							0 === $snapshot['summary']['total']
								? __( 'No sites have been added.', 'od-wordpress-monitor' )
								: __( 'No sites match this status.', 'od-wordpress-monitor' )
						);
						?>
					</td></tr>
				<?php else : ?>
					<?php foreach ( $snapshot['rows'] as $row ) : ?>
						<?php $this->render_row( $row['site'], $row['status'], $row['overall'] ); ?>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public function handle_test(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage monitored sites.', 'od-wordpress-monitor' ), '', array( 'response' => 403 ) );
		}

		$site_id = isset( $_POST['site_id'] ) ? absint( $_POST['site_id'] ) : 0;
		check_admin_referer( 'odm_test_connection_' . $site_id );

		$result = $this->service->test_connection( $site_id );
		$notice = is_wp_error( $result )
			? array(
				'type'    => 'error',
				'message' => ErrorMessages::for_code( $result->get_error_code() ),
			)
			: array(
				'type'    => 'success',
				'message' => __( 'Connected successfully.', 'od-wordpress-monitor' ),
			);

		set_transient( 'odm_admin_notice_' . get_current_user_id(), $notice, MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	/**
	 * @param array{total:int,healthy:int,warning:int,critical:int,unknown:int} $summary Summary counts.
	 */
	private function render_filters( array $summary, string $active_filter ): void {
		$filters = array(
			'all'      => array( __( 'All', 'od-wordpress-monitor' ), $summary['total'] ),
			'critical' => array( __( 'Problem', 'od-wordpress-monitor' ), $summary['critical'] ),
			'warning'  => array( __( 'Attention', 'od-wordpress-monitor' ), $summary['warning'] ),
			'healthy'  => array( __( 'Healthy', 'od-wordpress-monitor' ), $summary['healthy'] ),
			'unknown'  => array( __( 'Unknown', 'od-wordpress-monitor' ), $summary['unknown'] ),
		);
		?>
		<ul class="subsubsub" aria-label="<?php echo esc_attr__( 'Filter sites by status', 'od-wordpress-monitor' ); ?>">
			<?php foreach ( $filters as $filter => $details ) : ?>
				<li class="<?php echo esc_attr( $filter ); ?>">
					<a href="<?php echo esc_url( $this->filter_url( $filter ) ); ?>"<?php echo $filter === $active_filter ? ' class="current" aria-current="page"' : ''; ?>>
						<?php echo esc_html( $details[0] ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( $details[1] ) ); ?>)</span>
					</a><?php echo 'unknown' === $filter ? '' : ' |'; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	private function render_row( Site $site, ?SiteStatus $status, string $overall ): void {
		?>
		<tr>
			<td>
				<strong><a href="<?php echo esc_url( $this->detail_url( $site ) ); ?>"><?php echo esc_html( $site->name() ); ?></a></strong>
				<br><a href="<?php echo esc_url( $site->site_url() ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $site->site_url() ); ?></a>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="odm_test_connection">
					<input type="hidden" name="site_id" value="<?php echo esc_attr( (string) $site->id() ); ?>">
					<?php wp_nonce_field( 'odm_test_connection_' . $site->id() ); ?>
					<?php submit_button( __( 'Test Connection', 'od-wordpress-monitor' ), 'small', 'submit', false ); ?>
				</form>
			</td>
			<td><strong><?php echo esc_html( StatusLabel::for_status( $overall ) ); ?></strong></td>
			<td><?php echo esc_html( StatusLabel::for_status( null === $status ? 'unknown' : $status->http_status() ) ); ?></td>
			<td><?php echo esc_html( StatusLabel::for_status( null === $status ? 'unknown' : $status->agent_status() ) ); ?></td>
			<td><?php echo esc_html( StatusLabel::for_status( null === $status ? 'unknown' : $status->updates_status() ) ); ?></td>
			<td><?php echo esc_html( StatusLabel::for_status( null === $status ? 'unknown' : $status->site_health_status() ) ); ?></td>
			<td><?php echo esc_html( StatusLabel::for_status( null === $status ? 'unknown' : $status->ssl_status() ) ); ?></td>
			<td><?php $this->render_date( null === $status ? null : $status->last_checked_at() ); ?></td>
		</tr>
		<?php
	}

	private function detail_url( Site $site ): string {
		return add_query_arg(
			array(
				'page'    => SiteDetailPage::SLUG,
				'site_id' => $site->id(),
			),
			admin_url( 'admin.php' )
		);
	}

	private function render_date( ?DateTimeImmutable $date ): void {
		if ( null === $date ) {
			echo esc_html( '—' );
			return;
		}

		$format = sprintf( '%s %s', get_option( 'date_format' ), get_option( 'time_format' ) );
		printf(
			'<time datetime="%1$s">%2$s</time>',
			esc_attr( $date->format( DATE_ATOM ) ),
			esc_html( wp_date( $format, $date->getTimestamp() ) )
		);
	}

	private function filter_url( string $filter ): string {
		return add_query_arg(
			array(
				'page'   => self::SLUG,
				'status' => $filter,
			),
			admin_url( 'admin.php' )
		);
	}

	private function render_notice(): void {
		$key    = 'odm_admin_notice_' . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! is_array( $notice ) || ! isset( $notice['type'], $notice['message'] ) ) {
			return;
		}

		delete_transient( $key );
		$class = 'success' === $notice['type'] ? 'notice-success' : 'notice-error';
		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( $notice['message'] )
		);
	}
}
