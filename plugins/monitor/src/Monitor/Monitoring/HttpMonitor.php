<?php
/**
 * HTTP uptime monitor.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Monitor\Monitoring;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Olein\WordPressMonitor\Http\HttpClient;
use Olein\WordPressMonitor\Monitor\CheckResult;
use Olein\WordPressMonitor\Monitor\MonitorInterface;
use Olein\WordPressMonitor\Site\Site;
use Olein\WordPressMonitor\Support\ErrorCode;
use WP_Error;
use WP_Http;

final class HttpMonitor implements MonitorInterface {
	public const TYPE          = 'http';
	public const TIMEOUT       = 10;
	public const MAX_REDIRECTS = 3;

	private readonly Closure $clock;

	public function __construct( private readonly HttpClient $http_client, ?Closure $clock = null ) {
		$this->clock = $clock ?? static fn(): float => microtime( true );
	}

	public function get_type(): string {
		return self::TYPE;
	}

	public function check( Site $site ): CheckResult {
		if ( null === $site->id() || $site->id() < 1 ) {
			throw new InvalidArgumentException( 'HTTP monitoring requires a registered site.' );
		}

		$started_at = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$started    = ( $this->clock )();
		$url        = $site->site_url();

		if ( ! $this->is_safe_url( $url ) ) {
			return $this->error_result(
				$site,
				$started_at,
				$started,
				ErrorCode::INVALID_URL,
				__( 'The registered site URL is not a valid public HTTP URL.', 'od-wordpress-monitor' )
			);
		}

		$redirect_count = 0;

		while ( true ) {
			$response = $this->http_client->get(
				$url,
				array(
					'timeout'             => self::TIMEOUT,
					'redirection'         => 0,
					'limit_response_size' => 1,
					'headers'             => array( 'Accept' => 'text/html,application/xhtml+xml' ),
				)
			);

			if ( is_wp_error( $response ) ) {
				return $this->transport_error_result( $site, $started_at, $started, $response, $url, $redirect_count );
			}

			$status_code = wp_remote_retrieve_response_code( $response );

			if ( $status_code >= 300 && $status_code < 400 ) {
				$location = wp_remote_retrieve_header( $response, 'location' );

				if ( ! is_string( $location ) || '' === trim( $location ) ) {
					return $this->http_status_result( $site, $started_at, $started, $status_code, $url, $redirect_count );
				}

				if ( $redirect_count >= self::MAX_REDIRECTS ) {
					return $this->error_result(
						$site,
						$started_at,
						$started,
						ErrorCode::REDIRECT_LIMIT,
						__( 'The site exceeded the allowed redirect limit.', 'od-wordpress-monitor' ),
						$status_code,
						$url,
						$redirect_count
					);
				}

				$redirect_url = WP_Http::make_absolute_url( trim( $location ), $url );

				if ( ! $this->is_safe_url( $redirect_url ) ) {
					return $this->error_result(
						$site,
						$started_at,
						$started,
						ErrorCode::UNSAFE_REDIRECT,
						__( 'The site redirected to an unsafe URL.', 'od-wordpress-monitor' ),
						$status_code,
						$url,
						$redirect_count
					);
				}

				$url = $redirect_url;
				++$redirect_count;
				continue;
			}

			return $this->http_status_result( $site, $started_at, $started, $status_code, $url, $redirect_count );
		}
	}

	/**
	 * Normalize a completed HTTP response.
	 */
	private function http_status_result( Site $site, DateTimeImmutable $started_at, float $started, int $status_code, string $url, int $redirect_count ): CheckResult {
		if ( $status_code >= 200 && $status_code < 300 ) {
			return $this->result(
				$site,
				$started_at,
				$started,
				CheckResult::STATUS_HEALTHY,
				null,
				__( 'The site is reachable.', 'od-wordpress-monitor' ),
				$status_code,
				$url,
				$redirect_count
			);
		}

		return $this->error_result(
			$site,
			$started_at,
			$started,
			ErrorCode::HTTP_STATUS,
			__( 'The site returned a non-success HTTP status.', 'od-wordpress-monitor' ),
			$status_code,
			$url,
			$redirect_count
		);
	}

	/**
	 * Normalize a WordPress HTTP API error without retaining its raw message.
	 */
	private function transport_error_result( Site $site, DateTimeImmutable $started_at, float $started, WP_Error $error, string $url, int $redirect_count ): CheckResult {
		$error_message = strtolower( $error->get_error_message() );
		$is_timeout    = str_contains( $error_message, 'timed out' ) || str_contains( $error_message, 'timeout' );

		return $this->error_result(
			$site,
			$started_at,
			$started,
			$is_timeout ? ErrorCode::TIMEOUT : ErrorCode::CONNECTION_ERROR,
			$is_timeout
				? __( 'The site request timed out.', 'od-wordpress-monitor' )
				: __( 'The site could not be reached.', 'od-wordpress-monitor' ),
			null,
			$url,
			$redirect_count
		);
	}

	/**
	 * Create a critical result.
	 */
	private function error_result(
		Site $site,
		DateTimeImmutable $started_at,
		float $started,
		string $error_code,
		string $message,
		?int $status_code = null,
		?string $url = null,
		int $redirect_count = 0
	): CheckResult {
		return $this->result(
			$site,
			$started_at,
			$started,
			CheckResult::STATUS_CRITICAL,
			$error_code,
			$message,
			$status_code,
			$url,
			$redirect_count
		);
	}

	/**
	 * Create a normalized result with minimal metadata.
	 */
	private function result(
		Site $site,
		DateTimeImmutable $started_at,
		float $started,
		string $status,
		?string $error_code,
		string $message,
		?int $status_code,
		?string $url,
		int $redirect_count
	): CheckResult {
		$finished_at = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$duration_ms = max( 0, (int) round( ( ( $this->clock )() - $started ) * 1000 ) );
		$data        = array( 'redirect_count' => $redirect_count );

		if ( null !== $status_code ) {
			$data['http_status'] = $status_code;
		}

		if ( null !== $url ) {
			$data['final_url'] = $this->public_url( $url );
		}

		return new CheckResult(
			(int) $site->id(),
			$this->get_type(),
			$status,
			$error_code,
			$message,
			$started_at,
			$finished_at,
			$duration_ms,
			$data
		);
	}

	private function is_safe_url( string $url ): bool {
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );

		return in_array( $scheme, array( 'http', 'https' ), true ) && false !== wp_http_validate_url( $url );
	}

	/**
	 * Remove query, fragment, and user information before persisting a URL.
	 */
	private function public_url( string $url ): string {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return '';
		}

		$public_url = $parts['scheme'] . '://' . $parts['host'];

		if ( isset( $parts['port'] ) ) {
			$public_url .= ':' . $parts['port'];
		}

		return $public_url . ( $parts['path'] ?? '' );
	}
}
