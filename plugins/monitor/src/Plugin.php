<?php
/**
 * Monitor composition root.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor;

use Closure;
use Olein\WordPressMonitor\Activation\DatabaseMigrator;
use Olein\WordPressMonitor\Admin\AddSitePage;
use Olein\WordPressMonitor\Admin\Admin;
use Olein\WordPressMonitor\Admin\DashboardPage;
use Olein\WordPressMonitor\Admin\NotificationSettingsPage;
use Olein\WordPressMonitor\Admin\SiteDetailPage;
use Olein\WordPressMonitor\Admin\SitesPage;
use Olein\WordPressMonitor\Admin\StatusOverview;
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
use Olein\WordPressMonitor\Http\UrlValidator;
use Olein\WordPressMonitor\Monitor\Monitoring\AgentPingMonitor;
use Olein\WordPressMonitor\Monitor\Monitoring\AgentStatusMonitor;
use Olein\WordPressMonitor\Monitor\Monitoring\HttpMonitor;
use Olein\WordPressMonitor\Monitor\Monitoring\SiteHealthMonitor;
use Olein\WordPressMonitor\Monitor\Monitoring\SslCertificateClient;
use Olein\WordPressMonitor\Monitor\Monitoring\SslMonitor;
use Olein\WordPressMonitor\Monitor\Monitoring\UpdateMonitor;
use Olein\WordPressMonitor\Notification\EmailNotifier;
use Olein\WordPressMonitor\Notification\NotificationManager;
use Olein\WordPressMonitor\Notification\NotificationMessageFactory;
use Olein\WordPressMonitor\Notification\NotificationRule;
use Olein\WordPressMonitor\Notification\NotificationSettings;
use Olein\WordPressMonitor\Protocol\ResponseValidator;
use Olein\WordPressMonitor\Scheduler\BatchScheduler;
use Olein\WordPressMonitor\Scheduler\CheckLock;
use Olein\WordPressMonitor\Scheduler\CheckRetention;
use Olein\WordPressMonitor\Scheduler\CheckRunner;
use Olein\WordPressMonitor\Scheduler\OptionCleanupRepository;
use Olein\WordPressMonitor\Scheduler\RetryScheduler;
use Olein\WordPressMonitor\Scheduler\Scheduler;
use Olein\WordPressMonitor\Scheduler\SchedulerHeartbeat;
use Olein\WordPressMonitor\Site\SiteRepository;
use Olein\WordPressMonitor\Site\SiteService;
use Olein\WordPressMonitor\Status\SiteStatusRepository;
use Olein\WordPressMonitor\Support\UUID;
use RuntimeException;
use WP_Error;

final class Plugin {
	private ?bool $database_ready = null;

	private ?WP_Error $database_migration_error = null;

	/**
	 * @param Closure|null $database_migrator_factory Optional migrator factory for tests.
	 */
	public function __construct( private readonly ?Closure $database_migrator_factory = null ) {
	}

	public function register_hooks(): void {
		add_filter( 'cron_schedules', array( Scheduler::class, 'add_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected,WordPress.WP.CronInterval.CronSchedulesInterval -- Intervals are defined by Scheduler; five minutes is required.
		add_action( 'plugins_loaded', array( $this, 'maybe_upgrade_database' ), 0 );
		add_action( 'init', array( $this, 'register_runtime_hooks' ), 0 );
	}

	/**
	 * Build runtime services after WordPress pluggable functions are available.
	 */
	public function register_runtime_hooks(): void {
		if ( null === $this->database_ready && ! $this->maybe_upgrade_database() ) {
			return;
		}

		if ( ! $this->database_ready ) {
			return;
		}

		try {
			global $wpdb;

			$sites                 = new SiteRepository( $wpdb );
			$credentials           = new CredentialService(
				new CredentialRepository( $wpdb ),
				new CredentialEncryptor()
			);
			$url_validator         = new UrlValidator();
			$http_client           = new HttpClient( $url_validator );
			$agent_client          = new AgentClient( $http_client, new ResponseValidator() );
			$checks                = new CheckRepository( $wpdb );
			$events                = new EventRepository( $wpdb );
			$statuses              = new SiteStatusRepository( $wpdb );
			$notification_settings = new NotificationSettings();
			$notifications         = new NotificationManager(
				new NotificationRule(),
				new NotificationMessageFactory( $sites ),
				array( new EmailNotifier( $notification_settings ) )
			);
			$recorder              = new CheckResultRecorder(
				$wpdb,
				$checks,
				$statuses,
				$events,
				new StatusEvaluator(),
				new StateTransition(),
				$notifications
			);
			$heartbeat             = new SchedulerHeartbeat();
			$retry_scheduler       = new RetryScheduler();
			$batch_limit           = min( CheckRunner::MAX_BATCH_LIMIT, max( 1, (int) apply_filters( 'odm_check_batch_limit', CheckRunner::DEFAULT_BATCH_LIMIT ) ) );
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
						new SslMonitor( new SslCertificateClient(), url_validator: $url_validator ),
					),
					$recorder,
					$retry_scheduler,
					new BatchScheduler( $wpdb ),
					$batch_limit
				),
				new CheckRetention( $checks, null, new OptionCleanupRepository( $wpdb ) ),
				$heartbeat
			);
			$scheduler->register_hooks();

			if ( ! is_admin() ) {
				return;
			}

			$service = new SiteService(
				$sites,
				$credentials,
				$agent_client,
				new UUID(),
				$url_validator
			);

			$overview = new StatusOverview( $sites, $statuses );

			( new Admin(
				new DashboardPage( $overview, $heartbeat ),
				new SitesPage( $overview, $service ),
				new SiteDetailPage( $sites, $statuses, $checks, $events, $service ),
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

	/**
	 * Run pending database migrations on every normal WordPress bootstrap.
	 */
	public function maybe_upgrade_database(): bool {
		global $wpdb;

		$migrator = null === $this->database_migrator_factory
			? new DatabaseMigrator( $wpdb )
			: ( $this->database_migrator_factory )( $wpdb );
		$result   = $migrator->migrate();

		if ( is_wp_error( $result ) ) {
			$this->database_ready           = false;
			$this->database_migration_error = $result;
			add_action( 'admin_notices', array( $this, 'database_migration_notice' ) );

			return false;
		}

		$this->database_ready           = true;
		$this->database_migration_error = null;
		remove_action( 'admin_notices', array( $this, 'database_migration_notice' ) );

		return true;
	}

	/**
	 * Show a safe migration status to administrators.
	 */
	public function database_migration_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || null === $this->database_migration_error ) {
			return;
		}

		$in_progress = 'odm_database_migration_in_progress' === $this->database_migration_error->get_error_code();
		$status      = get_option( DatabaseMigrator::STATUS_OPTION, array() );
		$from        = is_array( $status ) && isset( $status['previous_version'] ) ? sanitize_text_field( (string) $status['previous_version'] ) : '';
		$to          = is_array( $status ) && isset( $status['target_version'] ) ? sanitize_text_field( (string) $status['target_version'] ) : DatabaseMigrator::VERSION;

		echo '<div class="notice ' . esc_attr( $in_progress ? 'notice-warning' : 'notice-error' ) . '"><p>';
		if ( $in_progress ) {
			echo esc_html__( 'OD WordPress Monitor is temporarily paused while its database is being updated by another request.', 'od-wordpress-monitor' );
		} else {
			echo esc_html__( 'OD WordPress Monitor could not update its database. Monitoring is paused; reload this page to retry after checking database permissions and logs.', 'od-wordpress-monitor' );
		}

		if ( '' !== $from ) {
			echo ' ';
			echo esc_html(
				sprintf(
					/* translators: 1: previous database schema version, 2: target database schema version. */
					__( 'Schema version: %1$s → %2$s.', 'od-wordpress-monitor' ),
					$from,
					$to
				)
			);
		}
		echo '</p></div>';
	}
}
