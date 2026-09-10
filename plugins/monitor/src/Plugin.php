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
use Olein\WordPressMonitor\Admin\NotificationSettingsPage;
use Olein\WordPressMonitor\Admin\SitesPage;
use Olein\WordPressMonitor\Credential\CredentialEncryptor;
use Olein\WordPressMonitor\Credential\CredentialRepository;
use Olein\WordPressMonitor\Credential\CredentialService;
use Olein\WordPressMonitor\Check\CheckRepository;
use Olein\WordPressMonitor\Evaluation\CheckResultRecorder;
use Olein\WordPressMonitor\Evaluation\StateTransition;
use Olein\WordPressMonitor\Evaluation\StatusEvaluator;
use Olein\WordPressMonitor\Event\EventRepository;
use Olein\WordPressMonitor\Http\AgentClient;
use Olein\WordPressMonitor\Http\HttpClient;
use Olein\WordPressMonitor\Monitor\Monitoring\AgentPingMonitor;
use Olein\WordPressMonitor\Monitor\Monitoring\AgentStatusMonitor;
use Olein\WordPressMonitor\Monitor\Monitoring\HttpMonitor;
use Olein\WordPressMonitor\Monitor\Monitoring\SiteHealthMonitor;
use Olein\WordPressMonitor\Monitor\Monitoring\SslCertificateClient;
use Olein\WordPressMonitor\Monitor\Monitoring\SslMonitor;
use Olein\WordPressMonitor\Monitor\Monitoring\UpdateMonitor;
use Olein\WordPressMonitor\Notification\EmailNotifier;
use Olein\WordPressMonitor\Notification\NotificationManager;
use Olein\WordPressMonitor\Notification\NotificationRule;
use Olein\WordPressMonitor\Notification\NotificationSettings;
use Olein\WordPressMonitor\Protocol\ResponseValidator;
use Olein\WordPressMonitor\Scheduler\CheckLock;
use Olein\WordPressMonitor\Scheduler\CheckRetention;
use Olein\WordPressMonitor\Scheduler\CheckRunner;
use Olein\WordPressMonitor\Scheduler\Scheduler;
use Olein\WordPressMonitor\Site\SiteRepository;
use Olein\WordPressMonitor\Site\SiteService;
use Olein\WordPressMonitor\Status\SiteStatusRepository;
use Olein\WordPressMonitor\Support\UUID;
use RuntimeException;

final class Plugin {
	public function register_hooks(): void {
		add_filter( 'cron_schedules', array( Scheduler::class, 'add_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected,WordPress.WP.CronInterval.CronSchedulesInterval -- Intervals are defined by Scheduler; five minutes is required.
		add_action( 'admin_init', array( $this, 'maybe_upgrade_database' ) );
		add_action( 'init', array( $this, 'register_runtime_hooks' ), 0 );
	}

	/**
	 * Build runtime services after WordPress pluggable functions are available.
	 */
	public function register_runtime_hooks(): void {
		try {
			global $wpdb;

			$sites                 = new SiteRepository( $wpdb );
			$credentials           = new CredentialService(
				new CredentialRepository( $wpdb ),
				new CredentialEncryptor()
			);
			$http_client           = new HttpClient();
			$agent_client          = new AgentClient( $http_client, new ResponseValidator() );
			$checks                = new CheckRepository( $wpdb );
			$events                = new EventRepository( $wpdb );
			$notification_settings = new NotificationSettings();
			$notifications         = new NotificationManager(
				$notification_settings,
				new NotificationRule(),
				new EmailNotifier( $sites )
			);
			$recorder              = new CheckResultRecorder(
				$wpdb,
				$checks,
				new SiteStatusRepository( $wpdb ),
				$events,
				new StatusEvaluator(),
				new StateTransition(),
				$notifications
			);
			$scheduler             = new Scheduler(
				new CheckRunner(
					$sites,
					new CheckLock( $wpdb ),
					array(
						new HttpMonitor( $http_client ),
						new AgentPingMonitor( $agent_client, $credentials ),
						new AgentStatusMonitor( $agent_client, $credentials ),
						new UpdateMonitor( $agent_client, $credentials ),
						new SiteHealthMonitor( $agent_client, $credentials ),
						new SslMonitor( new SslCertificateClient() ),
					),
					$recorder
				),
				new CheckRetention( $checks )
			);
			$scheduler->register_hooks();

			if ( ! is_admin() ) {
				return;
			}

			$service = new SiteService(
				$sites,
				$credentials,
				$agent_client,
				new UUID()
			);

			( new Admin(
				new SitesPage( $sites, $service ),
				new AddSitePage( $service ),
				new NotificationSettingsPage( $notification_settings )
			) )->register_hooks();
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
