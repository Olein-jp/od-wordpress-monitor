<?php
/**
 * Notification settings administration page.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Admin;

use Olein\WordPressMonitor\Notification\NotificationSettings;

final class NotificationSettingsPage {
	public const SLUG = 'od-wordpress-monitor-notifications';

	public function __construct( private readonly NotificationSettings $settings ) {
	}

	public function register_settings(): void {
		$this->settings->register();
		add_settings_section(
			'odm_notifications_section',
			__( 'Email notifications', 'od-wordpress-monitor' ),
			array( $this, 'render_section' ),
			self::SLUG
		);
		add_settings_field(
			'odm_notifications_enabled',
			__( 'Notifications', 'od-wordpress-monitor' ),
			array( $this, 'render_enabled_field' ),
			self::SLUG,
			'odm_notifications_section'
		);
		add_settings_field(
			'odm_notification_email',
			__( 'Notification email', 'od-wordpress-monitor' ),
			array( $this, 'render_email_field' ),
			self::SLUG,
			'odm_notifications_section'
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage notification settings.', 'od-wordpress-monitor' ) );
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Notification Settings', 'od-wordpress-monitor' ); ?></h1>
			<?php settings_errors(); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
				<?php settings_fields( NotificationSettings::GROUP ); ?>
				<?php do_settings_sections( self::SLUG ); ?>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public function render_section(): void {
		echo '<p>' . esc_html__( 'Configure the global recipient for monitoring outage and recovery notifications.', 'od-wordpress-monitor' ) . '</p>';
	}

	public function render_enabled_field(): void {
		?>
		<label for="odm-notifications-enabled">
			<input
				id="odm-notifications-enabled"
				name="<?php echo esc_attr( NotificationSettings::OPTION ); ?>[enabled]"
				type="checkbox"
				value="1"
				<?php checked( $this->settings->enabled() ); ?>
			>
			<?php echo esc_html__( 'Enable email notifications', 'od-wordpress-monitor' ); ?>
		</label>
		<?php
	}

	public function render_email_field(): void {
		?>
		<input
			class="regular-text"
			id="odm-notification-email"
			name="<?php echo esc_attr( NotificationSettings::OPTION ); ?>[email]"
			type="email"
			value="<?php echo esc_attr( $this->settings->email() ); ?>"
			autocomplete="email"
		>
		<p class="description"><?php echo esc_html__( 'Notifications remain suppressed until a valid email address is saved.', 'od-wordpress-monitor' ); ?></p>
		<?php
	}
}
