<?php
/**
 * Site persistence.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Site;

use WP_Error;
use wpdb;

final class SiteRepository {
	private readonly string $table;

	public function __construct( private readonly wpdb $database ) {
		$this->table = $database->prefix . 'odm_sites';
	}

	/**
	 * Insert a site.
	 *
	 * @return int|WP_Error
	 */
	public function create( Site $site ) {
		$now    = current_time( 'mysql', true );
		$result = $this->database->insert(
			$this->table,
			array(
				'uuid'       => $site->uuid(),
				'name'       => $site->name(),
				'site_url'   => $site->site_url(),
				'agent_url'  => $site->agent_url(),
				'enabled'    => $site->enabled() ? 1 : 0,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false === $result ) {
			return new WP_Error( 'DATABASE_ERROR', __( 'The site could not be saved.', 'od-wordpress-monitor' ) );
		}

		return (int) $this->database->insert_id;
	}

	public function find( int $id ): ?Site {
		$sql = $this->database->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->database->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Return all monitored sites.
	 *
	 * @return list<Site>
	 */
	public function all(): array {
		$sql  = "SELECT * FROM {$this->table} ORDER BY name ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->database->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Return enabled monitored sites.
	 *
	 * @return list<Site>
	 */
	public function enabled(): array {
		$sql  = "SELECT * FROM {$this->table} WHERE enabled = 1 ORDER BY name ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->database->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Return a bounded keyset page of enabled sites in stable ID order.
	 *
	 * @return list<Site>
	 */
	public function enabled_after( int $cursor, int $limit ): array {
		$sql  = $this->database->prepare(
			"SELECT * FROM {$this->table} WHERE enabled = 1 AND id > %d ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			max( 0, $cursor ),
			max( 1, $limit )
		);
		$rows = $this->database->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	public function update( Site $site ): bool {
		if ( null === $site->id() ) {
			return false;
		}

		$result = $this->database->update(
			$this->table,
			array(
				'name'       => $site->name(),
				'site_url'   => $site->site_url(),
				'agent_url'  => $site->agent_url(),
				'enabled'    => $site->enabled() ? 1 : 0,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $site->id() ),
			array( '%s', '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	public function delete( int $id ): bool {
		return false !== $this->database->delete( $this->table, array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Hydrate a site from a database row.
	 *
	 * @param array<string,mixed> $row Database row.
	 */
	private function hydrate( array $row ): Site {
		return new Site(
			(int) $row['id'],
			(string) $row['uuid'],
			(string) $row['name'],
			(string) $row['site_url'],
			(string) $row['agent_url'],
			(bool) $row['enabled']
		);
	}
}
