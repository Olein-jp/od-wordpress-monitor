<?php
/**
 * Monitor composition root.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor;

use Olein\WordPressMonitor\Activation\DatabaseMigrator;
use Olein\WordPressMonitor\Admin\AddSitePage;
use Olein\WordPressMonitor\Admin\Admin;
use Olein\WordPressMonitor\Admin\SitesPage;
use Olein\WordPressMonitor\Credential\CredentialEncryptor;
use Olein\WordPressMonitor\Credential\CredentialRepository;
use Olein\WordPressMonitor\Credential\CredentialService;
use Olein\WordPressMonitor\Http\AgentClient;
use Olein\WordPressMonitor\Http\HttpClient;
use Olein\WordPressMonitor\Protocol\ResponseValidator;
use Olein\WordPressMonitor\Site\SiteRepository;
use Olein\WordPressMonitor\Site\SiteService;
use Olein\WordPressMonitor\Support\UUID;
use RuntimeException;

final class Plugin {
	public function register_hooks(): void {
		add_action( 'admin_init', array( $this, 'maybe_upgrade_database' ) );

		if ( ! is_admin() ) {
			return;
		}

		try {
			global $wpdb;

			$sites       = new SiteRepository( $wpdb );
			$credentials = new CredentialService(
				new CredentialRepository( $wpdb ),
				new CredentialEncryptor()
			);
			$service     = new SiteService(
				$sites,
				$credentials,
				new AgentClient( new HttpClient(), new ResponseValidator() ),
				new UUID()
			);

			( new Admin( new SitesPage( $sites, $service ), new AddSitePage( $service ) ) )->register_hooks();
		} catch ( RuntimeException $exception ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'OD WordPress Monitor requires the sodium PHP extension to protect credentials.', 'od-wordpress-monitor' );
					echo '</p></div>';
				}
			);
		}
	}

	public function maybe_upgrade_database(): void {
		if ( DatabaseMigrator::VERSION === get_option( DatabaseMigrator::VERSION_OPTION ) ) {
			return;
		}

		global $wpdb;
		( new DatabaseMigrator( $wpdb ) )->migrate();
	}
}
