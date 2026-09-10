<?php
/**
 * Monitor check history persistence.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Check;

use DateTimeImmutable;
use DateTimeZone;
use Olein\WordPressMonitor\Monitor\CheckMetadata;
use Olein\WordPressMonitor\Monitor\CheckResult;
use Olein\WordPressMonitor\Support\MetadataCodec;
use WP_Error;
use wpdb;

final class CheckRepository {
	private readonly string $table;

	public function __construct(
		private readonly wpdb $database,
		private readonly MetadataCodec $metadata_codec = new MetadataCodec(),
		private readonly CheckMetadata $check_metadata = new CheckMetadata()
	) {
		$this->table = $database->prefix . 'odm_checks';
	}

	/**
	 * Persist a completed monitor result.
	 *
	 * @return int|WP_Error
	 */
	public function create( CheckResult $check ) {
		$metadata = $this->metadata_codec->encode( $this->check_metadata->for_result( $check ) );

		if ( is_wp_error( $metadata ) ) {
			return $metadata;
		}

		$result = $this->database->insert(
			$this->table,
			array(
				'site_id'     => $check->site_id(),
				'check_type'  => $check->type(),
				'status'      => $check->status(),
				'error_code'  => $check->error_code(),
				'message'     => $check->message(),
				'duration_ms' => $check->duration_ms(),
				'metadata'    => $metadata,
				'started_at'  => $this->format_date( $check->started_at() ),
				'finished_at' => $this->format_date( $check->finished_at() ),
				'checked_at'  => $this->format_date( $check->finished_at() ),
			)
		);

		if ( false === $result ) {
			return new WP_Error( 'DATABASE_ERROR', __( 'The check result could not be saved.', 'od-wordpress-monitor' ) );
		}

		return (int) $this->database->insert_id;
	}

	/**
	 * Delete checks strictly older than a UTC cutoff.
	 *
	 * @return int|WP_Error Number of deleted rows or a safe failure.
	 */
	public function delete_before( DateTimeImmutable $cutoff, int $limit = 100 ): int|WP_Error {
		$cutoff = $this->format_date( $cutoff );
		$sql    = $this->database->prepare( "DELETE FROM {$this->table} WHERE checked_at < %s ORDER BY checked_at ASC, id ASC LIMIT %d", $cutoff, max( 1, $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $this->database->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( false === $result ) {
			return new WP_Error( 'DATABASE_ERROR', __( 'Old check results could not be deleted.', 'od-wordpress-monitor' ) );
		}

		return $result;
	}

	public function find( int $id ): ?CheckRecord {
		$sql = $this->database->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->database->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Return a site's newest checks first.
	 *
	 * @return list<CheckRecord>
	 */
	public function for_site( int $site_id, int $limit = 50 ): array {
		$limit = max( 1, $limit );
		$sql   = $this->database->prepare( "SELECT * FROM {$this->table} WHERE site_id = %d ORDER BY checked_at DESC, id DESC LIMIT %d", $site_id, $limit ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $this->database->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * @param array<string,mixed> $row Database row.
	 */
	private function hydrate( array $row ): CheckRecord {
		return new CheckRecord(
			(int) $row['id'],
			(int) $row['site_id'],
			(string) $row['check_type'],
			(string) $row['status'],
			null === $row['error_code'] ? null : (string) $row['error_code'],
			(string) $row['message'],
			$this->parse_date( $row['started_at'] ),
			$this->parse_date( $row['finished_at'] ),
			(int) $row['duration_ms'],
			$this->metadata_codec->decode( null === $row['metadata'] ? null : (string) $row['metadata'] )
		);
	}

	private function format_date( DateTimeImmutable $date ): string {
		return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * @param mixed $date Database datetime.
	 */
	private function parse_date( $date ): DateTimeImmutable {
		return new DateTimeImmutable( (string) $date, new DateTimeZone( 'UTC' ) );
	}
}
