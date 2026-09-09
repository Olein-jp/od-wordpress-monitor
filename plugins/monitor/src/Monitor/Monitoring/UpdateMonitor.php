<?php
/**
 * Update availability monitor.
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

final class UpdateMonitor implements MonitorInterface {
	public const TYPE = 'updates';

	private const ERROR_CODES = array(
		ErrorCode::AGENT_NOT_FOUND,
		ErrorCode::AUTHENTICATION_FAILED,
		ErrorCode::CONNECTION_ERROR,
		ErrorCode::CREDENTIAL_DECRYPTION_FAILED,
		ErrorCode::CREDENTIAL_NOT_FOUND,
		ErrorCode::HTTPS_REQUIRED,
		ErrorCode::INVALID_JSON,
		ErrorCode::INVALID_RESPONSE,
		ErrorCode::PERMISSION_DENIED,
		ErrorCode::TIMEOUT,
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
			throw new InvalidArgumentException( 'Update monitoring requires a registered site.' );
		}

		$started_at = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$started    = ( $this->clock )();
		$credential = $this->credential_service->for_site( $site->id() );

		if ( is_wp_error( $credential ) ) {
			return $this->error_result( $site, $started_at, $started, $credential );
		}

		$response = $this->agent_client->updates( $site, $credential );
		unset( $credential );

		if ( is_wp_error( $response ) ) {
			return $this->error_result( $site, $started_at, $started, $response );
		}

		$summary      = $response['summary'];
		$update_types = array();

		foreach ( array( 'wordpress', 'plugins', 'themes' ) as $type ) {
			if ( $summary[ $type ] > 0 ) {
				$update_types[] = $type;
			}
		}

		$has_updates = $summary['total'] > 0;

		return $this->result(
			$site,
			$started_at,
			$started,
			$has_updates ? CheckResult::STATUS_WARNING : CheckResult::STATUS_HEALTHY,
			null,
			$has_updates
				? __( 'Updates are available.', 'od-wordpress-monitor' )
				: __( 'No updates are currently reported.', 'od-wordpress-monitor' ),
			array(
				'endpoint'          => 'updates',
				'schema_version'    => $response['schema_version'],
				'total_updates'     => $summary['total'],
				'wordpress_updates' => $summary['wordpress'],
				'plugin_updates'    => $summary['plugins'],
				'theme_updates'     => $summary['themes'],
				'update_types'      => $update_types,
			)
		);
	}

	/**
	 * Normalize an Agent or credential error without retaining raw response data.
	 */
	private function error_result( Site $site, DateTimeImmutable $started_at, float $started, WP_Error $error ): CheckResult {
		$error_code = $error->get_error_code();
		$error_code = is_string( $error_code ) && in_array( $error_code, self::ERROR_CODES, true ) ? $error_code : ErrorCode::AGENT_ERROR;

		$message = match ( $error_code ) {
			ErrorCode::AUTHENTICATION_FAILED                         => __( 'Agent authentication failed.', 'od-wordpress-monitor' ),
			ErrorCode::PERMISSION_DENIED                             => __( 'The Agent credential lacks the required permission.', 'od-wordpress-monitor' ),
			ErrorCode::TIMEOUT                                       => __( 'The Agent request timed out.', 'od-wordpress-monitor' ),
			ErrorCode::CONNECTION_ERROR                              => __( 'The Agent could not be reached.', 'od-wordpress-monitor' ),
			ErrorCode::AGENT_NOT_FOUND                               => __( 'The Agent endpoint was not found.', 'od-wordpress-monitor' ),
			ErrorCode::HTTPS_REQUIRED                                => __( 'The Agent URL must use HTTPS.', 'od-wordpress-monitor' ),
			ErrorCode::CREDENTIAL_NOT_FOUND,
			ErrorCode::CREDENTIAL_DECRYPTION_FAILED                  => __( 'The stored Agent credential is unavailable.', 'od-wordpress-monitor' ),
			ErrorCode::INVALID_JSON,
			ErrorCode::INVALID_RESPONSE,
			ErrorCode::UNSUPPORTED_SCHEMA                            => __( 'The Agent returned an invalid update response.', 'od-wordpress-monitor' ),
			default                                         => __( 'The update check failed.', 'od-wordpress-monitor' ),
		};

		return $this->result(
			$site,
			$started_at,
			$started,
			CheckResult::STATUS_CRITICAL,
			$error_code,
			$message,
			array( 'endpoint' => 'updates' )
		);
	}

	/**
	 * Create a normalized update result.
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
