<?php
/**
 * SSL certificate monitor.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Monitor\Monitoring;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Olein\WordPressMonitor\Http\UrlValidator;
use Olein\WordPressMonitor\Monitor\CheckResult;
use Olein\WordPressMonitor\Monitor\MonitorInterface;
use Olein\WordPressMonitor\Site\Site;
use Olein\WordPressMonitor\Support\ErrorCode;
use WP_Error;

final class SslMonitor implements MonitorInterface {
	public const TYPE                 = 'ssl';
	public const TIMEOUT              = 10;
	public const DEFAULT_WARNING_DAYS = 30;
	public const DEFAULT_FAILURE_DAYS = 0;

	private const ERROR_CODES = array(
		ErrorCode::CERTIFICATE_EXPIRED,
		ErrorCode::CERTIFICATE_VALIDATION_FAILED,
		ErrorCode::CONNECTION_ERROR,
		ErrorCode::INVALID_CERTIFICATE,
		ErrorCode::SSL_UNAVAILABLE,
		ErrorCode::TIMEOUT,
	);

	private readonly Closure $clock;
	private readonly Closure $now;
	private readonly UrlValidator $url_validator;

	public function __construct(
		private readonly SslCertificateClientInterface $client,
		private readonly int $warning_days = self::DEFAULT_WARNING_DAYS,
		private readonly int $failure_days = self::DEFAULT_FAILURE_DAYS,
		?Closure $clock = null,
		?Closure $now = null,
		?UrlValidator $url_validator = null
	) {
		if ( $failure_days < 0 || $warning_days <= $failure_days ) {
			throw new InvalidArgumentException( 'SSL thresholds must be non-negative and warning must exceed failure.' );
		}

		$this->clock         = $clock ?? static fn(): float => microtime( true );
		$this->now           = $now ?? static fn(): DateTimeImmutable => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$this->url_validator = $url_validator ?? new UrlValidator();
	}

	public function get_type(): string {
		return self::TYPE;
	}

	public function check( Site $site ): CheckResult {
		if ( null === $site->id() || $site->id() < 1 ) {
			throw new InvalidArgumentException( 'SSL monitoring requires a registered site.' );
		}

		$started_at = ( $this->now )()->setTimezone( new DateTimeZone( 'UTC' ) );
		$started    = ( $this->clock )();
		$target     = $this->target( $site->site_url() );

		if ( is_wp_error( $target ) ) {
			return $this->result(
				$site,
				$started_at,
				$started,
				CheckResult::STATUS_CRITICAL,
				ErrorCode::INVALID_URL,
				__( 'The registered site URL is not a valid public HTTPS URL.', 'od-wordpress-monitor' )
			);
		}

		$certificate = $this->client->inspect( $target['host'], $target['port'], self::TIMEOUT );
		$data        = array(
			'host'         => $target['host'],
			'port'         => $target['port'],
			'warning_days' => $this->warning_days,
			'failure_days' => $this->failure_days,
		);

		if ( is_wp_error( $certificate ) ) {
			return $this->certificate_error_result( $site, $started_at, $started, $certificate, $data );
		}

		if ( $certificate['valid_from'] >= $certificate['valid_to'] ) {
			return $this->certificate_error_result( $site, $started_at, $started, new WP_Error( ErrorCode::INVALID_CERTIFICATE ), $data );
		}

		$now_timestamp     = $started_at->getTimestamp();
		$remaining_seconds = $certificate['valid_to'] - $now_timestamp;

		$data['valid_from'] = gmdate( 'Y-m-d\TH:i:s\Z', $certificate['valid_from'] );
		$data['valid_to']   = gmdate( 'Y-m-d\TH:i:s\Z', $certificate['valid_to'] );
		$data['days_left']  = (int) floor( $remaining_seconds / DAY_IN_SECONDS );

		if ( $certificate['valid_from'] > $now_timestamp ) {
			return $this->result(
				$site,
				$started_at,
				$started,
				CheckResult::STATUS_CRITICAL,
				ErrorCode::CERTIFICATE_NOT_YET_VALID,
				__( 'The SSL certificate is not yet valid.', 'od-wordpress-monitor' ),
				$data
			);
		}

		if ( $remaining_seconds <= 0 ) {
			return $this->result(
				$site,
				$started_at,
				$started,
				CheckResult::STATUS_CRITICAL,
				ErrorCode::CERTIFICATE_EXPIRED,
				__( 'The SSL certificate has expired.', 'od-wordpress-monitor' ),
				$data
			);
		}

		if ( $remaining_seconds <= $this->failure_days * DAY_IN_SECONDS ) {
			return $this->result(
				$site,
				$started_at,
				$started,
				CheckResult::STATUS_CRITICAL,
				ErrorCode::CERTIFICATE_EXPIRING,
				__( 'The SSL certificate is within the failure threshold.', 'od-wordpress-monitor' ),
				$data
			);
		}

		if ( $remaining_seconds <= $this->warning_days * DAY_IN_SECONDS ) {
			return $this->result(
				$site,
				$started_at,
				$started,
				CheckResult::STATUS_WARNING,
				ErrorCode::CERTIFICATE_EXPIRING,
				__( 'The SSL certificate is approaching expiration.', 'od-wordpress-monitor' ),
				$data
			);
		}

		return $this->result(
			$site,
			$started_at,
			$started,
			CheckResult::STATUS_HEALTHY,
			null,
			__( 'The SSL certificate is valid.', 'od-wordpress-monitor' ),
			$data
		);
	}

	/**
	 * Validate and extract the registered TLS target.
	 *
	 * @return array{host:string,port:int}|WP_Error
	 */
	private function target( string $url ): array|WP_Error {
		$validated = $this->url_validator->validate( $url );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$parts = wp_parse_url( $validated );

		if (
			! is_array( $parts )
			|| 'https' !== ( $parts['scheme'] ?? null )
			|| ! isset( $parts['host'] )
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
		) {
			return new WP_Error( ErrorCode::INVALID_URL );
		}

		$port = isset( $parts['port'] ) ? (int) $parts['port'] : 443;

		if ( $port < 1 || $port > 65535 ) {
			return new WP_Error( ErrorCode::INVALID_URL );
		}

		return array(
			'host' => strtolower( (string) $parts['host'] ),
			'port' => $port,
		);
	}

	/**
	 * Normalize a TLS client error without retaining its raw details.
	 *
	 * @param array<string,mixed> $data Safe result metadata.
	 */
	private function certificate_error_result( Site $site, DateTimeImmutable $started_at, float $started, WP_Error $error, array $data ): CheckResult {
		$error_code = $error->get_error_code();
		$error_code = is_string( $error_code ) && in_array( $error_code, self::ERROR_CODES, true ) ? $error_code : ErrorCode::CERTIFICATE_ERROR;
		$message    = match ( $error_code ) {
			ErrorCode::TIMEOUT                       => __( 'The SSL certificate check timed out.', 'od-wordpress-monitor' ),
			ErrorCode::CONNECTION_ERROR              => __( 'The SSL endpoint could not be reached.', 'od-wordpress-monitor' ),
			ErrorCode::CERTIFICATE_EXPIRED           => __( 'The SSL certificate has expired.', 'od-wordpress-monitor' ),
			ErrorCode::CERTIFICATE_VALIDATION_FAILED => __( 'The SSL certificate could not be verified.', 'od-wordpress-monitor' ),
			ErrorCode::SSL_UNAVAILABLE               => __( 'SSL certificate inspection is unavailable.', 'od-wordpress-monitor' ),
			ErrorCode::INVALID_CERTIFICATE           => __( 'The SSL endpoint returned an invalid certificate.', 'od-wordpress-monitor' ),
			default                         => __( 'The SSL certificate check failed.', 'od-wordpress-monitor' ),
		};

		return $this->result( $site, $started_at, $started, CheckResult::STATUS_CRITICAL, $error_code, $message, $data );
	}

	/**
	 * Create a normalized SSL result.
	 *
	 * @param array<string,mixed> $data Safe result metadata.
	 */
	private function result(
		Site $site,
		DateTimeImmutable $started_at,
		float $started,
		string $status,
		?string $error_code,
		string $message,
		array $data = array()
	): CheckResult {
		$finished_at = ( $this->now )()->setTimezone( new DateTimeZone( 'UTC' ) );
		$duration_ms = max( 0, (int) round( ( ( $this->clock )() - $started ) * 1000 ) );

		return new CheckResult(
			(int) $site->id(),
			$this->get_type(),
			$status,
			$error_code,
			$message,
			$started_at,
			$finished_at < $started_at ? $started_at : $finished_at,
			$duration_ms,
			$data
		);
	}
}
