<?php
/**
 * Read model for the monitoring dashboard and site list.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Admin;

use Olein\WordPressMonitor\Monitor\Status;
use Olein\WordPressMonitor\Site\Site;
use Olein\WordPressMonitor\Site\SiteRepository;
use Olein\WordPressMonitor\Status\SiteStatus;
use Olein\WordPressMonitor\Status\SiteStatusRepository;

final class StatusOverview {
	public const FILTER_ALL = 'all';

	public function __construct(
		private readonly SiteRepository $sites,
		private readonly SiteStatusRepository $statuses
	) {
	}

	/**
	 * Build a summary and severity-sorted site rows with two database queries.
	 *
	 * @return array{
	 *     summary:array{total:int,healthy:int,warning:int,critical:int,unknown:int},
	 *     rows:list<array{site:Site,status:?SiteStatus,overall:string}>,
	 *     filter:string
	 * }
	 */
	public function snapshot( string $filter = self::FILTER_ALL ): array {
		$filter           = $this->normalize_filter( $filter );
		$statuses_by_site = array();

		foreach ( $this->statuses->all() as $status ) {
			$statuses_by_site[ $status->site_id() ] = $status;
		}

		$summary = array(
			'total'    => 0,
			'healthy'  => 0,
			'warning'  => 0,
			'critical' => 0,
			'unknown'  => 0,
		);
		$rows    = array();

		foreach ( $this->sites->all() as $site ) {
			$status  = null === $site->id() ? null : ( $statuses_by_site[ $site->id() ] ?? null );
			$overall = StatusLabel::normalize( null === $status ? 'unknown' : $status->overall_status() );

			++$summary['total'];
			++$summary[ $overall ];

			if ( self::FILTER_ALL === $filter || $overall === $filter ) {
				$rows[] = array(
					'site'    => $site,
					'status'  => $status,
					'overall' => $overall,
				);
			}
		}

		usort( $rows, array( $this, 'compare_rows' ) );

		return array(
			'summary' => $summary,
			'rows'    => $rows,
			'filter'  => $filter,
		);
	}

	private function normalize_filter( string $filter ): string {
		return self::FILTER_ALL === $filter || Status::is_valid( $filter )
			? $filter
			: self::FILTER_ALL;
	}

	/**
	 * Sort Problem, Attention, Unknown, Healthy; then by site name.
	 *
	 * @param array{site:Site,status:?SiteStatus,overall:string} $left  Left row.
	 * @param array{site:Site,status:?SiteStatus,overall:string} $right Right row.
	 */
	private function compare_rows( array $left, array $right ): int {
		$priority = array(
			Status::CRITICAL => 0,
			Status::WARNING  => 1,
			Status::UNKNOWN  => 2,
			Status::HEALTHY  => 3,
		);
		$result   = $priority[ $left['overall'] ] <=> $priority[ $right['overall'] ];

		if ( 0 !== $result ) {
			return $result;
		}

		$result = strcasecmp( $left['site']->name(), $right['site']->name() );

		if ( 0 !== $result ) {
			return $result;
		}

		return ( $left['site']->id() ?? 0 ) <=> ( $right['site']->id() ?? 0 );
	}
}
