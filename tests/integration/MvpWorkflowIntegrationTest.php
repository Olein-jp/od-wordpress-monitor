<?php
/**
 * MVP workflow integration test.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\IntegrationTests;

use DateTimeImmutable;
use Olein\WordPressMonitor\Activation\DatabaseMigrator;
use Olein\WordPressMonitor\Admin\SiteDetailPage;
use Olein\WordPressMonitor\Admin\SitesPage;
use Olein\WordPressMonitor\Admin\StatusOverview;
use Olein\WordPressMonitor\Check\CheckRepository;
use Olein\WordPressMonitor\Credential\CredentialEncryptor;
use Olein\WordPressMonitor\Credential\CredentialRepository;
use Olein\WordPressMonitor\Credential\CredentialService;
use Olein\WordPressMonitor\Evaluation\CheckResultRecorder;
use Olein\WordPressMonitor\Evaluation\StateTransition;
use Olein\WordPressMonitor\Evaluation\StatusEvaluator;
use Olein\WordPressMonitor\Event\EventRepository;
use Olein\WordPressMonitor\Event\MonitoringEvent;
use Olein\WordPressMonitor\Http\AgentClient;
use Olein\WordPressMonitor\Http\HttpClient;
use Olein\WordPressMonitor\Http\UrlValidator;
use Olein\WordPressMonitor\Monitor\Monitoring\AgentPingMonitor;
use Olein\WordPressMonitor\Monitor\Monitoring\AgentStatusMonitor;
use Olein\WordPressMonitor\Monitor\Monitoring\HttpMonitor;
use Olein\WordPressMonitor\Monitor\Monitoring\SiteHealthMonitor;
use Olein\WordPressMonitor\Monitor\Monitoring\SslCertificateClientInterface;
use Olein\WordPressMonitor\Monitor\Monitoring\SslMonitor;
use Olein\WordPressMonitor\Monitor\Monitoring\UpdateMonitor;
use Olein\WordPressMonitor\Notification\NotificationManager;
use Olein\WordPressMonitor\Notification\NotificationRule;
use Olein\WordPressMonitor\Notification\NotificationSenderInterface;
use Olein\WordPressMonitor\Notification\NotificationSettings;
use Olein\WordPressMonitor\Protocol\ResponseValidator;
use Olein\WordPressMonitor\Scheduler\CheckLock;
use Olein\WordPressMonitor\Scheduler\CheckRunner;
use Olein\WordPressMonitor\Site\SiteRepository;
use Olein\WordPressMonitor\Site\SiteService;
use Olein\WordPressMonitor\Status\SiteStatusRepository;
use Olein\WordPressMonitor\Support\UUID;
use WP_Error;

final class MvpWorkflowIntegrationTest extends \WP_UnitTestCase {
	private SiteRepository $sites;
	private CredentialRepository $credential_repository;
	private CredentialService $credentials;
	private CheckRepository $checks;
	private EventRepository $events;
	private SiteStatusRepository $statuses;
	private AgentClient $agent;
	private HttpClient $http;
	private UrlValidator $url_validator;
	private int $http_status = 200;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;

		( new DatabaseMigrator( $wpdb ) )->migrate();
		$this->sites                 = new SiteRepository( $wpdb );
		$this->credential_repository = new CredentialRepository( $wpdb );
		$this->credentials           = new CredentialService(
			$this->credential_repository,
			new CredentialEncryptor( str_repeat( 'k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) )
		);
		$this->checks                = new CheckRepository( $wpdb );
		$this->events                = new EventRepository( $wpdb );
		$this->statuses              = new SiteStatusRepository( $wpdb );
		$this->url_validator         = new UrlValidator( static fn(): array => array( '93.184.216.34' ) );
		$this->http                  = new HttpClient( $this->url_validator );
		$this->agent                 = new AgentClient( $this->http, new ResponseValidator() );

		foreach ( array( 'odm_events', 'odm_checks', 'odm_site_status', 'odm_credentials', 'odm_sites' ) as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		delete_option( NotificationSettings::OPTION );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET = array();
		$this->mock_http();
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		delete_option( NotificationSettings::OPTION );
		$_GET = array();
		parent::tear_down();
	}

	public function test_registration_checks_history_notification_and_admin_display_work_together(): void {
		$service      = new SiteService( $this->sites, $this->credentials, $this->agent, new UUID(), $this->url_validator );
		$registration = $service->register( 'MVP Site', 'https://example.com', 'agent-user', 'application-password' );

		$this->assertIsArray( $registration );
		$site    = $registration['site'];
		$site_id = (int) $site->id();
		$stored  = $this->credential_repository->find_by_site( $site_id );
		$this->assertNotNull( $stored );
		$this->assertStringNotContainsString( 'application-password', $stored['encrypted_password'] );

		$sender = new class() implements NotificationSenderInterface {
			/** @var list<string> */
			public array $types = array();

			public function send( string $recipient, MonitoringEvent $event, string $notification_type ): bool {
				unset( $recipient, $event );
				$this->types[] = $notification_type;

				return true;
			}
		};
		update_option(
			NotificationSettings::OPTION,
			array(
				'enabled' => '1',
				'email'   => 'operator@example.com',
			),
			false
		);
		$recorder = new CheckResultRecorder(
			$GLOBALS['wpdb'],
			$this->checks,
			$this->statuses,
			$this->events,
			new StatusEvaluator(),
			new StateTransition(),
			new NotificationManager( new NotificationSettings(), new NotificationRule(), $sender )
		);
		$runner   = new CheckRunner(
			$this->sites,
			new CheckLock( $GLOBALS['wpdb'] ),
			array(
				new HttpMonitor( $this->http ),
				new AgentPingMonitor( $this->agent, $this->credentials ),
				new AgentStatusMonitor( $this->agent, $this->credentials ),
				new UpdateMonitor( $this->agent, $this->credentials ),
				new SiteHealthMonitor( $this->agent, $this->credentials ),
				new SslMonitor( $this->certificate_client(), url_validator: $this->url_validator ),
			),
			$recorder
		);

		foreach ( array( 'http', 'agent_ping', 'agent_status', 'updates', 'site_health', 'ssl' ) as $check_type ) {
			$this->assertCount( 1, $runner->run( $check_type ) );
		}

		$this->http_status = 503;
		$this->assertSame( 'critical', $runner->run( 'http' )[0]->status() );

		$history = $this->checks->for_site( $site_id, 20 );
		$events  = $this->events->for_site( $site_id, 20 );
		$status  = $this->statuses->find( $site_id );
		$types   = array_unique( array_map( static fn( $check ): string => $check->type(), $history ) );
		sort( $types );

		$this->assertCount( 7, $history );
		$this->assertSame( array( 'agent_ping', 'agent_status', 'http', 'site_health', 'ssl', 'updates' ), $types );
		$this->assertNotNull( $status );
		$this->assertSame( 'critical', $status->overall_status() );
		$this->assertNotEmpty( $events );
		$this->assertSame( 'SITE_DOWN', $events[0]->type() );
		$this->assertSame( array( NotificationRule::OUTAGE ), $sender->types );

		$_GET['site_id'] = (string) $site_id;
		$detail          = $this->render( new SiteDetailPage( $this->sites, $this->statuses, $this->checks, $this->events, $service ) );
		$list            = $this->render( new SitesPage( new StatusOverview( $this->sites, $this->statuses ), $service ) );

		$this->assertStringContainsString( '<h1>MVP Site</h1>', $detail );
		$this->assertStringContainsString( 'Recent Events', $detail );
		$this->assertStringContainsString( 'Recent Checks', $detail );
		$this->assertStringContainsString( '<strong>Problem</strong>', $list );
		$this->assertStringNotContainsString( 'application-password', $detail . $list );
	}

	private function mock_http(): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, array $arguments, string $url ): array {
				unset( $preempt, $arguments );
				$path = (string) wp_parse_url( $url, PHP_URL_PATH );
				$body = array();

				if ( str_ends_with( $path, '/ping' ) ) {
					$body = $this->fixture( 'ping-success.json' );
				} elseif ( str_ends_with( $path, '/status' ) ) {
					$body = $this->fixture( 'status-success.json' );
				} elseif ( str_ends_with( $path, '/updates' ) ) {
					$body = $this->fixture( 'updates-success.json' );
				} elseif ( str_ends_with( $path, '/site-health' ) ) {
					$body            = $this->fixture( 'site-health-success.json' );
					$body['summary'] = array(
						'critical'    => 0,
						'recommended' => 0,
						'good'        => 1,
					);
					$body['tests']   = array( $body['tests'][0] );
				}

				$status = array() === $body ? $this->http_status : 200;

				return array(
					'headers'  => array(),
					'body'     => array() === $body ? '' : wp_json_encode( $body ),
					'response' => array(
						'code'    => $status,
						'message' => '',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}

	private function certificate_client(): SslCertificateClientInterface {
		return new class() implements SslCertificateClientInterface {
			public function inspect( string $host, int $port, int $timeout ): array|WP_Error {
				unset( $host, $port, $timeout );

				return array(
					'valid_from' => time() - DAY_IN_SECONDS,
					'valid_to'   => time() + ( 90 * DAY_IN_SECONDS ),
				);
			}
		};
	}

	/**
	 * @return array<string,mixed>
	 */
	private function fixture( string $name ): array {
		$contents = file_get_contents( dirname( __DIR__, 2 ) . '/packages/protocol/fixtures/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertNotFalse( $contents );
		$data = json_decode( $contents, true );
		$this->assertIsArray( $data );

		return $data;
	}

	/**
	 * @param SiteDetailPage|SitesPage $page Administration page.
	 */
	private function render( $page ): string {
		ob_start();
		$page->render();

		return (string) ob_get_clean();
	}
}
