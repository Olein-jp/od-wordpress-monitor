<?php
/**
 * Monitored site detail administration page.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Admin;

use DateTimeImmutable;
use Olein\WordPressMonitor\Check\CheckRecord;
use Olein\WordPressMonitor\Check\CheckRepository;
use Olein\WordPressMonitor\Event\EventRepository;
use Olein\WordPressMonitor\Event\EventType;
use Olein\WordPressMonitor\Event\MonitoringEvent;
use Olein\WordPressMonitor\Site\Site;
use Olein\WordPressMonitor\Site\SiteRepository;
use Olein\WordPressMonitor\Site\SiteService;
use Olein\WordPressMonitor\Status\SiteStatus;
use Olein\WordPressMonitor\Status\SiteStatusRepository;

final class SiteDetailPage {
	public const SLUG = 'od-wordpress-monitor-site';

	private const RECENT_LIMIT = 20;

	public function __construct(
		private readonly SiteRepository $sites,
		private readonly SiteStatusRepository $statuses,
		private readonly CheckRepository $checks,
		private readonly EventRepository $events,
		private readonly SiteService $site_service
	) {
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to view monitored site details.', 'od-wordpress-monitor' ) );
		}

		$requested_site_id = isset( $_GET['site_id'] ) && is_string( $_GET['site_id'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only site selection.
			? wp_unslash( $_GET['site_id'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only site selection.
			: '';
		$site_id           = 1 === preg_match( '/^[1-9][0-9]*$/', $requested_site_id ) ? (int) $requested_site_id : 0;
		$site              = 0 < $site_id ? $this->sites->find( $site_id ) : null;

		if ( null === $site ) {
			$this->render_missing_site();
			return;
		}

		$status = $this->statuses->find( $site_id );
		$events = $this->events->for_site( $site_id, self::RECENT_LIMIT );
		$checks = $this->checks->for_site( $site_id, self::RECENT_LIMIT );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $site->name() ); ?></h1>
			<p>
				<a href="<?php echo esc_url( $site->site_url() ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $site->site_url() ); ?></a>
			</p>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . SitesPage::SLUG ) ); ?>">&larr; <?php echo esc_html__( 'Back to Sites', 'od-wordpress-monitor' ); ?></a></p>

			<h2><?php echo esc_html__( 'Current Status', 'od-wordpress-monitor' ); ?></h2>
			<?php $this->render_current_status( $site, $status ); ?>

			<h2><?php echo esc_html__( 'Site Health Details', 'od-wordpress-monitor' ); ?></h2>
			<?php $this->render_site_health_details( $status ); ?>

			<h2><?php echo esc_html__( 'Site Software', 'od-wordpress-monitor' ); ?></h2>
			<?php $this->render_software_inventory( $status ); ?>

			<h2><?php echo esc_html__( 'Recent Events', 'od-wordpress-monitor' ); ?></h2>
			<?php $this->render_events( $events ); ?>

			<h2><?php echo esc_html__( 'Recent Checks', 'od-wordpress-monitor' ); ?></h2>
			<?php $this->render_checks( $checks ); ?>
		</div>
		<?php
	}

	private function render_site_health_details( ?SiteStatus $status ): void {
		$metadata    = null === $status ? array() : $status->metadata();
		$site_health = isset( $metadata['site_health'] ) && is_array( $metadata['site_health'] ) ? $metadata['site_health'] : null;
		?>
		<p><?php echo esc_html__( 'The Agent Site Health check is limited to safe synchronous tests and may not exactly match the WordPress Site Health screen.', 'od-wordpress-monitor' ); ?></p>
		<?php
		if ( null === $site_health ) {
			echo '<p>' . esc_html__( 'Site Health details have not yet been collected.', 'od-wordpress-monitor' ) . '</p>';
			return;
		}

		$rows = array(
			array( __( 'Critical problems', 'od-wordpress-monitor' ), $this->site_health_count( $site_health, 'critical' ) ),
			array( __( 'Recommended improvements', 'od-wordpress-monitor' ), $this->site_health_count( $site_health, 'recommended' ) ),
			array( __( 'Good results', 'od-wordpress-monitor' ), $this->site_health_count( $site_health, 'good' ) ),
			array( __( 'Representative test ID', 'od-wordpress-monitor' ), isset( $site_health['representative_test_id'] ) && is_string( $site_health['representative_test_id'] ) && '' !== $site_health['representative_test_id'] ? $site_health['representative_test_id'] : '—' ),
		);
		?>
		<table class="widefat striped">
			<caption class="screen-reader-text"><?php echo esc_html__( 'Latest Site Health diagnostic summary', 'od-wordpress-monitor' ); ?></caption>
			<thead><tr>
				<th scope="col"><?php echo esc_html__( 'Item', 'od-wordpress-monitor' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'Value', 'od-wordpress-monitor' ); ?></th>
			</tr></thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $row[0] ); ?></th>
						<td><?php echo esc_html( $row[1] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * @param array<string|int,mixed> $metadata Site Health metadata.
	 */
	private function site_health_count( array $metadata, string $key ): string {
		return isset( $metadata[ $key ] ) && is_int( $metadata[ $key ] ) && 0 <= $metadata[ $key ]
			? (string) $metadata[ $key ]
			: '—';
	}

	private function render_software_inventory( ?SiteStatus $status ): void {
		$metadata  = null === $status ? array() : $status->metadata();
		$updates   = isset( $metadata['updates'] ) && is_array( $metadata['updates'] ) ? $metadata['updates'] : array();
		$inventory = isset( $updates['software_inventory'] ) && is_array( $updates['software_inventory'] ) ? $updates['software_inventory'] : null;

		if ( null === $inventory ) {
			echo '<p>' . esc_html__( 'Software information has not yet been collected.', 'od-wordpress-monitor' ) . '</p>';
			return;
		}

		$wordpress_version = isset( $inventory['wordpress_version'] ) && is_string( $inventory['wordpress_version'] ) ? $inventory['wordpress_version'] : '—';
		$theme             = isset( $inventory['theme'] ) && is_array( $inventory['theme'] ) ? $inventory['theme'] : null;
		$plugins           = isset( $inventory['plugins'] ) && is_array( $inventory['plugins'] ) && array_is_list( $inventory['plugins'] ) ? $inventory['plugins'] : array();
		$collected_at      = isset( $inventory['collected_at'] ) && is_string( $inventory['collected_at'] ) ? $this->parse_inventory_date( $inventory['collected_at'] ) : null;
		?>
		<p>
			<?php echo esc_html__( 'Last collected:', 'od-wordpress-monitor' ); ?>
			<?php $this->render_date( $collected_at ); ?>
		</p>
		<table class="widefat striped">
			<caption class="screen-reader-text"><?php echo esc_html__( 'Active site software and current versions', 'od-wordpress-monitor' ); ?></caption>
			<thead><tr>
				<th scope="col"><?php echo esc_html__( 'Type', 'od-wordpress-monitor' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'Name', 'od-wordpress-monitor' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'Version', 'od-wordpress-monitor' ); ?></th>
			</tr></thead>
			<tbody>
				<tr>
					<th scope="row"><?php echo esc_html__( 'WordPress', 'od-wordpress-monitor' ); ?></th>
					<td><?php echo esc_html__( 'WordPress', 'od-wordpress-monitor' ); ?></td>
					<td><?php echo esc_html( $wordpress_version ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Theme', 'od-wordpress-monitor' ); ?></th>
					<td><?php echo esc_html( null !== $theme && isset( $theme['name'] ) && is_string( $theme['name'] ) ? $theme['name'] : '—' ); ?></td>
					<td><?php echo esc_html( null !== $theme && isset( $theme['version'] ) && is_string( $theme['version'] ) && '' !== $theme['version'] ? $theme['version'] : '—' ); ?></td>
				</tr>
				<?php foreach ( $plugins as $plugin ) : ?>
					<?php
					if ( ! is_array( $plugin ) ) {
						continue;
					}
					?>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Plugin', 'od-wordpress-monitor' ); ?></th>
						<td><?php echo esc_html( isset( $plugin['name'] ) && is_string( $plugin['name'] ) ? $plugin['name'] : '—' ); ?></td>
						<td><?php echo esc_html( isset( $plugin['version'] ) && is_string( $plugin['version'] ) && '' !== $plugin['version'] ? $plugin['version'] : '—' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( ! empty( $inventory['truncated'] ) ) : ?>
			<p><?php echo esc_html__( 'Only the first 100 active plugins are shown.', 'od-wordpress-monitor' ); ?></p>
		<?php endif; ?>
		<?php
	}

	private function parse_inventory_date( string $date ): ?DateTimeImmutable {
		try {
			return new DateTimeImmutable( $date );
		} catch ( \Throwable ) {
			return null;
		}
	}

	private function render_missing_site(): void {
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Site Details', 'od-wordpress-monitor' ); ?></h1>
			<div class="notice notice-error"><p><?php echo esc_html__( 'The monitored site could not be found.', 'od-wordpress-monitor' ); ?></p></div>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . SitesPage::SLUG ) ); ?>"><?php echo esc_html__( 'Back to Sites', 'od-wordpress-monitor' ); ?></a></p>
		</div>
		<?php
	}

	private function render_current_status( Site $site, ?SiteStatus $status ): void {
		$versions = $this->versions( $site, $status );
		$rows     = array(
			array( __( 'Overall', 'od-wordpress-monitor' ), $this->status_value( $status, 'overall' ), null === $status ? null : $status->last_checked_at() ),
			array( __( 'HTTP', 'od-wordpress-monitor' ), $this->status_value( $status, 'http' ), null === $status ? null : $status->http_checked_at() ),
			array( __( 'Agent', 'od-wordpress-monitor' ), $this->status_value( $status, 'agent' ), null === $status ? null : $status->agent_checked_at() ),
			array( __( 'WordPress', 'od-wordpress-monitor' ), $versions['wordpress'], null === $status ? null : $status->agent_checked_at() ),
			array( __( 'PHP', 'od-wordpress-monitor' ), $versions['php'], null === $status ? null : $status->agent_checked_at() ),
			array( __( 'Updates', 'od-wordpress-monitor' ), $this->status_value( $status, 'updates' ), null === $status ? null : $status->updates_checked_at() ),
			array( __( 'Site Health', 'od-wordpress-monitor' ), $this->status_value( $status, 'site_health' ), null === $status ? null : $status->site_health_checked_at() ),
			array( __( 'SSL', 'od-wordpress-monitor' ), $this->status_value( $status, 'ssl' ), null === $status ? null : $status->ssl_checked_at() ),
			array( __( 'Last checked', 'od-wordpress-monitor' ), '—', null === $status ? null : $status->last_checked_at() ),
		);
		?>
		<table class="widefat striped">
			<caption class="screen-reader-text"><?php echo esc_html__( 'Current monitoring status and check times', 'od-wordpress-monitor' ); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php echo esc_html__( 'Item', 'od-wordpress-monitor' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Status or version', 'od-wordpress-monitor' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Checked at', 'od-wordpress-monitor' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $row[0] ); ?></th>
						<td><?php echo esc_html( $row[1] ); ?></td>
						<td><?php $this->render_date( $row[2] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * @param list<MonitoringEvent> $events Recent events.
	 */
	private function render_events( array $events ): void {
		echo '<p>' . esc_html__( 'Recent Events is a history of state changes and may include problems that are no longer active.', 'od-wordpress-monitor' ) . '</p>';

		if ( array() === $events ) {
			echo '<p>' . esc_html__( 'No events have been recorded.', 'od-wordpress-monitor' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped">
			<caption class="screen-reader-text"><?php echo esc_html__( 'Recent monitoring events, newest first', 'od-wordpress-monitor' ); ?></caption>
			<thead><tr>
				<th scope="col"><?php echo esc_html__( 'Occurred at', 'od-wordpress-monitor' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'Event', 'od-wordpress-monitor' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'Previous', 'od-wordpress-monitor' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'Current', 'od-wordpress-monitor' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'Error', 'od-wordpress-monitor' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'Message', 'od-wordpress-monitor' ); ?></th>
			</tr></thead>
			<tbody>
				<?php foreach ( $events as $event ) : ?>
					<tr>
						<td><?php $this->render_date( $event->occurred_at() ); ?></td>
						<td><?php echo esc_html( $this->event_label( $event->type() ) ); ?></td>
						<td><?php echo esc_html( StatusLabel::for_status( $event->previous_status() ) ); ?></td>
						<td><?php echo esc_html( StatusLabel::for_status( $event->current_status() ) ); ?></td>
						<td><?php echo esc_html( $event->error_code() ?? '—' ); ?></td>
						<td><?php echo esc_html( $event->message() ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * @param list<CheckRecord> $checks Recent checks.
	 */
	private function render_checks( array $checks ): void {
		if ( array() === $checks ) {
			echo '<p>' . esc_html__( 'No checks have been recorded.', 'od-wordpress-monitor' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped">
			<caption class="screen-reader-text"><?php echo esc_html__( 'Recent monitoring checks, newest first', 'od-wordpress-monitor' ); ?></caption>
			<thead><tr>
				<th scope="col"><?php echo esc_html__( 'Checked at', 'od-wordpress-monitor' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'Check', 'od-wordpress-monitor' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'Status', 'od-wordpress-monitor' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'Duration', 'od-wordpress-monitor' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'Error', 'od-wordpress-monitor' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'Message', 'od-wordpress-monitor' ); ?></th>
			</tr></thead>
			<tbody>
				<?php foreach ( $checks as $check ) : ?>
					<tr>
						<td><?php $this->render_date( $check->checked_at() ); ?></td>
						<td><?php echo esc_html( $this->check_label( $check->type() ) ); ?></td>
						<td><?php echo esc_html( StatusLabel::for_status( $check->status() ) ); ?></td>
						<td><?php echo esc_html( sprintf( /* translators: %d: request duration in milliseconds. */ __( '%d ms', 'od-wordpress-monitor' ), $check->duration_ms() ) ); ?></td>
						<td><?php echo esc_html( $check->error_code() ?? '—' ); ?></td>
						<td><?php echo esc_html( $check->message() ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function status_value( ?SiteStatus $status, string $type ): string {
		if ( null === $status ) {
			return __( 'Unknown', 'od-wordpress-monitor' );
		}

		$value = match ( $type ) {
			'overall'     => $status->overall_status(),
			'http'        => $status->http_status(),
			'agent'       => $status->agent_status(),
			'updates'     => $status->updates_status(),
			'site_health' => $status->site_health_status(),
			'ssl'         => $status->ssl_status(),
			default       => 'unknown',
		};

		return StatusLabel::for_status( $value );
	}

	/**
	 * @return array<string,string>
	 */
	private function versions( Site $site, ?SiteStatus $status ): array {
		$metadata  = null === $status ? array() : $status->metadata();
		$agent     = isset( $metadata['agent_status'] ) && is_array( $metadata['agent_status'] ) ? $metadata['agent_status'] : array();
		$cached    = $this->site_service->cached_status( $site );
		$wordpress = $agent['wordpress'] ?? $metadata['wordpress_version'] ?? null;
		$php       = $agent['php'] ?? $metadata['php_version'] ?? null;

		if (
			! is_string( $wordpress )
			&& is_array( $cached )
			&& isset( $cached['wordpress'] )
			&& is_array( $cached['wordpress'] )
		) {
			$wordpress = $cached['wordpress']['version'] ?? null;
		}

		if (
			! is_string( $php )
			&& is_array( $cached )
			&& isset( $cached['server'] )
			&& is_array( $cached['server'] )
		) {
			$php = $cached['server']['php_version'] ?? null;
		}

		return array(
			'wordpress' => is_string( $wordpress ) && '' !== $wordpress ? $wordpress : '—',
			'php'       => is_string( $php ) && '' !== $php ? $php : '—',
		);
	}

	private function event_label( string $type ): string {
		return match ( $type ) {
			EventType::SITE_DOWN             => __( 'Site down', 'od-wordpress-monitor' ),
			EventType::RECOVERED             => __( 'Recovered', 'od-wordpress-monitor' ),
			EventType::AGENT                 => __( 'Agent', 'od-wordpress-monitor' ),
			EventType::UPDATES               => __( 'Updates', 'od-wordpress-monitor' ),
			EventType::SITE_HEALTH_CRITICAL  => __( 'Site Health problem', 'od-wordpress-monitor' ),
			EventType::SITE_HEALTH_PARTIALLY_RECOVERED => __( 'Site Health partially recovered', 'od-wordpress-monitor' ),
			EventType::SITE_HEALTH_RECOVERED => __( 'Site Health recovered', 'od-wordpress-monitor' ),
			EventType::SSL                   => __( 'SSL', 'od-wordpress-monitor' ),
			default                          => __( 'Unknown event', 'od-wordpress-monitor' ),
		};
	}

	private function check_label( string $type ): string {
		return match ( $type ) {
			'http'         => __( 'HTTP', 'od-wordpress-monitor' ),
			'agent_ping'   => __( 'Agent ping', 'od-wordpress-monitor' ),
			'agent_status' => __( 'Agent status', 'od-wordpress-monitor' ),
			'updates'      => __( 'Updates', 'od-wordpress-monitor' ),
			'site_health'  => __( 'Site Health', 'od-wordpress-monitor' ),
			'ssl'          => __( 'SSL', 'od-wordpress-monitor' ),
			default        => __( 'Unknown check', 'od-wordpress-monitor' ),
		};
	}

	private function render_date( ?DateTimeImmutable $date ): void {
		if ( null === $date ) {
			echo esc_html( '—' );
			return;
		}

		$format = sprintf( '%s %s', get_option( 'date_format' ), get_option( 'time_format' ) );
		printf(
			'<time datetime="%1$s">%2$s</time>',
			esc_attr( $date->format( DATE_ATOM ) ),
			esc_html( wp_date( $format, $date->getTimestamp() ) )
		);
	}
}
