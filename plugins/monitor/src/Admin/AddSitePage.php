<?php
/**
 * Add Site administration page.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Admin;

use Olein\WordPressMonitor\Site\SiteService;

final class AddSitePage {
	public function __construct( private readonly SiteService $service ) {
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage monitored sites.', 'od-wordpress-monitor' ) );
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Add Site', 'od-wordpress-monitor' ); ?></h1>
			<p><?php echo esc_html__( 'Connect to a WordPress site running OD Monitor Agent.', 'od-wordpress-monitor' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="odm_add_site">
				<?php wp_nonce_field( 'odm_add_site' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="odm-site-name"><?php echo esc_html__( 'Site Name', 'od-wordpress-monitor' ); ?></label></th>
						<td><input class="regular-text" id="odm-site-name" name="site_name" type="text" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="odm-site-url"><?php echo esc_html__( 'Site URL', 'od-wordpress-monitor' ); ?></label></th>
						<td><input class="regular-text" id="odm-site-url" name="site_url" type="url" placeholder="https://example.com" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="odm-agent-username"><?php echo esc_html__( 'Agent Username', 'od-wordpress-monitor' ); ?></label></th>
						<td><input class="regular-text" id="odm-agent-username" name="agent_username" type="text" autocomplete="username" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="odm-application-password"><?php echo esc_html__( 'Application Password', 'od-wordpress-monitor' ); ?></label></th>
						<td><input class="regular-text" id="odm-application-password" name="application_password" type="password" autocomplete="new-password" required></td>
					</tr>
				</table>
				<?php submit_button( __( 'Connect and Add Site', 'od-wordpress-monitor' ) ); ?>
			</form>
		</div>
		<?php
	}

	public function handle_post(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage monitored sites.', 'od-wordpress-monitor' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'odm_add_site' );

		$name     = isset( $_POST['site_name'] ) ? sanitize_text_field( wp_unslash( $_POST['site_name'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		$site_url = isset( $_POST['site_url'] ) ? esc_url_raw( wp_unslash( $_POST['site_url'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		$username = isset( $_POST['agent_username'] ) ? sanitize_text_field( wp_unslash( $_POST['agent_username'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		$password = isset( $_POST['application_password'] ) ? (string) wp_unslash( $_POST['application_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.InputNotValidated

		$result   = $this->service->register( $name, $site_url, $username, $password );
		$password = '';

		if ( is_wp_error( $result ) ) {
			$this->set_notice( 'error', ErrorMessages::for_code( $result->get_error_code() ) );
			wp_safe_redirect( admin_url( 'admin.php?page=od-wordpress-monitor-add' ) );
			exit;
		}

		$status = $result['status'];
		$this->set_notice(
			'success',
			sprintf(
				/* translators: 1: Site name, 2: WordPress version, 3: PHP version, 4: Agent version. */
				__( 'Connected successfully. Site: %1$s — WordPress: %2$s — PHP: %3$s — Agent: %4$s', 'od-wordpress-monitor' ),
				$status['site']['name'],
				$status['wordpress']['version'],
				$status['server']['php_version'],
				$status['agent']['version']
			)
		);
		wp_safe_redirect( admin_url( 'admin.php?page=' . SitesPage::SLUG ) );
		exit;
	}

	private function set_notice( string $type, string $message ): void {
		set_transient(
			'odm_admin_notice_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
	}
}
