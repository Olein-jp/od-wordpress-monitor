<?php
/**
 * Encrypted credential persistence.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Credential;

use WP_Error;
use wpdb;

final class CredentialRepository {
	private readonly string $table;

	public function __construct( private readonly wpdb $database ) {
		$this->table = $database->prefix . 'odm_credentials';
	}

	/**
	 * Persist encrypted credential data.
	 *
	 * @return int|WP_Error
	 */
	public function create( int $site_id, string $username, string $encrypted_password ) {
		$now    = current_time( 'mysql', true );
		$result = $this->database->insert(
			$this->table,
			array(
				'site_id'            => $site_id,
				'username'           => $username,
				'encrypted_password' => $encrypted_password,
				'created_at'         => $now,
				'updated_at'         => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return new WP_Error( 'DATABASE_ERROR', __( 'The credential could not be saved.', 'od-wordpress-monitor' ) );
		}

		return (int) $this->database->insert_id;
	}

	/**
	 * Find encrypted credential data for a site.
	 *
	 * @return array{username:string,encrypted_password:string}|null
	 */
	public function find_by_site( int $site_id ): ?array {
		$sql = $this->database->prepare( "SELECT username, encrypted_password FROM {$this->table} WHERE site_id = %d", $site_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->database->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $row ) ) {
			return null;
		}

		return array(
			'username'           => (string) $row['username'],
			'encrypted_password' => (string) $row['encrypted_password'],
		);
	}
}
