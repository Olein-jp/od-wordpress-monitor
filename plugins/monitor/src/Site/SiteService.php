<?php
/**
 * Monitored site workflows.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Site;

use Olein\WordPressMonitor\Credential\Credential;
use Olein\WordPressMonitor\Credential\CredentialService;
use Olein\WordPressMonitor\Http\AgentClient;
use Olein\WordPressMonitor\Support\ErrorCode;
use Olein\WordPressMonitor\Support\UUID;
use WP_Error;

final class SiteService {
	public function __construct(
		private readonly SiteRepository $sites,
		private readonly CredentialService $credentials,
		private readonly AgentClient $agent,
		private readonly UUID $uuid
	) {
	}

	/**
	 * Validate, connect, and atomically persist a new site as far as WordPress APIs allow.
	 *
	 * @return array{site:Site,status:array<string,mixed>}|WP_Error
	 */
	public function register( string $name, string $site_url, string $username, string $password ) {
		$name     = sanitize_text_field( $name );
		$username = sanitize_text_field( $username );

		if ( '' === $name || '' === $username || '' === $password ) {
			return new WP_Error( ErrorCode::INVALID_RESPONSE, __( 'All fields are required.', 'od-wordpress-monitor' ) );
		}

		$normalized_url = $this->normalize_url( $site_url );

		if ( is_wp_error( $normalized_url ) ) {
			return $normalized_url;
		}

		$site       = new Site(
			null,
			$this->uuid->generate(),
			$name,
			$normalized_url,
			trailingslashit( $normalized_url ) . 'wp-json/od-monitor-agent/v1'
		);
		$credential = new Credential( $username, $password );

		$ping = $this->agent->ping( $site, $credential );
		if ( is_wp_error( $ping ) ) {
			return $ping;
		}

		$status = $this->agent->status( $site, $credential );
		if ( is_wp_error( $status ) ) {
			return $status;
		}

		$site_id = $this->sites->create( $site );
		if ( is_wp_error( $site_id ) ) {
			return $site_id;
		}

		$credential_id = $this->credentials->store( $site_id, $credential );
		if ( is_wp_error( $credential_id ) ) {
			$this->sites->delete( $site_id );
			return $credential_id;
		}

		$saved_site = new Site( $site_id, $site->uuid(), $site->name(), $site->site_url(), $site->agent_url(), true );
		$this->cache_status( $saved_site, $status );

		return array(
			'site'   => $saved_site,
			'status' => $status,
		);
	}

	/**
	 * Test a stored site's connection.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function test_connection( int $site_id ) {
		$site = $this->sites->find( $site_id );

		if ( null === $site ) {
			return new WP_Error( 'SITE_NOT_FOUND', __( 'The monitored site was not found.', 'od-wordpress-monitor' ) );
		}

		$credential = $this->credentials->for_site( $site_id );
		if ( is_wp_error( $credential ) ) {
			return $credential;
		}

		$ping = $this->agent->ping( $site, $credential );
		if ( is_wp_error( $ping ) ) {
			return $ping;
		}

		$status = $this->agent->status( $site, $credential );
		if ( ! is_wp_error( $status ) ) {
			$this->cache_status( $site, $status );
		}

		return $status;
	}

	/**
	 * Return the most recently confirmed status without making a request.
	 *
	 * @return array<string,mixed>|false
	 */
	public function cached_status( Site $site ) {
		return get_transient( 'odm_status_' . $site->uuid() );
	}

	/**
	 * Normalize and validate a site URL.
	 *
	 * @return string|WP_Error
	 */
	private function normalize_url( string $url ) {
		$url = untrailingslashit( esc_url_raw( trim( $url ) ) );

		if ( ! wp_http_validate_url( $url ) || ! wp_parse_url( $url, PHP_URL_HOST ) ) {
			return new WP_Error( ErrorCode::INVALID_URL, __( 'Enter a valid public site URL.', 'od-wordpress-monitor' ) );
		}

		if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			return new WP_Error( ErrorCode::HTTPS_REQUIRED, __( 'The site URL must use HTTPS.', 'od-wordpress-monitor' ) );
		}

		return $url;
	}

	/**
	 * Cache only public status data, never credentials.
	 *
	 * @param array<string,mixed> $status Agent status.
	 */
	private function cache_status( Site $site, array $status ): void {
		set_transient( 'odm_status_' . $site->uuid(), $status, DAY_IN_SECONDS );
	}
}
