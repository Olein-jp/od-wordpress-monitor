<?php
/**
 * Notification delivery retry tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use DateTimeImmutable;
use Olein\WordPressMonitor\Activation\DatabaseMigrator;
use Olein\WordPressMonitor\Event\EventRepository;
use Olein\WordPressMonitor\Event\MonitoringEvent;
use Olein\WordPressMonitor\Notification\NotificationChannelResult;
use Olein\WordPressMonitor\Notification\NotificationDeliveryResult;
use Olein\WordPressMonitor\Notification\NotificationDeliveryRetry;
use Olein\WordPressMonitor\Notification\NotificationManager;
use Olein\WordPressMonitor\Notification\NotificationMessage;
use Olein\WordPressMonitor\Notification\NotificationMessageFactoryInterface;
use Olein\WordPressMonitor\Notification\NotificationRule;
use Olein\WordPressMonitor\Notification\NotificationSenderInterface;

final class NotificationDeliveryRetryTest extends \WP_UnitTestCase {
	private EventRepository $events;
	private int $event_id;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		( new DatabaseMigrator( $wpdb ) )->migrate();
		$this->events = new EventRepository( $wpdb );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}odm_events" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->event_id = $this->events->create( new MonitoringEvent( null, 7, 'SITE_DOWN', 'healthy', 'critical', null, 'Down.', new DateTimeImmutable( '2026-09-13T00:00:00Z' ), array( 'source' => 'http' ) ) );
		$this->assertIsInt( $this->event_id );
	}

	public function tear_down(): void {
		wp_clear_scheduled_hook( NotificationDeliveryRetry::HOOK, array( $this->event_id, 'slack' ) );
		delete_option( 'odm_lock_notification_retry_' . $this->event_id . '_slack' );
		parent::tear_down();
	}

	/**
	 * @dataProvider retryable_error_provider
	 */
	public function test_only_transient_failures_are_retryable( string $code, bool $expected ): void {
		$retry  = $this->retry( $this->sender() );
		$result = NotificationChannelResult::failed( 'slack', $code );
		$this->assertSame( $expected, $retry->is_retryable( $result ) );
		$retry->schedule_failed( $this->event_id, new NotificationDeliveryResult( array( $result ) ) );
		$this->assertSame( $expected, is_int( wp_next_scheduled( NotificationDeliveryRetry::HOOK, array( $this->event_id, 'slack' ) ) ) );
	}

	/**
	 * @return array<string,array{string,bool}>
	 */
	public function retryable_error_provider(): array {
		return array(
			'timeout'      => array( 'TIMEOUT', true ),
			'connection'   => array( 'CONNECTION_ERROR', true ),
			'http 408'     => array( 'HTTP_408', true ),
			'http 429'     => array( 'HTTP_429', true ),
			'http 500'     => array( 'HTTP_500', true ),
			'http 599'     => array( 'HTTP_599', true ),
			'http 400'     => array( 'HTTP_400', false ),
			'http 401'     => array( 'HTTP_401', false ),
			'http 403'     => array( 'HTTP_403', false ),
			'http 404'     => array( 'HTTP_404', false ),
			'payload'      => array( 'PAYLOAD_ENCODING_FAILED', false ),
			'bad webhook'  => array( 'WEBHOOK_URL_INVALID', false ),
			'bad chatwork' => array( 'CHATWORK_SETTINGS_INVALID', false ),
			'email failed' => array( 'EMAIL_SEND_FAILED', false ),
		);
	}

	public function test_schedules_only_event_and_channel_once_and_merges_retry_success(): void {
		$sender  = $this->sender();
		$retry   = $this->retry( $sender );
		$initial = new NotificationDeliveryResult( array( NotificationChannelResult::sent( 'email' ), NotificationChannelResult::failed( 'slack', 'HTTP_429', 1, 120 ) ) );
		$this->assertTrue( $this->events->record_notification_result( $this->event_id, $initial, new DateTimeImmutable( '2026-09-13T00:00:00Z' ) ) );
		$retry->schedule_failed( $this->event_id, $initial );
		$retry->schedule_failed( $this->event_id, $initial );
		$event = wp_get_scheduled_event( NotificationDeliveryRetry::HOOK, array( $this->event_id, 'slack' ) );
		$this->assertIsObject( $event );
		$this->assertSame( 1789257720, $event->timestamp );
		$this->assertSame( array( $this->event_id, 'slack' ), $event->args );
		$this->assertStringNotContainsString( 'Down.', (string) wp_json_encode( $event ) );
		$this->assertSame( 'sent', $retry->run( $this->event_id, 'slack' )->status() );
		$this->assertNull( $retry->run( $this->event_id, 'slack' ) );
		$this->assertSame( 1, $sender->calls );
		$notification = $this->events->find( $this->event_id )->metadata()['notification'];
		$this->assertSame( 'sent', $notification['status'] );
		$this->assertSame( 'sent', $notification['channels']['email']['status'] );
		$this->assertSame( 1, $notification['channels']['email']['attempts'] );
		$this->assertSame( 'sent', $notification['channels']['slack']['status'] );
		$this->assertSame( 2, $notification['channels']['slack']['attempts'] );
		$this->assertSame( '2026-09-13T00:00:00Z', $notification['channels']['slack']['attempted_at'] );
		$this->assertSame( array( 'source', 'notification' ), array_keys( $this->events->find( $this->event_id )->metadata() ) );
	}

	public function test_missing_disabled_or_already_successful_channel_does_not_send(): void {
		$sender = $this->sender();
		$retry  = $this->retry( $sender );
		$this->assertNull( $retry->run( 999999, 'slack' ) );
		$this->assertTrue( $this->events->record_notification_result( $this->event_id, new NotificationDeliveryResult( array( NotificationChannelResult::sent( 'slack' ) ) ) ) );
		$this->assertNull( $retry->run( $this->event_id, 'slack' ) );
		$this->assertSame( 0, $sender->calls );
		$this->assertTrue( $this->events->record_notification_result( $this->event_id, new NotificationDeliveryResult( array( NotificationChannelResult::failed( 'slack', 'HTTP_503' ) ) ) ) );
		$sender->is_enabled = false;
		$this->assertNull( $retry->run( $this->event_id, 'slack' ) );
		$this->assertSame( 0, $sender->calls );
	}

	private function retry( NotificationSenderInterface $sender ): NotificationDeliveryRetry {
		$factory = new class() implements NotificationMessageFactoryInterface {
			public function create( MonitoringEvent $event, string $notification_type ): ?NotificationMessage {
				return new NotificationMessage( $notification_type, 'Example', 'https://example.com', $event->type(), $event->previous_status(), $event->current_status(), $event->occurred_at(), '—', 'Down.' );
			}
		};
		return new NotificationDeliveryRetry( $this->events, new NotificationManager( new NotificationRule(), $factory, array( $sender ) ), static fn(): int => 1789257600 );
	}

	/**
	 * @return NotificationSenderInterface&object{calls:int,is_enabled:bool}
	 */
	private function sender(): NotificationSenderInterface {
		return new class() implements NotificationSenderInterface {
			public int $calls       = 0;
			public bool $is_enabled = true;
			public function channel_id(): string {
				return 'slack';
			}
			public function enabled(): bool {
				return $this->is_enabled;
			}
			public function send( NotificationMessage $message ): NotificationChannelResult {
				unset( $message );
				++$this->calls;
				return NotificationChannelResult::sent( 'slack' );
			}
		};
	}
}
