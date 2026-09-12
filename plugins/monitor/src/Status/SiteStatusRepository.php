<?php
/**
 * Current site status persistence.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Status;

use DateTimeImmutable;
use DateTimeZone;
use Olein\WordPressMonitor\Support\MetadataCodec;
use WP_Error;
use wpdb;

final class SiteStatusRepository {
	private readonly string $table;

	public function __construct(
		private readonly wpdb $database,
		private readonly MetadataCodec $metadata_codec = new MetadataCodec()
	) {
		$this->table = $database->prefix . 'odm_site_status';
	}

	/**
	 * Insert or update the one current-state row for a site.
	 *
	 * @return bool|WP_Error
	 */
	public function upsert( SiteStatus $status ) {
		$metadata = $this->metadata_codec->encode( $status->metadata() );

		if ( is_wp_error( $metadata ) ) {
			return $metadata;
		}

		$data = array(
			'overall_status'         => $status->overall_status(),
			'http_status'            => $status->http_status(),
			'http_checked_at'        => $this->format_date( $status->http_checked_at() ),
			'agent_status'           => $status->agent_status(),
			'agent_checked_at'       => $this->format_date( $status->agent_checked_at() ),
			'updates_status'         => $status->updates_status(),
			'updates_checked_at'     => $this->format_date( $status->updates_checked_at() ),
			'site_health_status'     => $status->site_health_status(),
			'site_health_checked_at' => $this->format_date( $status->site_health_checked_at() ),
			'ssl_status'             => $status->ssl_status(),
			'ssl_checked_at'         => $this->format_date( $status->ssl_checked_at() ),
			'last_checked_at'        => $this->format_date( $status->last_checked_at() ),
			'last_error_code'        => $status->last_error_code(),
			'last_message'           => $status->last_message(),
			'metadata'               => $metadata,
			'updated_at'             => current_time( 'mysql', true ),
		);

		$existing = $this->find( $status->site_id() );

		if ( null === $existing ) {
			$result = $this->database->insert(
				$this->table,
				array_merge( array( 'site_id' => $status->site_id() ), $data )
			);
		} else {
			$result = $this->database->update( $this->table, $data, array( 'site_id' => $status->site_id() ) );
		}

		if ( false === $result ) {
			return new WP_Error( 'DATABASE_ERROR', __( 'The site status could not be saved.', 'od-wordpress-monitor' ) );
		}

		return true;
	}

	public function find( int $site_id ): ?SiteStatus {
		return $this->find_with_query( $site_id, false );
	}

	/**
	 * Return the current status rows for all sites.
	 *
	 * @return list<SiteStatus>
	 */
	public function all(): array {
		$sql  = "SELECT * FROM {$this->table}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->database->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Return a bounded set of current status rows in stable site order.
	 *
	 * @return list<SiteStatus>
	 */
	public function bounded( int $limit ): array {
		$sql  = $this->database->prepare( "SELECT * FROM {$this->table} ORDER BY site_id ASC LIMIT %d", max( 1, $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->database->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Find and lock a site's current row during a persistence transaction.
	 */
	public function find_for_update( int $site_id ): ?SiteStatus {
		return $this->find_with_query( $site_id, true );
	}

	private function find_with_query( int $site_id, bool $for_update ): ?SiteStatus {
		$locking_clause = $for_update ? ' FOR UPDATE' : '';
		$sql            = $this->database->prepare( "SELECT * FROM {$this->table} WHERE site_id = %d{$locking_clause}", $site_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row            = $this->database->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * @param array<string,mixed> $row Database row.
	 */
	private function hydrate( array $row ): SiteStatus {
		return new SiteStatus(
			(int) $row['site_id'],
			(string) $row['overall_status'],
			(string) $row['http_status'],
			$this->parse_date( $row['http_checked_at'] ),
			(string) $row['agent_status'],
			$this->parse_date( $row['agent_checked_at'] ),
			(string) $row['updates_status'],
			$this->parse_date( $row['updates_checked_at'] ),
			(string) $row['site_health_status'],
			$this->parse_date( $row['site_health_checked_at'] ),
			(string) $row['ssl_status'],
			$this->parse_date( $row['ssl_checked_at'] ),
			$this->parse_date( $row['last_checked_at'] ),
			null === $row['last_error_code'] ? null : (string) $row['last_error_code'],
			(string) $row['last_message'],
			$this->metadata_codec->decode( null === $row['metadata'] ? null : (string) $row['metadata'] ),
			$this->parse_date( $row['updated_at'] )
		);
	}

	private function format_date( ?DateTimeImmutable $date ): ?string {
		return null === $date ? null : $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * @param mixed $date Database datetime.
	 */
	private function parse_date( $date ): ?DateTimeImmutable {
		return null === $date || '' === $date ? null : new DateTimeImmutable( (string) $date, new DateTimeZone( 'UTC' ) );
	}
}
