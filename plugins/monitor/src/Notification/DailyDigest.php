<?php
/**
 * Idempotent daily notification digest.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Notification;

use Closure;
use DateTimeImmutable;
use Olein\WordPressMonitor\Monitor\Status;
use Olein\WordPressMonitor\Site\SiteRepository;
use Olein\WordPressMonitor\Status\SiteStatusRepository;

final class DailyDigest {
	public const STATE_OPTION = 'odm_notification_digest_state';
	public const CLAIM_OPTION = 'odm_notification_digest_claim';
	public const MAX_SITES    = 100;

	private readonly Closure $now;

	public function __construct(
		private readonly NotificationChannelSettings $settings,
		private readonly SiteStatusRepository $statuses,
		private readonly SiteRepository $sites,
		private readonly NotificationManager $notifications,
		private readonly NotificationDigestSignature $signatures = new NotificationDigestSignature(),
		?Closure $now = null
	) {
		$this->now = $now ?? static fn(): DateTimeImmutable => current_datetime();
	}

	public function run(): ?NotificationDeliveryResult {
		if ( ! $this->settings->updates_digest_enabled() && ! $this->settings->site_health_digest_enabled() ) {
			return null;
		}

		$now = ( $this->now )();
		if ( (int) $now->format( 'G' ) !== $this->settings->digest_hour() ) {
			return null;
		}

		$period = $now->format( 'Y-m-d' );
		$state  = $this->state();
		if ( $period === $state['last_period'] || ! $this->claim( $period ) ) {
			return null;
		}

		$next_signatures = array(
			'updates'     => array(),
			'site_health' => array(),
		);
		$lines           = array();

		foreach ( $this->statuses->bounded( self::MAX_SITES ) as $status ) {
			$site = $this->sites->find( $status->site_id() );
			if ( null === $site ) {
				continue;
			}

			$name     = sanitize_text_field( $site->name() );
			$metadata = $status->metadata();
			if ( $this->settings->updates_digest_enabled() && Status::WARNING === $status->updates_status() ) {
				$signature = $this->signatures->updates( isset( $metadata['updates'] ) && is_array( $metadata['updates'] ) ? $metadata['updates'] : array() );
				$this->add_item( 'updates', $status->site_id(), $name, $signature, $state, $next_signatures, $lines );
			}

			if ( $this->settings->site_health_digest_enabled() && Status::WARNING === $status->site_health_status() ) {
				$signature = $this->signatures->site_health( isset( $metadata['site_health'] ) && is_array( $metadata['site_health'] ) ? $metadata['site_health'] : array() );
				$this->add_item( 'site_health', $status->site_id(), $name, $signature, $state, $next_signatures, $lines );
			}
		}

		update_option(
			self::STATE_OPTION,
			array(
				'last_period' => $period,
				'signatures'  => $next_signatures,
			),
			false
		);

		if ( array() === $lines ) {
			return null;
		}

		$message = new NotificationMessage(
			NotificationRule::DAILY_DIGEST,
			__( 'Monitoring summary', 'od-wordpress-monitor' ),
			home_url( '/' ),
			'DAILY_DIGEST',
			'—',
			'—',
			$now,
			'—',
			implode( "\n", $lines )
		);

		return $this->notifications->dispatch( $message );
	}

	/**
	 * @param array{last_period:string,signatures:array<string,array<string,string>>} $state           Previous state.
	 * @param array<string,array<string,string>>                                    $next_signatures New state.
	 * @param list<string>                                                          $lines           Digest lines.
	 */
	private function add_item( string $type, int $site_id, string $site_name, ?string $signature, array $state, array &$next_signatures, array &$lines ): void {
		if ( null === $signature ) {
			return;
		}

		$key                              = (string) $site_id;
		$next_signatures[ $type ][ $key ] = $signature;
		if ( ( $state['signatures'][ $type ][ $key ] ?? '' ) === $signature ) {
			return;
		}

		$label   = 'updates' === $type ? __( 'Updates', 'od-wordpress-monitor' ) : __( 'Site Health recommended', 'od-wordpress-monitor' );
		$lines[] = sprintf( /* translators: 1: digest item type, 2: monitored site name. */ __( '%1$s: %2$s', 'od-wordpress-monitor' ), $label, $site_name );
	}

	private function claim( string $period ): bool {
		$current = get_option( self::CLAIM_OPTION, '' );
		if ( $period === $current ) {
			return false;
		}

		if ( '' !== $current ) {
			delete_option( self::CLAIM_OPTION );
		}

		return add_option( self::CLAIM_OPTION, $period, '', false );
	}

	/**
	 * @return array{last_period:string,signatures:array<string,array<string,string>>}
	 */
	private function state(): array {
		$state      = get_option( self::STATE_OPTION, array() );
		$state      = is_array( $state ) ? $state : array();
		$signatures = isset( $state['signatures'] ) && is_array( $state['signatures'] ) ? $state['signatures'] : array();

		return array(
			'last_period' => isset( $state['last_period'] ) && is_string( $state['last_period'] ) ? $state['last_period'] : '',
			'signatures'  => array(
				'updates'     => isset( $signatures['updates'] ) && is_array( $signatures['updates'] ) ? $signatures['updates'] : array(),
				'site_health' => isset( $signatures['site_health'] ) && is_array( $signatures['site_health'] ) ? $signatures['site_health'] : array(),
			),
		);
	}
}
