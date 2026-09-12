<?php
/**
 * Monitoring event history persistence.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Event;

use DateTimeImmutable;
use DateTimeZone;
use Olein\WordPressMonitor\Notification\NotificationDeliveryResult;
use Olein\WordPressMonitor\Notification\NotificationChannelResult;
use Olein\WordPressMonitor\Support\MetadataCodec;
use Throwable;
use WP_Error;
use wpdb;

final class EventRepository {
	private readonly string $table;

	public function __construct(
		private readonly wpdb $database,
		private readonly MetadataCodec $metadata_codec = new MetadataCodec()
	) {
		$this->table = $database->prefix . 'odm_events';
	}

	/**
	 * Persist one meaningful state-change event.
	 *
	 * @return int|WP_Error
	 */
	public function create( MonitoringEvent $event ) {
		$metadata = $this->metadata_codec->encode( $event->metadata() );

		if ( is_wp_error( $metadata ) ) {
			return $metadata;
		}

		$result = $this->database->insert(
			$this->table,
			array(
				'site_id'         => $event->site_id(),
				'event_type'      => $event->type(),
				'previous_status' => $event->previous_status(),
				'current_status'  => $event->current_status(),
				'error_code'      => $event->error_code(),
				'message'         => $event->message(),
				'metadata'        => $metadata,
				'occurred_at'     => $this->format_date( $event->occurred_at() ),
				'created_at'      => current_time( 'mysql', true ),
			)
		);

		if ( false === $result ) {
			return new WP_Error( 'DATABASE_ERROR', __( 'The event could not be saved.', 'od-wordpress-monitor' ) );
		}

		return (int) $this->database->insert_id;
	}

	public function find( int $id ): ?MonitoringEvent {
		$sql = $this->database->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->database->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Record a secret-free notification attempt on an existing event.
	 *
	 * @return bool|WP_Error
	 */
	public function record_notification_result( int $id, NotificationDeliveryResult $delivery, ?DateTimeImmutable $attempted_at = null ) {
		$event = $this->find( $id );

		if ( null === $event ) {
			return new WP_Error( 'EVENT_NOT_FOUND', __( 'The notification event could not be found.', 'od-wordpress-monitor' ) );
		}

		$channels  = array();
		$timestamp = ( $attempted_at ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )
			->setTimezone( new DateTimeZone( 'UTC' ) )
			->format( 'Y-m-d\TH:i:s\Z' );

		foreach ( $delivery->channels() as $channel_id => $channel ) {
			$channels[ $channel_id ] = array(
				'status'       => $channel->status(),
				'attempts'     => $channel->attempts(),
				'attempted_at' => $timestamp,
			);

			if ( null !== $channel->error_code() ) {
				$channels[ $channel_id ]['error_code'] = $channel->error_code();
			}
		}

		$metadata                 = $event->metadata();
		$metadata['notification'] = array(
			'status'    => $delivery->status(),
			'timestamp' => $timestamp,
			'channels'  => $channels,
		);
		$encoded                  = $this->metadata_codec->encode( $metadata );

		if ( is_wp_error( $encoded ) ) {
			return $encoded;
		}

		$result = $this->database->update(
			$this->table,
			array( 'metadata' => $encoded ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			return new WP_Error( 'DATABASE_ERROR', __( 'The notification result could not be saved.', 'od-wordpress-monitor' ) );
		}

		return true;
	}

	/**
	 * Merge one retry result while holding the event row lock, preserving other channels.
	 *
	 * @return bool|WP_Error False when the channel is no longer eligible.
	 */
	public function record_channel_retry_result( int $id, string $channel_id, NotificationChannelResult $result, DateTimeImmutable $attempted_at ): bool|WP_Error {
		if ( $result->channel_id() !== $channel_id || false === $this->database->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			return new WP_Error( 'DATABASE_ERROR', __( 'The notification result could not be saved.', 'od-wordpress-monitor' ) );
		}

		try {
			$sql   = $this->database->prepare( "SELECT * FROM {$this->table} WHERE id = %d FOR UPDATE", $id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row   = $this->database->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$event = is_array( $row ) ? $this->hydrate( $row ) : null;
			if ( null === $event ) {
				return $this->abort_retry( false );
			}

			$metadata     = $event->metadata();
			$notification = $metadata['notification'] ?? null;
			$channels     = is_array( $notification ) ? ( $notification['channels'] ?? null ) : null;
			$previous     = is_array( $channels ) ? ( $channels[ $channel_id ] ?? null ) : null;
			if ( ! is_array( $previous ) || NotificationChannelResult::FAILED !== ( $previous['status'] ?? null ) || 1 !== ( $previous['attempts'] ?? null ) ) {
				return $this->abort_retry( false );
			}

			$timestamp               = $attempted_at->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
			$channels[ $channel_id ] = array(
				'status'       => $result->status(),
				'attempts'     => 2,
				'attempted_at' => $timestamp,
			);
			if ( null !== $result->error_code() ) {
				$channels[ $channel_id ]['error_code'] = $result->error_code();
			}

			$sent                      = count( array_filter( $channels, static fn( array $channel ): bool => NotificationChannelResult::SENT === ( $channel['status'] ?? null ) ) );
			$notification['status']    = count( $channels ) === $sent ? NotificationDeliveryResult::SENT : ( 0 === $sent ? NotificationDeliveryResult::FAILED : NotificationDeliveryResult::PARTIAL );
			$notification['timestamp'] = $timestamp;
			$notification['channels']  = $channels;
			$metadata['notification']  = $notification;
			$encoded                   = $this->metadata_codec->encode( $metadata );
			if ( is_wp_error( $encoded ) ) {
				return $this->abort_retry( $encoded );
			}

			$updated = $this->database->update( $this->table, array( 'metadata' => $encoded ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
			if ( false === $updated || false === $this->database->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				return $this->abort_retry( new WP_Error( 'DATABASE_ERROR', __( 'The notification result could not be saved.', 'od-wordpress-monitor' ) ) );
			}

			return true;
		} catch ( Throwable $exception ) {
			unset( $exception );
			return $this->abort_retry( new WP_Error( 'DATABASE_ERROR', __( 'The notification result could not be saved.', 'od-wordpress-monitor' ) ) );
		}
	}

	/**
	 * @param bool|WP_Error $result Safe failure result.
	 * @return bool|WP_Error
	 */
	private function abort_retry( bool|WP_Error $result ): bool|WP_Error {
		$this->database->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $result;
	}

	/**
	 * Return a site's newest events first.
	 *
	 * @return list<MonitoringEvent>
	 */
	public function for_site( int $site_id, int $limit = 50 ): array {
		$limit = max( 1, $limit );
		$sql   = $this->database->prepare( "SELECT * FROM {$this->table} WHERE site_id = %d ORDER BY occurred_at DESC, id DESC LIMIT %d", $site_id, $limit ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $this->database->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * @param array<string,mixed> $row Database row.
	 */
	private function hydrate( array $row ): MonitoringEvent {
		return new MonitoringEvent(
			(int) $row['id'],
			(int) $row['site_id'],
			(string) $row['event_type'],
			(string) $row['previous_status'],
			(string) $row['current_status'],
			null === $row['error_code'] ? null : (string) $row['error_code'],
			(string) $row['message'],
			$this->parse_date( $row['occurred_at'] ),
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
