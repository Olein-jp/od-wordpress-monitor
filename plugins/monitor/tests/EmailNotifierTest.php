<?php
/**
 * Email notifier tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use DateTimeImmutable;
use Olein\WordPressMonitor\Activation\DatabaseMigrator;
use Olein\WordPressMonitor\Event\MonitoringEvent;
use Olein\WordPressMonitor\Notification\EmailNotifier;
use Olein\WordPressMonitor\Notification\NotificationRule;
use Olein\WordPressMonitor\Site\Site;
use Olein\WordPressMonitor\Site\SiteRepository;

final class EmailNotifierTest extends \WP_UnitTestCase {
	private SiteRepository $sites;
	private int $site_id;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;

		( new DatabaseMigrator( $wpdb ) )->migrate();
		$this->sites = new SiteRepository( $wpdb );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}odm_sites" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$site_id = $this->sites->create(
			new Site(
				null,
				'11111111-1111-4111-8111-111111111111',
				"Example Site\nBcc: ignored@example.com",
				'https://user:password@example.com:8443/path?token=private#section',
				'https://example.com/wp-json/od-monitor-agent/v1'
			)
		);
		$this->assertIsInt( $site_id );
		$this->site_id = $site_id;
	}

	/**
	 * @dataProvider notification_provider
	 */
	public function test_sends_plain_text_outage_and_recovery_email( string $notification_type, string $previous, string $current, string $label ): void {
		$mail     = array();
		$callback = static function ( $short_circuit, array $attributes ) use ( &$mail ) {
			unset( $short_circuit );
			$mail = $attributes;

			return true;
		};
		add_filter( 'pre_wp_mail', $callback, 10, 2 );

		$sent = ( new EmailNotifier( $this->sites ) )->send(
			'alerts@example.com',
			$this->event( $previous, $current ),
			$notification_type
		);
		remove_filter( 'pre_wp_mail', $callback, 10 );

		$this->assertTrue( $sent );
		$this->assertSame( 'alerts@example.com', $mail['to'] );
		$this->assertStringContainsString( $label, $mail['subject'] );
		$this->assertStringNotContainsString( "\n", $mail['subject'] );
		$this->assertStringContainsString( 'Example Site Bcc: ignored@example.com', $mail['subject'] );
		$this->assertStringContainsString( 'https://example.com:8443/path', $mail['message'] );
		$this->assertStringContainsString( 'SITE_DOWN', $mail['message'] );
		$this->assertStringContainsString( $previous, $mail['message'] );
		$this->assertStringContainsString( $current, $mail['message'] );
		$this->assertStringContainsString( '2026-09-10 00:00:00 UTC', $mail['message'] );
		$this->assertStringContainsString( 'CONNECTION_ERROR', $mail['message'] );
		$this->assertStringContainsString( 'Request failed.', $mail['message'] );
		$this->assertStringNotContainsString( 'password', strtolower( $mail['message'] ) );
		$this->assertStringNotContainsString( 'private', $mail['message'] );
		$this->assertStringNotContainsString( 'abcdefghijklmnop', $mail['message'] );
	}

	/**
	 * @return array<string,array{string,string,string,string}>
	 */
	public function notification_provider(): array {
		return array(
			'outage'   => array( NotificationRule::OUTAGE, 'healthy', 'critical', 'Outage' ),
			'recovery' => array( NotificationRule::RECOVERY, 'critical', 'healthy', 'Recovery' ),
		);
	}

	public function test_wp_mail_false_is_returned_as_failure(): void {
		$callback = static fn() => false;
		add_filter( 'pre_wp_mail', $callback );

		$sent = ( new EmailNotifier( $this->sites ) )->send(
			'alerts@example.com',
			$this->event( 'healthy', 'critical' ),
			NotificationRule::OUTAGE
		);
		remove_filter( 'pre_wp_mail', $callback );

		$this->assertFalse( $sent );
	}

	public function test_mail_exception_is_contained(): void {
		$callback = static function (): void {
			throw new \RuntimeException( 'Sensitive transport failure.' );
		};
		add_filter( 'pre_wp_mail', $callback );

		$sent = ( new EmailNotifier( $this->sites ) )->send(
			'alerts@example.com',
			$this->event( 'healthy', 'critical' ),
			NotificationRule::OUTAGE
		);
		remove_filter( 'pre_wp_mail', $callback );

		$this->assertFalse( $sent );
	}

	private function event( string $previous, string $current ): MonitoringEvent {
		return new MonitoringEvent(
			null,
			$this->site_id,
			'SITE_DOWN',
			$previous,
			$current,
			'CONNECTION_ERROR',
			"Request failed.\nAuthorization: Bearer abcdefghijklmnop\nCookie: session=private",
			new DateTimeImmutable( '2026-09-10T09:00:00+09:00' ),
			array( 'ignored' => 'metadata is not emailed' )
		);
	}
}
