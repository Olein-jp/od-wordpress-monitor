<?php
/**
 * Monitor administration hooks.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Admin;

final class Admin {
	public function __construct(
		private readonly DashboardPage $dashboard_page,
		private readonly SitesPage $sites_page,
		private readonly SiteDetailPage $site_detail_page,
		private readonly AddSitePage $add_site_page,
		private readonly NotificationSettingsPage $notification_settings_page
	) {
	}

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this->notification_settings_page, 'register_settings' ) );
		add_action( 'admin_post_odm_add_site', array( $this->add_site_page, 'handle_post' ) );
		add_action( 'admin_post_odm_test_connection', array( $this->sites_page, 'handle_test' ) );
		add_action( 'admin_post_odm_test_notification', array( $this->notification_settings_page, 'handle_test' ) );
	}

	public function register_menu(): void {
		add_menu_page(
			__( 'WordPress Monitor', 'od-wordpress-monitor' ),
			__( 'WordPress Monitor', 'od-wordpress-monitor' ),
			'manage_options',
			'od-wordpress-monitor',
			array( $this->dashboard_page, 'render' ),
			'dashicons-visibility'
		);

		add_submenu_page(
			'od-wordpress-monitor',
			__( 'Dashboard', 'od-wordpress-monitor' ),
			__( 'Dashboard', 'od-wordpress-monitor' ),
			'manage_options',
			'od-wordpress-monitor',
			array( $this->dashboard_page, 'render' )
		);

		add_submenu_page(
			'od-wordpress-monitor',
			__( 'Sites', 'od-wordpress-monitor' ),
			__( 'Sites', 'od-wordpress-monitor' ),
			'manage_options',
			SitesPage::SLUG,
			array( $this->sites_page, 'render' )
		);

		add_submenu_page(
			null,
			__( 'Site Details', 'od-wordpress-monitor' ),
			__( 'Site Details', 'od-wordpress-monitor' ),
			'manage_options',
			SiteDetailPage::SLUG,
			array( $this->site_detail_page, 'render' )
		);

		add_submenu_page(
			'od-wordpress-monitor',
			__( 'Add Site', 'od-wordpress-monitor' ),
			__( 'Add Site', 'od-wordpress-monitor' ),
			'manage_options',
			'od-wordpress-monitor-add',
			array( $this->add_site_page, 'render' )
		);

		add_submenu_page(
			'od-wordpress-monitor',
			__( 'Notification Settings', 'od-wordpress-monitor' ),
			__( 'Notifications', 'od-wordpress-monitor' ),
			'manage_options',
			NotificationSettingsPage::SLUG,
			array( $this->notification_settings_page, 'render' )
		);
	}
}
