<?php
/**
 * Notification manager tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use DateTimeImmutable;
use Olein\WordPressMonitor\Event\MonitoringEvent;
use Olein\WordPressMonitor\Notification\NotificationChannelResult;
use Olein\WordPressMonitor\Notification\NotificationDeliveryResult;
use Olein\WordPressMonitor\Notification\NotificationManager;
use Olein\WordPressMonitor\Notification\NotificationMessage;
use Olein\WordPressMonitor\Notification\NotificationMessageFactoryInterface;
use Olein\WordPressMonitor\Notification\NotificationRule;
use Olein\WordPressMonitor\Notification\NotificationSenderInterface;

final class NotificationManagerTest extends \WP_UnitTestCase {
	/**
	 * @dataProvider selected_transition_provider
	 */
	public function test_dispatches_selected_transitions( string $previous, string $current, string $expected_type ): void {
		$sender  = $this->sender( 'email' );
		$manager = new NotificationManager( new NotificationRule(), $this->message_factory(), array( $sender ) );

		$result = $manager->notify( $this->event( $previous, $current ) );

		$this->assertInstanceOf( NotificationDeliveryResult::class, $result );
		$this->assertSame( NotificationDeliveryResult::SENT, $result->status() );
		$this->assertSame( 1, $sender->calls );
		$this->assertSame( $expected_type, $sender->message->notification_type() );
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

	public function test_does_not_dispatch_a_suppressed_transition(): void {
		$sender  = $this->sender( 'email' );
		$manager = new NotificationManager( new NotificationRule(), $this->message_factory(), array( $sender ) );

		$this->assertNull( $manager->notify( $this->event( 'critical', 'critical' ) ) );
		$this->assertNull( $manager->notify( $this->event( 'unknown', 'critical' ) ) );
		$this->assertSame( 0, $sender->calls );
	}

	public function test_does_not_dispatch_when_every_channel_is_disabled(): void {
		$sender  = $this->sender( 'email', false );
		$manager = new NotificationManager( new NotificationRule(), $this->message_factory(), array( $sender ) );

		$this->assertNull( $manager->notify( $this->event( 'healthy', 'critical' ) ) );
		$this->assertSame( 0, $sender->calls );
	}

	public function test_continues_after_failure_and_returns_a_partial_result(): void {
		$failed    = $this->sender( 'slack', true, false );
		$succeeded = $this->sender( 'email' );
		$manager   = new NotificationManager( new NotificationRule(), $this->message_factory(), array( $failed, $succeeded ) );

		$result = $manager->notify( $this->event( 'healthy', 'critical' ) );

		$this->assertInstanceOf( NotificationDeliveryResult::class, $result );
		$this->assertSame( NotificationDeliveryResult::PARTIAL, $result->status() );
		$this->assertSame( NotificationChannelResult::FAILED, $result->channels()['slack']->status() );
		$this->assertSame( 'TEST_SEND_FAILED', $result->channels()['slack']->error_code() );
		$this->assertSame( NotificationChannelResult::SENT, $result->channels()['email']->status() );
		$this->assertSame( 1, $failed->calls );
		$this->assertSame( 1, $succeeded->calls );
	}

	public function test_chatwork_failure_does_not_stop_email_delivery(): void {
		$chatwork = $this->sender( 'chatwork', true, false );
		$email    = $this->sender( 'email' );
		$manager  = new NotificationManager( new NotificationRule(), $this->message_factory(), array( $chatwork, $email ) );

		$result = $manager->notify( $this->event( 'healthy', 'critical' ) );

		$this->assertInstanceOf( NotificationDeliveryResult::class, $result );
		$this->assertSame( NotificationDeliveryResult::PARTIAL, $result->status() );
		$this->assertSame( NotificationChannelResult::FAILED, $result->channels()['chatwork']->status() );
		$this->assertSame( NotificationChannelResult::SENT, $result->channels()['email']->status() );
		$this->assertSame( 1, $chatwork->calls );
		$this->assertSame( 1, $email->calls );
	}

	public function test_returns_sent_when_every_enabled_channel_succeeds(): void {
		$email   = $this->sender( 'email' );
		$slack   = $this->sender( 'slack' );
		$manager = new NotificationManager( new NotificationRule(), $this->message_factory(), array( $email, $slack ) );

		$result = $manager->notify( $this->event( 'healthy', 'critical' ) );

		$this->assertInstanceOf( NotificationDeliveryResult::class, $result );
		$this->assertSame( NotificationDeliveryResult::SENT, $result->status() );
		$this->assertSame( array( 'email', 'slack' ), array_keys( $result->channels() ) );
		$this->assertSame( 1, $email->calls );
		$this->assertSame( 1, $slack->calls );
	}

	public function test_continues_after_exception_and_returns_a_failed_result(): void {
		$exception = $this->sender( 'slack', true, true, true );
		$failed    = $this->sender( 'discord', true, false );
		$manager   = new NotificationManager( new NotificationRule(), $this->message_factory(), array( $exception, $failed ) );

		$result = $manager->notify( $this->event( 'healthy', 'critical' ) );

		$this->assertInstanceOf( NotificationDeliveryResult::class, $result );
		$this->assertSame( NotificationDeliveryResult::FAILED, $result->status() );
		$this->assertSame( 'DELIVERY_EXCEPTION', $result->channels()['slack']->error_code() );
		$this->assertSame( 'TEST_SEND_FAILED', $result->channels()['discord']->error_code() );
		$this->assertSame( 1, $failed->calls );
	}

	public function test_rejects_duplicate_channel_ids(): void {
		$this->expectException( \InvalidArgumentException::class );

		new NotificationManager(
			new NotificationRule(),
			$this->message_factory(),
			array( $this->sender( 'email' ), $this->sender( 'email' ) )
		);
	}

	public function test_channel_result_replaces_an_unsafe_error_code(): void {
		$result = NotificationChannelResult::failed( 'email', 'Bearer secret-value' );

		$this->assertSame( 'DELIVERY_FAILED', $result->error_code() );
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

	private function message_factory(): NotificationMessageFactoryInterface {
		return new class() implements NotificationMessageFactoryInterface {
			public function create( MonitoringEvent $event, string $notification_type ): ?NotificationMessage {
				return new NotificationMessage(
					$notification_type,
					'Example Site',
					'https://example.com',
					$event->type(),
					$event->previous_status(),
					$event->current_status(),
					$event->occurred_at(),
					$event->error_code() ?? '—',
					$event->message()
				);
			}
		};
	}

	/**
	 * @return NotificationSenderInterface&object{calls:int,message:?NotificationMessage}
	 */
	private function sender( string $channel_id, bool $enabled = true, bool $succeeds = true, bool $throws = false ): NotificationSenderInterface {
		return new class( $channel_id, $enabled, $succeeds, $throws ) implements NotificationSenderInterface {
			public int $calls                    = 0;
			public ?NotificationMessage $message = null;

			public function __construct(
				private readonly string $channel_id,
				private readonly bool $is_enabled,
				private readonly bool $succeeds,
				private readonly bool $throws
			) {
			}

			public function channel_id(): string {
				return $this->channel_id;
			}

			public function enabled(): bool {
				return $this->is_enabled;
			}

			public function send( NotificationMessage $message ): NotificationChannelResult {
				++$this->calls;
				$this->message = $message;

				if ( $this->throws ) {
					throw new \RuntimeException( 'Sensitive transport detail.' );
				}

				return $this->succeeds
					? NotificationChannelResult::sent( $this->channel_id )
					: NotificationChannelResult::failed( $this->channel_id, 'TEST_SEND_FAILED' );
			}
		};
	}
}
