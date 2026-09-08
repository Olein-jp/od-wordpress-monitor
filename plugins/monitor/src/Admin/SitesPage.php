<?php
/**
 * Sites list administration page.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Admin;

use Olein\WordPressMonitor\Site\SiteRepository;
use Olein\WordPressMonitor\Site\SiteService;

final class SitesPage {
	public function __construct(
		private readonly SiteRepository $repository,
		private readonly SiteService $service
	) {
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage monitored sites.', 'od-wordpress-monitor' ) );
		}

		$this->render_notice();
		$sites = $this->repository->all();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html__( 'Sites', 'od-wordpress-monitor' ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=od-wordpress-monitor-add' ) ); ?>"><?php echo esc_html__( 'Add Site', 'od-wordpress-monitor' ); ?></a>
			<hr class="wp-header-end">
			<table class="widefat striped">
				<thead><tr>
					<th><?php echo esc_html__( 'Name', 'od-wordpress-monitor' ); ?></th>
					<th><?php echo esc_html__( 'Site URL', 'od-wordpress-monitor' ); ?></th>
					<th><?php echo esc_html__( 'Connection', 'od-wordpress-monitor' ); ?></th>
					<th><?php echo esc_html__( 'WordPress', 'od-wordpress-monitor' ); ?></th>
					<th><?php echo esc_html__( 'PHP', 'od-wordpress-monitor' ); ?></th>
					<th><?php echo esc_html__( 'Agent', 'od-wordpress-monitor' ); ?></th>
					<th><?php echo esc_html__( 'Actions', 'od-wordpress-monitor' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( array() === $sites ) : ?>
					<tr><td colspan="7"><?php echo esc_html__( 'No sites have been added.', 'od-wordpress-monitor' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $sites as $site ) : ?>
						<?php $status = $this->service->cached_status( $site ); ?>
						<tr>
							<td><?php echo esc_html( $site->name() ); ?></td>
							<td><a href="<?php echo esc_url( $site->site_url() ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $site->site_url() ); ?></a></td>
							<td><?php echo esc_html( is_array( $status ) ? __( 'Connected', 'od-wordpress-monitor' ) : __( 'Not tested', 'od-wordpress-monitor' ) ); ?></td>
							<td><?php echo esc_html( is_array( $status ) ? $status['wordpress']['version'] : '—' ); ?></td>
							<td><?php echo esc_html( is_array( $status ) ? $status['server']['php_version'] : '—' ); ?></td>
							<td><?php echo esc_html( is_array( $status ) ? $status['agent']['version'] : '—' ); ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="odm_test_connection">
									<input type="hidden" name="site_id" value="<?php echo esc_attr( (string) $site->id() ); ?>">
									<?php wp_nonce_field( 'odm_test_connection_' . $site->id() ); ?>
									<?php submit_button( __( 'Test Connection', 'od-wordpress-monitor' ), 'secondary small', 'submit', false ); ?>
								</form>
							</td>
						</tr>
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
		wp_safe_redirect( admin_url( 'admin.php?page=od-wordpress-monitor' ) );
		exit;
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
