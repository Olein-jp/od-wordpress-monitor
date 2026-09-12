<?php
/**
 * Notification settings administration page.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Admin;

use InvalidArgumentException;
use Olein\WordPressMonitor\Notification\ChatworkNotifier;
use Olein\WordPressMonitor\Notification\DiscordNotifier;
use Olein\WordPressMonitor\Notification\NotificationChannelSettings;
use Olein\WordPressMonitor\Notification\NotificationSettings;
use Olein\WordPressMonitor\Notification\NotificationTestService;
use Olein\WordPressMonitor\Notification\SlackNotifier;

final class NotificationSettingsPage {
	public const SLUG = 'od-wordpress-monitor-notifications';

	public function __construct(
		private readonly NotificationSettings $settings,
		private readonly ?NotificationChannelSettings $channel_settings = null,
		private readonly ?NotificationTestService $test_service = null
	) {
	}

	public function register_settings(): void {
		$this->settings->register();
		$this->channel_settings?->register();
		if ( null !== $this->channel_settings ) {
			add_settings_section( 'odm_notification_rules_section', __( 'Notification rules', 'od-wordpress-monitor' ), array( $this, 'render_rules_section' ), self::SLUG );
			add_settings_field( 'odm_ssl_warning', __( 'SSL expiration warning', 'od-wordpress-monitor' ), array( $this, 'render_ssl_warning_field' ), self::SLUG, 'odm_notification_rules_section' );
			add_settings_field( 'odm_updates_digest', __( 'Available updates', 'od-wordpress-monitor' ), array( $this, 'render_updates_digest_field' ), self::SLUG, 'odm_notification_rules_section' );
			add_settings_field( 'odm_site_health_digest', __( 'Site Health recommendations', 'od-wordpress-monitor' ), array( $this, 'render_site_health_digest_field' ), self::SLUG, 'odm_notification_rules_section' );
			add_settings_field( 'odm_digest_hour', __( 'Daily digest time', 'od-wordpress-monitor' ), array( $this, 'render_digest_hour_field' ), self::SLUG, 'odm_notification_rules_section' );
		}
		add_settings_section( 'odm_notifications_section', __( 'Email notifications', 'od-wordpress-monitor' ), array( $this, 'render_section' ), self::SLUG );
		add_settings_field( 'odm_notifications_enabled', __( 'Notifications', 'od-wordpress-monitor' ), array( $this, 'render_enabled_field' ), self::SLUG, 'odm_notifications_section' );
		add_settings_field( 'odm_notification_email', __( 'Notification email', 'od-wordpress-monitor' ), array( $this, 'render_email_field' ), self::SLUG, 'odm_notifications_section' );

		if ( null !== $this->channel_settings ) {
			$this->register_webhook_section( SlackNotifier::CHANNEL_ID, __( 'Slack notifications', 'od-wordpress-monitor' ) );
			$this->register_webhook_section( DiscordNotifier::CHANNEL_ID, __( 'Discord notifications', 'od-wordpress-monitor' ) );
			$this->register_chatwork_section();
		}
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage notification settings.', 'od-wordpress-monitor' ) );
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Notification Settings', 'od-wordpress-monitor' ); ?></h1>
			<?php $this->render_test_notice(); ?>
			<?php settings_errors(); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
				<?php settings_fields( NotificationSettings::GROUP ); ?>
				<?php do_settings_sections( self::SLUG ); ?>
				<?php submit_button(); ?>
			</form>
			<?php $this->render_test_forms(); ?>
		</div>
		<?php
	}

	public function handle_test(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to test notification settings.', 'od-wordpress-monitor' ), '', array( 'response' => 403 ) );
		}

		$channel_id = isset( $_POST['channel'] ) ? sanitize_key( wp_unslash( $_POST['channel'] ) ) : '';
		if ( ! in_array( $channel_id, $this->test_channels(), true ) ) {
			wp_die( esc_html__( 'The notification channel is invalid.', 'od-wordpress-monitor' ), '', array( 'response' => 400 ) );
		}

		check_admin_referer( 'odm_test_notification_' . $channel_id );

		try {
			$result = null === $this->test_service ? null : $this->test_service->send( $channel_id );
		} catch ( InvalidArgumentException $exception ) {
			unset( $exception );
			$result = null;
		}

		$notice = null !== $result && $result->succeeded()
			? array(
				'type'    => 'success',
				'channel' => $channel_id,
			)
			: array(
				'type'    => 'error',
				'channel' => $channel_id,
			);
		set_transient( 'odm_notification_notice_' . get_current_user_id(), $notice, MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	public function render_section(): void {
		echo '<p>' . esc_html__( 'Configure the global recipient for monitoring outage and recovery notifications.', 'od-wordpress-monitor' ) . '</p>';
	}

	public function render_rules_section(): void {
		echo '<p>' . esc_html__( 'These rules apply globally to every enabled notification channel. Daily digests include only newly detected or changed warning details; unchanged warnings are not repeated.', 'od-wordpress-monitor' ) . '</p>';
	}

	public function render_ssl_warning_field(): void {
		$this->render_rule_checkbox( 'ssl-warning', 'ssl_warning', $this->channel_settings?->ssl_warning_enabled() ?? true, __( 'Notify once when a healthy SSL certificate enters the warning period.', 'od-wordpress-monitor' ) );
	}

	public function render_updates_digest_field(): void {
		$this->render_rule_checkbox( 'updates-digest', 'updates_digest', $this->channel_settings?->updates_digest_enabled() ?? false, __( 'Include newly detected or changed available updates in the daily digest.', 'od-wordpress-monitor' ) );
	}

	public function render_site_health_digest_field(): void {
		$this->render_rule_checkbox( 'site-health-digest', 'site_health_recommended_digest', $this->channel_settings?->site_health_digest_enabled() ?? false, __( 'Include newly detected or changed Site Health recommendations in the daily digest.', 'od-wordpress-monitor' ) );
	}

	public function render_digest_hour_field(): void {
		$hour = $this->channel_settings?->digest_hour() ?? 9;
		?>
		<label for="odm-digest-hour" class="screen-reader-text"><?php echo esc_html__( 'Daily digest hour', 'od-wordpress-monitor' ); ?></label>
		<select id="odm-digest-hour" name="<?php echo esc_attr( NotificationChannelSettings::OPTION ); ?>[rules][digest_hour]">
			<?php for ( $candidate = 0; $candidate < 24; ++$candidate ) : ?>
				<option value="<?php echo esc_attr( (string) $candidate ); ?>" <?php selected( $hour, $candidate ); ?>><?php echo esc_html( sprintf( '%02d:00', $candidate ) ); ?></option>
			<?php endfor; ?>
		</select>
		<p class="description"><?php echo esc_html__( 'Uses the timezone configured in WordPress. WP-Cron sends the digest on the first run during the selected hour.', 'od-wordpress-monitor' ); ?></p>
		<?php
	}

	public function render_enabled_field(): void {
		?>
		<label for="odm-notifications-enabled">
			<input id="odm-notifications-enabled" name="<?php echo esc_attr( NotificationSettings::OPTION ); ?>[enabled]" type="checkbox" value="1" <?php checked( $this->settings->enabled() ); ?>>
			<?php echo esc_html__( 'Enable email notifications', 'od-wordpress-monitor' ); ?>
		</label>
		<?php
	}

	public function render_email_field(): void {
		?>
		<input class="regular-text" id="odm-notification-email" name="<?php echo esc_attr( NotificationSettings::OPTION ); ?>[email]" type="email" value="<?php echo esc_attr( $this->settings->email() ); ?>" autocomplete="email">
		<p class="description"><?php echo esc_html__( 'Notifications remain suppressed until a valid email address is saved.', 'od-wordpress-monitor' ); ?></p>
		<?php
	}

	/**
	 * @param array{channel:string} $args Field arguments.
	 */
	public function render_webhook_field( array $args ): void {
		if ( null === $this->channel_settings ) {
			return;
		}

		$channel_id = $args['channel'];
		$label      = ucfirst( $channel_id );
		$option     = NotificationChannelSettings::OPTION . '[' . $channel_id . ']';
		$configured = $this->channel_settings->has_webhook( $channel_id );
		?>
		<fieldset>
			<legend class="screen-reader-text"><?php echo esc_html( $label ); ?></legend>
			<label for="odm-<?php echo esc_attr( $channel_id ); ?>-enabled">
				<input id="odm-<?php echo esc_attr( $channel_id ); ?>-enabled" name="<?php echo esc_attr( $option ); ?>[enabled]" type="checkbox" value="1" <?php checked( $this->channel_settings->enabled( $channel_id ) ); ?>>
				<?php echo esc_html( sprintf( /* translators: %s: service name. */ __( 'Enable %s notifications', 'od-wordpress-monitor' ), $label ) ); ?>
			</label>
			<p>
				<label for="odm-<?php echo esc_attr( $channel_id ); ?>-webhook"><?php echo esc_html__( 'Webhook URL', 'od-wordpress-monitor' ); ?></label><br>
				<input class="regular-text" id="odm-<?php echo esc_attr( $channel_id ); ?>-webhook" name="<?php echo esc_attr( $option ); ?>[webhook_url]" type="password" value="" autocomplete="new-password">
			</p>
			<p class="description"><?php echo esc_html( $configured ? __( 'Configured. Leave blank to keep the saved webhook.', 'od-wordpress-monitor' ) : __( 'Not configured.', 'od-wordpress-monitor' ) ); ?></p>
			<?php if ( $configured ) : ?>
				<label><input name="<?php echo esc_attr( $option ); ?>[delete]" type="checkbox" value="1"> <?php echo esc_html__( 'Delete the saved webhook', 'od-wordpress-monitor' ); ?></label>
			<?php endif; ?>
		</fieldset>
		<?php
	}

	public function render_chatwork_field(): void {
		if ( null === $this->channel_settings ) {
			return;
		}

		$channel_id = ChatworkNotifier::CHANNEL_ID;
		$option     = NotificationChannelSettings::OPTION . '[' . $channel_id . ']';
		$configured = $this->channel_settings->has_api_token();
		?>
		<fieldset>
			<legend class="screen-reader-text"><?php echo esc_html__( 'Chatwork settings', 'od-wordpress-monitor' ); ?></legend>
			<label for="odm-chatwork-enabled">
				<input id="odm-chatwork-enabled" name="<?php echo esc_attr( $option ); ?>[enabled]" type="checkbox" value="1" <?php checked( $this->channel_settings->enabled( $channel_id ) ); ?>>
				<?php echo esc_html__( 'Enable Chatwork notifications', 'od-wordpress-monitor' ); ?>
			</label>
			<p>
				<label for="odm-chatwork-room-id"><?php echo esc_html__( 'Room ID', 'od-wordpress-monitor' ); ?></label><br>
				<input class="regular-text" id="odm-chatwork-room-id" inputmode="numeric" name="<?php echo esc_attr( $option ); ?>[room_id]" pattern="[1-9][0-9]*" type="text" value="<?php echo esc_attr( $this->channel_settings->room_id() ); ?>">
			</p>
			<p>
				<label for="odm-chatwork-api-token"><?php echo esc_html__( 'API token', 'od-wordpress-monitor' ); ?></label><br>
				<input class="regular-text" id="odm-chatwork-api-token" name="<?php echo esc_attr( $option ); ?>[api_token]" type="password" value="" autocomplete="new-password">
			</p>
			<p class="description"><?php echo esc_html( $configured ? __( 'Configured. Leave blank to keep the saved API token.', 'od-wordpress-monitor' ) : __( 'API token is not configured.', 'od-wordpress-monitor' ) ); ?></p>
			<?php if ( $configured ) : ?>
				<label><input name="<?php echo esc_attr( $option ); ?>[delete]" type="checkbox" value="1"> <?php echo esc_html__( 'Delete the saved API token', 'od-wordpress-monitor' ); ?></label>
			<?php endif; ?>
		</fieldset>
		<?php
	}

	private function register_webhook_section( string $channel_id, string $title ): void {
		$section_id = 'odm_' . $channel_id . '_notifications_section';
		add_settings_section( $section_id, $title, '__return_null', self::SLUG );
		add_settings_field( 'odm_' . $channel_id . '_webhook', $title, array( $this, 'render_webhook_field' ), self::SLUG, $section_id, array( 'channel' => $channel_id ) );
	}

	private function render_rule_checkbox( string $id, string $key, bool $checked, string $label ): void {
		?>
		<label for="odm-<?php echo esc_attr( $id ); ?>">
			<input id="odm-<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( NotificationChannelSettings::OPTION ); ?>[rules][<?php echo esc_attr( $key ); ?>]" type="checkbox" value="1" <?php checked( $checked ); ?>>
			<?php echo esc_html( $label ); ?>
		</label>
		<?php
	}

	private function register_chatwork_section(): void {
		$section_id = 'odm_chatwork_notifications_section';
		$title      = __( 'Chatwork notifications', 'od-wordpress-monitor' );
		add_settings_section( $section_id, $title, '__return_null', self::SLUG );
		add_settings_field( 'odm_chatwork_credentials', $title, array( $this, 'render_chatwork_field' ), self::SLUG, $section_id );
	}

	private function render_test_forms(): void {
		if ( null === $this->test_service || null === $this->channel_settings ) {
			return;
		}

		foreach ( $this->test_channels() as $channel_id ) {
			$configured = ChatworkNotifier::CHANNEL_ID === $channel_id
				? '' !== $this->channel_settings->room_id() && $this->channel_settings->has_api_token()
				: $this->channel_settings->has_webhook( $channel_id );
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input name="action" type="hidden" value="odm_test_notification">
				<input name="channel" type="hidden" value="<?php echo esc_attr( $channel_id ); ?>">
				<?php wp_nonce_field( 'odm_test_notification_' . $channel_id ); ?>
				<?php submit_button( sprintf( /* translators: %s: service name. */ __( 'Send %s test notification', 'od-wordpress-monitor' ), ucfirst( $channel_id ) ), 'secondary', 'submit', false, array( 'disabled' => ! $configured ) ); ?>
			</form>
			<?php
		}
	}

	/**
	 * @return list<string>
	 */
	private function test_channels(): array {
		return array( SlackNotifier::CHANNEL_ID, DiscordNotifier::CHANNEL_ID, ChatworkNotifier::CHANNEL_ID );
	}

	private function render_test_notice(): void {
		$notice = get_transient( 'odm_notification_notice_' . get_current_user_id() );
		if ( ! is_array( $notice ) || ! isset( $notice['type'], $notice['channel'] ) ) {
			return;
		}

		delete_transient( 'odm_notification_notice_' . get_current_user_id() );
		$success = 'success' === $notice['type'];
		$message = $success
			? __( 'The test notification was sent.', 'od-wordpress-monitor' )
			: __( 'The test notification could not be sent. Check the saved notification settings and try again.', 'od-wordpress-monitor' );
		echo '<div class="notice notice-' . esc_attr( $success ? 'success' : 'error' ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}
}
