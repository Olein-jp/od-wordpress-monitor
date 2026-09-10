<?php
/**
 * Agent status monitor.
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

final class AgentStatusMonitor implements MonitorInterface {
	public const TYPE = 'agent_status';

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
			throw new InvalidArgumentException( 'Agent status monitoring requires a registered site.' );
		}

		$started_at = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$started    = ( $this->clock )();
		$credential = $this->credential_service->for_site( $site->id() );

		if ( is_wp_error( $credential ) ) {
			return $this->error_result( $site, $started_at, $started, $credential );
		}

		$response = $this->agent_client->status( $site, $credential );
		unset( $credential );

		if ( is_wp_error( $response ) ) {
			return $this->error_result( $site, $started_at, $started, $response );
		}

		return $this->result(
			$site,
			$started_at,
			$started,
			CheckResult::STATUS_HEALTHY,
			null,
			__( 'The Agent status is available.', 'od-wordpress-monitor' ),
			array(
				'endpoint'         => 'status',
				'schema_version'   => $response['schema_version'],
				'wordpress'        => $response['wordpress']['version'],
				'php'              => $response['server']['php_version'],
				'agent_version'    => $response['agent']['version'],
				'is_multisite'     => $response['wordpress']['multisite'],
				'environment_type' => $response['wordpress']['environment'],
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
			ErrorCode::UNSUPPORTED_SCHEMA                            => __( 'The Agent returned an invalid status response.', 'od-wordpress-monitor' ),
			default                                         => __( 'The Agent status check failed.', 'od-wordpress-monitor' ),
		};

		return $this->result(
			$site,
			$started_at,
			$started,
			CheckResult::STATUS_CRITICAL,
			$error_code,
			$message,
			array( 'endpoint' => 'status' )
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
