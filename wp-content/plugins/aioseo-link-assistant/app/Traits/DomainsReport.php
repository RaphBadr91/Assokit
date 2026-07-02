<?php
namespace AIOSEO\Plugin\Addon\LinkAssistant\Traits;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Addon\LinkAssistant\Models;

/**
 * Handles all data Vue needs for the Domains Report.
 *
 * @since 1.0.0
 */
trait DomainsReport {
	/**
	 * Returns the data for the Domains Report.
	 *
	 * @since 1.0.0
	 *
	 * @param  int    $limit      The limit.
	 * @param  int    $offset     The offset.
	 * @param  string $searchTerm An optional search term.
	 * @return array              The data.
	 */
	public function getDomainsReportData( $limit = 20, $offset = 0, $searchTerm = '' ) {
		$whereClause = $this->getDomainsReportWhereClause( $searchTerm );
		$domains     = Models\Link::getDomains( $limit, $offset, $whereClause );
		$total       = Models\Link::getTotalDomains( $whereClause );
		$page        = 0 === $offset ? 1 : ( $offset / $limit ) + 1;

		return [
			'rows'   => $domains,
			'totals' => [
				'page'  => $page,
				'pages' => ceil( $total / $limit ),
				'total' => $total
			]
		];
	}

	/**
	 * Get a where clause for the Domains Report search term.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $searchTerm The search term.
	 * @return string             The search where clause.
	 */
	private function getDomainsReportWhereClause( $searchTerm ) {
		if ( 'null' === $searchTerm || ! $searchTerm ) {
			return '';
		}

		$searchTerm = esc_sql( $searchTerm );
		if ( ! $searchTerm ) {
			return '';
		}

		$where = '';
		if ( intval( $searchTerm ) ) {
			$where .= '
				id = ' . (int) $searchTerm . ' OR
				post_id = ' . (int) $searchTerm . ' OR
			';
		}
		$where .= 'hostname LIKE \'%' . $searchTerm . '%\'';

		return "( $where )";
	}
}