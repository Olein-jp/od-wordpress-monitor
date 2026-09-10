<?php
/**
 * Notification manager tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use DateTimeImmutable;
use Olein\WordPressMonitor\Event\MonitoringEvent;
use Olein\WordPressMonitor\Notification\NotificationManager;
use Olein\WordPressMonitor\Notification\NotificationRule;
use Olein\WordPressMonitor\Notification\NotificationSenderInterface;
use Olein\WordPressMonitor\Notification\NotificationSettings;

final class NotificationManagerTest extends \WP_UnitTestCase {
	private NotificationSettings $settings;

	public function set_up(): void {
		parent::set_up();
		delete_option( NotificationSettings::OPTION );
		$this->settings = new NotificationSettings();
	}

	/**
	 * @dataProvider selected_transition_provider
	 */
	public function test_dispatches_selected_transitions_to_the_configured_recipient( string $previous, string $current, string $expected_type ): void {
		update_option(
			NotificationSettings::OPTION,
			array(
				'enabled' => '1',
				'email'   => 'alerts@example.com',
			)
		);
		$sender  = $this->sender();
		$manager = new NotificationManager( $this->settings, new NotificationRule(), $sender );
		$event   = $this->event( $previous, $current );

		$this->assertTrue( $manager->notify( $event ) );
		$this->assertSame( 1, $sender->calls );
		$this->assertSame( 'alerts@example.com', $sender->recipient );
		$this->assertSame( $event, $sender->event );
		$this->assertSame( $expected_type, $sender->notification_type );
	}

	/**
	 * @return array<string,array{string,string,string}>
	 */
	public function selected_transition_provider(): array {
		return array(
			'outage'           => array( 'healthy', 'critical', NotificationRule::OUTAGE ),
			'escalated outage' => array( 'warning', 'critical', NotificationRule::OUTAGE ),
			'recovery'         => array( 'critical', 'healthy', NotificationRule::RECOVERY ),
		);
	}

	public function test_does_not_dispatch_an_ongoing_failure(): void {
		update_option(
			NotificationSettings::OPTION,
			array(
				'enabled' => '1',
				'email'   => 'alerts@example.com',
			)
		);
		$sender  = $this->sender();
		$manager = new NotificationManager( $this->settings, new NotificationRule(), $sender );

		$this->assertNull( $manager->notify( $this->event( 'critical', 'critical' ) ) );
		$this->assertSame( 0, $sender->calls );
	}

	/**
	 * @dataProvider unavailable_recipient_provider
	 */
	public function test_does_not_call_sender_when_disabled_or_recipient_is_invalid( string $enabled, string $email ): void {
		update_option(
			NotificationSettings::OPTION,
			array(
				'enabled' => $enabled,
				'email'   => $email,
			)
		);
		$sender  = $this->sender();
		$manager = new NotificationManager( $this->settings, new NotificationRule(), $sender );

		$this->assertNull( $manager->notify( $this->event( 'healthy', 'critical' ) ) );
		$this->assertSame( 0, $sender->calls );
	}

	public function test_sender_exception_becomes_a_safe_failure(): void {
		update_option(
			NotificationSettings::OPTION,
			array(
				'enabled' => '1',
				'email'   => 'alerts@example.com',
			)
		);
		$sender  = new class() implements NotificationSenderInterface {
			public function send( string $recipient, MonitoringEvent $event, string $notification_type ): bool {
				unset( $recipient, $event, $notification_type );
				throw new \RuntimeException( 'Internal transport detail.' );
			}
		};
		$manager = new NotificationManager( $this->settings, new NotificationRule(), $sender );

		$this->assertFalse( $manager->notify( $this->event( 'healthy', 'critical' ) ) );
	}

	/**
	 * @return array<string,array{string,string}>
	 */
	public function unavailable_recipient_provider(): array {
		return array(
			'disabled'      => array( '0', 'alerts@example.com' ),
			'invalid email' => array( '1', 'not-an-email' ),
			'empty email'   => array( '1', '' ),
		);
	}

	private function event( string $previous, string $current ): MonitoringEvent {
		return new MonitoringEvent(
			null,
			1,
			'SITE_DOWN',
			$previous,
			$current,
			null,
			'Changed.',
			new DateTimeImmutable( '2026-09-10T00:00:00Z' )
		);
	}

	/**
	 * @return NotificationSenderInterface&object{calls:int,recipient:string,event:?MonitoringEvent,notification_type:string}
	 */
	private function sender(): NotificationSenderInterface {
		return new class() implements NotificationSenderInterface {
			public int $calls                = 0;
			public string $recipient         = '';
			public ?MonitoringEvent $event   = null;
			public string $notification_type = '';

			public function send( string $recipient, MonitoringEvent $event, string $notification_type ): bool {
				++$this->calls;
				$this->recipient         = $recipient;
				$this->event             = $event;
				$this->notification_type = $notification_type;

				return true;
			}
		};
	}
}
