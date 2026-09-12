<?php
/**
 * Builds secret-free notification content from a monitoring event.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Notification;

use Olein\WordPressMonitor\Event\MonitoringEvent;
use Olein\WordPressMonitor\Site\SiteRepository;

final class NotificationMessageFactory implements NotificationMessageFactoryInterface {
	public function __construct( private readonly SiteRepository $sites ) {
	}

	public function create( MonitoringEvent $event, string $notification_type ): ?NotificationMessage {
		if ( ! in_array( $notification_type, array( NotificationRule::OUTAGE, NotificationRule::RECOVERY ), true ) ) {
			return null;
		}

		$site = $this->sites->find( $event->site_id() );

		if ( null === $site ) {
			return null;
		}

		$site_name = $this->safe_line( $site->name() );
		$site_url  = $this->public_url( $site->site_url() );

		if ( '' === $site_name || null === $site_url ) {
			return null;
		}

		return new NotificationMessage(
			$notification_type,
			$site_name,
			$site_url,
			$this->safe_code( $event->type() ),
			$this->safe_code( $event->previous_status() ),
			$this->safe_code( $event->current_status() ),
			$event->occurred_at(),
			$this->safe_code( $event->error_code() ?? '—' ),
			$this->safe_message( $event->message() )
		);
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
