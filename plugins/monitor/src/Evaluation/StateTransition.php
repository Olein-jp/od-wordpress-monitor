<?php
/**
 * Detects meaningful state changes and creates events.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Evaluation;

use InvalidArgumentException;
use Olein\WordPressMonitor\Event\EventType;
use Olein\WordPressMonitor\Event\MonitoringEvent;
use Olein\WordPressMonitor\Monitor\CheckResult;
use Olein\WordPressMonitor\Monitor\Status;
use Olein\WordPressMonitor\Status\SiteStatus;

final class StateTransition {
	public function detect( SiteStatus $previous, SiteStatus $current, CheckResult $result ): ?MonitoringEvent {
		if ( $previous->site_id() !== $current->site_id() || $current->site_id() !== $result->site_id() ) {
			throw new InvalidArgumentException( 'A state transition must belong to one site.' );
		}

		$previous_status = $this->status_for_type( $previous, $result->type() );
		$current_status  = $this->status_for_type( $current, $result->type() );

		if (
			$previous_status === $current_status
			|| Status::UNKNOWN === $current_status
		) {
			return null;
		}

		if ( Status::HEALTHY === $current_status ) {
			if ( Status::UNKNOWN === $previous_status ) {
				return null;
			}

			$event_type = EventType::RECOVERED;
		} elseif (
			Status::UNKNOWN === $previous_status
			|| $this->severity( $current_status ) > $this->severity( $previous_status )
		) {
			$event_type = $this->worsening_event_type( $result->type(), $current_status );

			if ( null === $event_type ) {
				return null;
			}
		} else {
			return null;
		}

		$metadata               = $result->data();
		$metadata['check_type'] = $result->type();

		return new MonitoringEvent(
			null,
			$result->site_id(),
			$event_type,
			$previous_status,
			$current_status,
			$result->error_code(),
			$result->message(),
			$result->finished_at(),
			$metadata
		);
	}

	private function worsening_event_type( string $type, string $current_status ): ?string {
		return match ( $type ) {
			'http'                       => Status::CRITICAL === $current_status ? EventType::SITE_DOWN : null,
			'agent_ping', 'agent_status' => Status::CRITICAL === $current_status ? EventType::AGENT : null,
			'updates'                    => in_array( $current_status, array( Status::WARNING, Status::CRITICAL ), true ) ? EventType::UPDATES : null,
			'ssl'                        => in_array( $current_status, array( Status::WARNING, Status::CRITICAL ), true ) ? EventType::SSL : null,
			default                      => throw new InvalidArgumentException( 'The check type cannot generate an event.' ),
		};
	}

	private function status_for_type( SiteStatus $status, string $type ): string {
		return match ( $type ) {
			'http'                       => $status->http_status(),
			'agent_ping', 'agent_status' => $status->agent_status(),
			'updates'                    => $status->updates_status(),
			'ssl'                        => $status->ssl_status(),
			default                      => throw new InvalidArgumentException( 'The check type cannot generate a transition.' ),
		};
	}

	private function severity( string $status ): int {
		return match ( $status ) {
			Status::HEALTHY  => 0,
			Status::WARNING  => 1,
			Status::CRITICAL => 2,
			default          => -1,
		};
	}
}
