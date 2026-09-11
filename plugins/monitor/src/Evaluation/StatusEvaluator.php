<?php
/**
 * Applies one check result to a site's current status.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Evaluation;

use InvalidArgumentException;
use Olein\WordPressMonitor\Monitor\CheckMetadata;
use Olein\WordPressMonitor\Monitor\CheckResult;
use Olein\WordPressMonitor\Monitor\Status;
use Olein\WordPressMonitor\Status\SiteStatus;

final class StatusEvaluator {
	public function __construct( private readonly CheckMetadata $check_metadata = new CheckMetadata() ) {
	}

	/**
	 * Apply one completed check while preserving the other latest states.
	 */
	public function apply( ?SiteStatus $previous, CheckResult $result ): SiteStatus {
		$previous = $previous ?? new SiteStatus( $result->site_id() );

		if ( $previous->site_id() !== $result->site_id() ) {
			throw new InvalidArgumentException( 'The check result and site status must belong to the same site.' );
		}

		$http_status            = $previous->http_status();
		$http_checked_at        = $previous->http_checked_at();
		$agent_status           = $previous->agent_status();
		$agent_checked_at       = $previous->agent_checked_at();
		$updates_status         = $previous->updates_status();
		$updates_checked_at     = $previous->updates_checked_at();
		$site_health_status     = $previous->site_health_status();
		$site_health_checked_at = $previous->site_health_checked_at();
		$ssl_status             = $previous->ssl_status();
		$ssl_checked_at         = $previous->ssl_checked_at();

		switch ( $result->type() ) {
			case 'http':
				$http_status     = $result->status();
				$http_checked_at = $result->finished_at();
				break;
			case 'agent_ping':
			case 'agent_status':
				$agent_status     = $result->status();
				$agent_checked_at = $result->finished_at();
				break;
			case 'updates':
				$updates_status     = $result->status();
				$updates_checked_at = $result->finished_at();
				break;
			case 'site_health':
				$site_health_status     = $result->status();
				$site_health_checked_at = $result->finished_at();
				break;
			case 'ssl':
				$ssl_status     = $result->status();
				$ssl_checked_at = $result->finished_at();
				break;
			default:
				throw new InvalidArgumentException( 'The check type cannot be applied to site status.' );
		}

		$metadata        = $previous->metadata();
		$result_metadata = $this->check_metadata->for_status( $result );

		if (
			'updates' === $result->type()
			&& ! isset( $result_metadata['software_inventory'] )
			&& isset( $metadata['updates']['software_inventory'] )
		) {
			$result_metadata['software_inventory'] = $metadata['updates']['software_inventory'];
		}

		$metadata[ $result->type() ] = $result_metadata;

		return new SiteStatus(
			$result->site_id(),
			$this->overall_status( $http_status, $agent_status, $updates_status, $site_health_status, $ssl_status ),
			$http_status,
			$http_checked_at,
			$agent_status,
			$agent_checked_at,
			$updates_status,
			$updates_checked_at,
			$site_health_status,
			$site_health_checked_at,
			$ssl_status,
			$ssl_checked_at,
			$result->finished_at(),
			$result->error_code(),
			$result->message(),
			$metadata
		);
	}

	private function overall_status( string $http, string $agent, string $updates, string $site_health, string $ssl ): string {
		if ( Status::CRITICAL === $http || Status::CRITICAL === $site_health || Status::CRITICAL === $ssl ) {
			return Status::CRITICAL;
		}

		if (
			Status::WARNING === $http
			|| in_array( $agent, array( Status::WARNING, Status::CRITICAL ), true )
			|| in_array( $updates, array( Status::WARNING, Status::CRITICAL ), true )
			|| Status::WARNING === $site_health
			|| Status::WARNING === $ssl
		) {
			return Status::WARNING;
		}

		if ( in_array( Status::UNKNOWN, array( $http, $agent, $updates, $site_health, $ssl ), true ) ) {
			return Status::UNKNOWN;
		}

		return Status::HEALTHY;
	}
}
