<?php
/**
 * Plain-text email notification delivery.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Notification;

use DateTimeZone;
use Olein\WordPressMonitor\Event\MonitoringEvent;
use Olein\WordPressMonitor\Site\SiteRepository;
use Throwable;

final class EmailNotifier implements NotificationSenderInterface {
	public function __construct( private readonly SiteRepository $sites ) {
	}

	public function send( string $recipient, MonitoringEvent $event, string $notification_type ): bool {
		if ( ! is_email( $recipient ) ) {
			return false;
		}

		$site = $this->sites->find( $event->site_id() );

		if ( null === $site ) {
			return false;
		}

		$site_name = $this->safe_line( $site->name() );
		$site_url  = $this->public_url( $site->site_url() );

		if ( '' === $site_name || null === $site_url ) {
			return false;
		}

		if ( NotificationRule::OUTAGE === $notification_type ) {
			$label = __( 'Outage', 'od-wordpress-monitor' );
		} elseif ( NotificationRule::RECOVERY === $notification_type ) {
			$label = __( 'Recovery', 'od-wordpress-monitor' );
		} else {
			return false;
		}

		$subject = sprintf(
			/* translators: 1: Notification type, 2: monitored site name. */
			__( '[OD Monitor] %1$s: %2$s', 'od-wordpress-monitor' ),
			$label,
			$site_name
		);
		$body = implode(
			"\n",
			array(
				sprintf( /* translators: %s: outage or recovery. */ __( 'Notification: %s', 'od-wordpress-monitor' ), $label ),
				sprintf( /* translators: %s: monitored site name. */ __( 'Site: %s', 'od-wordpress-monitor' ), $site_name ),
				sprintf( /* translators: %s: monitored public URL. */ __( 'URL: %s', 'od-wordpress-monitor' ), $site_url ),
				sprintf( /* translators: %s: monitoring event type. */ __( 'Event: %s', 'od-wordpress-monitor' ), $this->safe_code( $event->type() ) ),
				sprintf( /* translators: %s: previous monitoring status. */ __( 'Previous status: %s', 'od-wordpress-monitor' ), $this->safe_code( $event->previous_status() ) ),
				sprintf( /* translators: %s: current monitoring status. */ __( 'Current status: %s', 'od-wordpress-monitor' ), $this->safe_code( $event->current_status() ) ),
				sprintf(
					/* translators: %s: UTC detection time. */
					__( 'Detected at: %s', 'od-wordpress-monitor' ),
					$event->occurred_at()->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s \U\T\C' )
				),
				sprintf( /* translators: %s: safe monitoring error code or dash. */ __( 'Error code: %s', 'od-wordpress-monitor' ), $this->safe_code( $event->error_code() ?? '—' ) ),
				sprintf( /* translators: %s: safe monitoring result message. */ __( 'Message: %s', 'od-wordpress-monitor' ), $this->safe_message( $event->message() ) ),
			)
		);

		try {
			return wp_mail( $recipient, $subject, $body );
		} catch ( Throwable $exception ) {
			unset( $exception );

			return false;
		}
	}

	private function safe_line( string $value ): string {
		return sanitize_text_field( $this->redact( $value ) );
	}

	private function safe_message( string $value ): string {
		return sanitize_textarea_field( $this->redact( $value ) );
	}

	private function safe_code( string $value ): string {
		$value = preg_replace( '/[^A-Za-z0-9_.—-]/', '', $value );

		return is_string( $value ) && '' !== $value ? substr( $value, 0, 100 ) : '—';
	}

	private function redact( string $value ): string {
		$value = preg_replace( '/(?:^|\R)\s*(?:authorization|cookie|set-cookie)\s*:.*(?=\R|$)/i', '', $value );
		$value = preg_replace( '/\b(?:basic|bearer)\s+[a-z0-9+\/=._-]+/i', '[redacted]', (string) $value );
		$value = preg_replace( '/\b(?:api[_-]?key|application[_-]?password|credential|password|secret|token)\s*[:=]\s*\S+/i', '[redacted]', (string) $value );

		return (string) $value;
	}

	private function public_url( string $url ): ?string {
		$parts = wp_parse_url( $url );

		if (
			! is_array( $parts )
			|| ! isset( $parts['scheme'], $parts['host'] )
			|| ! in_array( $parts['scheme'], array( 'http', 'https' ), true )
		) {
			return null;
		}

		$public_url = $parts['scheme'] . '://' . $parts['host'];

		if ( isset( $parts['port'] ) ) {
			$public_url .= ':' . (int) $parts['port'];
		}

		return $public_url . ( $parts['path'] ?? '' );
	}
}
