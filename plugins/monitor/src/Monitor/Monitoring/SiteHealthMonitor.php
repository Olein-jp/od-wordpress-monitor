<?php
/**
 * WordPress Site Health monitor.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Monitor\Monitoring;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Olein\WordPressMonitor\Credential\CredentialService;
use Olein\WordPressMonitor\Http\AgentClient;
use Olein\WordPressMonitor\Monitor\CheckResult;
use Olein\WordPressMonitor\Monitor\MonitorInterface;
use Olein\WordPressMonitor\Site\Site;
use Olein\WordPressMonitor\Support\ErrorCode;
use WP_Error;

final class SiteHealthMonitor implements MonitorInterface {
	public const TYPE = 'site_health';

	private const ERROR_CODES = array(
		ErrorCode::AGENT_NOT_FOUND,
		ErrorCode::AUTHENTICATION_FAILED,
		ErrorCode::CONNECTION_ERROR,
		ErrorCode::CREDENTIAL_DECRYPTION_FAILED,
		ErrorCode::CREDENTIAL_NOT_FOUND,
		ErrorCode::HTTPS_REQUIRED,
		ErrorCode::INVALID_JSON,
		ErrorCode::INVALID_RESPONSE,
		ErrorCode::INVALID_URL,
		ErrorCode::PERMISSION_DENIED,
		ErrorCode::REDIRECT_LIMIT,
		ErrorCode::TIMEOUT,
		ErrorCode::UNSAFE_REDIRECT,
		ErrorCode::UNSUPPORTED_SCHEMA,
	);

	private readonly Closure $clock;

	public function __construct(
		private readonly AgentClient $agent_client,
		private readonly CredentialService $credential_service,
		?Closure $clock = null
	) {
		$this->clock = $clock ?? static fn(): float => microtime( true );
	}

	public function get_type(): string {
		return self::TYPE;
	}

	public function check( Site $site ): CheckResult {
		if ( null === $site->id() || $site->id() < 1 ) {
			throw new InvalidArgumentException( 'Site Health monitoring requires a registered site.' );
		}

		$started_at = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$started    = ( $this->clock )();
		$credential = $this->credential_service->for_site( $site->id() );

		if ( is_wp_error( $credential ) ) {
			return $this->error_result( $site, $started_at, $started, $credential );
		}

		$response = $this->agent_client->site_health( $site, $credential );
		unset( $credential );

		if ( is_wp_error( $response ) ) {
			return $this->error_result( $site, $started_at, $started, $response );
		}

		$summary = $response['summary'];
		$status  = CheckResult::STATUS_HEALTHY;
		$message = __( 'Site Health reports no problems.', 'od-wordpress-monitor' );
		$issues  = array_values(
			array_filter(
				$response['tests'],
				static fn( array $test ): bool => in_array( $test['status'], array( 'critical', 'recommended' ), true )
			)
		);

		if ( $summary['critical'] > 0 ) {
			$status  = CheckResult::STATUS_CRITICAL;
			$message = __( 'Site Health reports a critical problem.', 'od-wordpress-monitor' );
		} elseif ( $summary['recommended'] > 0 ) {
			$status  = CheckResult::STATUS_WARNING;
			$message = __( 'Site Health reports a recommended improvement.', 'od-wordpress-monitor' );
		}

		return $this->result(
			$site,
			$started_at,
			$started,
			$status,
			null,
			$message,
			array(
				'critical'     => $summary['critical'],
				'recommended'  => $summary['recommended'],
				'good'         => $summary['good'],
				'issues'       => $issues,
				'collected_at' => $response['timestamp'],
			)
		);
	}

	private function error_result( Site $site, DateTimeImmutable $started_at, float $started, WP_Error $error ): CheckResult {
		$error_code = $error->get_error_code();
		$error_code = is_string( $error_code ) && in_array( $error_code, self::ERROR_CODES, true ) ? $error_code : ErrorCode::AGENT_ERROR;
		$message    = match ( $error_code ) {
			ErrorCode::AUTHENTICATION_FAILED                         => __( 'Agent authentication failed.', 'od-wordpress-monitor' ),
			ErrorCode::PERMISSION_DENIED                             => __( 'The Agent credential lacks the required permission.', 'od-wordpress-monitor' ),
			ErrorCode::TIMEOUT                                       => __( 'The Agent request timed out.', 'od-wordpress-monitor' ),
			ErrorCode::CONNECTION_ERROR                              => __( 'The Agent could not be reached.', 'od-wordpress-monitor' ),
			ErrorCode::AGENT_NOT_FOUND                               => __( 'The Agent endpoint was not found.', 'od-wordpress-monitor' ),
			ErrorCode::HTTPS_REQUIRED                                => __( 'The Agent URL must use HTTPS.', 'od-wordpress-monitor' ),
			ErrorCode::INVALID_URL,
			ErrorCode::REDIRECT_LIMIT,
			ErrorCode::UNSAFE_REDIRECT                               => __( 'The Agent URL was blocked by the outbound security policy.', 'od-wordpress-monitor' ),
			ErrorCode::CREDENTIAL_NOT_FOUND,
			ErrorCode::CREDENTIAL_DECRYPTION_FAILED                  => __( 'The stored Agent credential is unavailable.', 'od-wordpress-monitor' ),
			ErrorCode::INVALID_JSON,
			ErrorCode::INVALID_RESPONSE,
			ErrorCode::UNSUPPORTED_SCHEMA                            => __( 'The Agent returned an invalid Site Health response.', 'od-wordpress-monitor' ),
			default                                                  => __( 'The Site Health check failed.', 'od-wordpress-monitor' ),
		};

		return $this->result(
			$site,
			$started_at,
			$started,
			CheckResult::STATUS_CRITICAL,
			$error_code,
			$message,
			array()
		);
	}

	/**
	 * @param array<string,mixed> $data Safe result metadata.
	 */
	private function result(
		Site $site,
		DateTimeImmutable $started_at,
		float $started,
		string $status,
		?string $error_code,
		string $message,
		array $data
	): CheckResult {
		$finished_at = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$duration_ms = max( 0, (int) round( ( ( $this->clock )() - $started ) * 1000 ) );

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
}
