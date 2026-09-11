<?php
/**
 * Credential repository tests.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Tests;

use Olein\WordPressMonitor\Activation\DatabaseMigrator;
use Olein\WordPressMonitor\Credential\Credential;
use Olein\WordPressMonitor\Credential\CredentialEncryptor;
use Olein\WordPressMonitor\Credential\CredentialRepository;
use Olein\WordPressMonitor\Credential\CredentialService;
use Olein\WordPressMonitor\Site\Site;
use Olein\WordPressMonitor\Site\SiteRepository;

final class CredentialRepositoryTest extends \WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		global $wpdb;

		( new DatabaseMigrator( $wpdb ) )->migrate();
		$wpdb->query( "DELETE FROM {$wpdb->prefix}odm_credentials" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}odm_sites" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	public function test_only_encrypted_password_is_persisted(): void {
		global $wpdb;

		$sites   = new SiteRepository( $wpdb );
		$site_id = $sites->create(
			new Site( null, wp_generate_uuid4(), 'Example', 'https://example.com', 'https://example.com/wp-json/od-monitor-agent/v1' )
		);
		$repo    = new CredentialRepository( $wpdb );
		$service = new CredentialService(
			$repo,
			new CredentialEncryptor( str_repeat( 'k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) )
		);

		$credential_id = $service->store( $site_id, new Credential( 'agent-user', 'plain-secret' ) );
		$stored        = $repo->find_by_site( $site_id );
		$database_row  = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}odm_credentials WHERE site_id = {$site_id}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->assertIsInt( $credential_id );
		$this->assertIsArray( $database_row );
		$this->assertSame( 'agent-user', $stored['username'] );
		$this->assertStringNotContainsString( 'plain-secret', $stored['encrypted_password'] );
		$this->assertStringNotContainsString( 'plain-secret', wp_json_encode( $database_row ) );
		$this->assertSame( 'plain-secret', $service->for_site( $site_id )->password() );
	}
}
