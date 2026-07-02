<?php
namespace AIOSEO\Plugin\Addon\LinkAssistant\Traits;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Addon\LinkAssistant\Models;

/**
 * Handles all data Vue needs for the Overview page.
 *
 * @since 1.0.0
 */
trait Overview {
	/**
	 * Returns all data for the Overview page.
	 *
	 * @since 1.0.0
	 *
	 * @return array The data.
	 */
	public function getOverviewData() {
		$overviewData = aioseoLinkAssistant()->cache->get( 'overview_data' );
		if ( ! empty( $overviewData ) ) {
			return $overviewData;
		}

		$overviewData = [
			'totals'               => $this->getLinkCountTotals(),
			'mostLinkedDomains'    => $this->getMostLinkedDomains(),
			'linkingOpportunities' => [
				'inbound'  => $this->getInboundLinkingOpportunities(),
				'outbound' => $this->getOutboundLinkingOpportunities()
			]
		];

		// Convert to array so that we don't serialize any objects.
		$overviewData = json_decode( wp_json_encode( $overviewData ), true );

		aioseoLinkAssistant()->cache->update( 'overview_data', $overviewData, 15 * MINUTE_IN_SECONDS );

		return $overviewData;
	}

	/**
	 * Returns the totals for the Link Count bar at the top of the Overview page.
	 *
	 * @since 1.0.0
	 *
	 * @return array The totals.
	 */
	private function getLinkCountTotals() {
		aioseoLinkAssistant()->helpers->maybeCreateTempTables();

		$tempTableName        = aioseo()->core->db->prefix . 'aioseotemp_la_included_posts';
		$aioseoLinksTableName = aioseo()->core->db->prefix . 'aioseo_links';

		$whereClause     = '';
		$excludedPostIds = aioseoLinkAssistant()->helpers->getExcludedPostIds();
		if ( ! empty( $excludedPostIds ) ) {
			$implodedPostIds = aioseo()->helpers->implodeWhereIn( $excludedPostIds );
			$whereClause     = "WHERE p.ID NOT IN ( {$implodedPostIds} )";
		}

		$totals = aioseo()->core->db->execute(
			"SELECT count(*) as totalLinks,
				count(IF(external = 1, 1, NULL)) as externalLinks,
				count(IF(internal = 1, 1, NULL)) as internalLinks,
				count(IF(affiliate = 1, 1, NULL)) as affiliateLinks
			FROM {$aioseoLinksTableName} as al
			JOIN {$tempTableName} as p ON al.post_id = p.ID
			{$whereClause}
			",
			true
		)->result();

		if ( empty( $totals[0] ) ) {
			return [
				'crawledPosts'   => (int) $this->getTotalCrawledPosts(),
				'orphanedPosts'  => (int) $this->getTotalOrphanedPosts(),
				'externalLinks'  => 0,
				'internalLinks'  => 0,
				'affiliateLinks' => 0,
				'totalLinks'     => 0
			];
		}

		return [
			'crawledPosts'   => (int) $this->getTotalCrawledPosts(),
			'orphanedPosts'  => (int) $this->getTotalOrphanedPosts(),
			'totalLinks'     => (int) $totals[0]->totalLinks,
			'externalLinks'  => (int) $totals[0]->externalLinks,
			'internalLinks'  => (int) $totals[0]->internalLinks,
			'affiliateLinks' => (int) $totals[0]->affiliateLinks
		];
	}

	/**
	 * Returns the top 10 most linked to external domains.
	 *
	 * @since 1.0.0
	 *
	 * @return array The most linked domains.
	 */
	private function getMostLinkedDomains() {
		aioseoLinkAssistant()->helpers->maybeCreateTempTables();

		return aioseo()->core->db->start( 'aioseo_links as al' )
			->select( 'al.hostname as name, count(*) as count' )
			->join( 'aioseotemp_la_included_posts as p', 'al.post_id = p.ID' )
			->where( 'al.external', 1 )
			->groupBy( 'name' )
			->orderBy( 'count DESC' )
			->limit( 10 )
			->run()
			->result();
	}

	/**
	 * Returns the 5 posts with the most inbound link suggestions.
	 *
	 * @since 1.1.0
	 *
	 * @return array The posts.
	 */
	private function getInboundLinkingOpportunities() {
		aioseoLinkAssistant()->helpers->maybeCreateTempTables();

		$posts = aioseo()->core->db->start( 'aioseo_links_suggestions as als' )
			->select( 'linked_post_id, count(DISTINCT post_id) as inboundSuggestions' )
			->join( 'aioseotemp_la_included_posts as p', 'als.linked_post_id = p.ID' )
			->where( 'p.post_status', 'publish' )
			->groupBy( 'linked_post_id' )
			->orderBy( 'inboundSuggestions DESC' )
			->limit( 5 )
			->run()
			->result();

		foreach ( $posts as $post ) {
			$post->postTitle = aioseo()->helpers->getPostTitle( $post->linked_post_id );
			$post->permalink = get_permalink( $post->linked_post_id );
		}

		return $posts;
	}

	/**
	 * Returns the 5 posts with the most outbound link suggestions.
	 *
	 * @since 1.1.0
	 *
	 * @return array The posts.
	 */
	private function getOutboundLinkingOpportunities() {
		aioseoLinkAssistant()->helpers->maybeCreateTempTables();

		$posts = aioseo()->core->db->start( 'aioseo_links_suggestions as als' )
			->select( 'post_id, count(DISTINCT phrase) as outboundSuggestions' )
			->join( 'aioseotemp_la_included_posts as p', 'als.post_id = p.ID' )
			->join( 'posts as p2', 'als.linked_post_id = p2.ID' )
			->where( 'p2.post_status', 'publish' )
			->groupBy( 'post_id' )
			->orderBy( 'outboundSuggestions DESC' )
			->limit( 5 )
			->run()
			->result();

		foreach ( $posts as $post ) {
			$post->postTitle = aioseo()->helpers->getPostTitle( $post->post_id );
			$post->permalink = get_permalink( $post->post_id );
		}

		return $posts;
	}

	/**
	 * Returns the total amount of orphaned posts.
	 *
	 * @since 1.0.0
	 * @version 1.1.8 Added `whereClause`, `filter` and `additionalFilters` parameters.
	 *
	 * @param  string $whereClause       An optional WHERE clause for search queries.
	 * @param  string $filter            An optional filter for the results.
	 * @param  array  $additionalFilters Additional filters to use when querying the data.
	 * @return int                       The total amount of orphaned posts.
	 */
	private function getTotalOrphanedPosts( $whereClause = '', $filter = 'all', $additionalFilters = [] ) {
		$totalOrphanedPosts = aioseoLinkAssistant()->cache->get( 'aioseo_link_assistant_total_orphaned_posts' );
		if ( null !== $totalOrphanedPosts ) {
			return (int) $totalOrphanedPosts;
		}

		$totalCrawledPosts  = $this->getTotalCrawledPosts( $whereClause, $filter, $additionalFilters );
		$totalLinkedPosts   = Models\Link::getSiteTotalLinkedPosts();
		$totalOrphanedPosts = $totalCrawledPosts - $totalLinkedPosts;

		aioseoLinkAssistant()->cache->update( 'aioseo_link_assistant_total_orphaned_posts', $totalOrphanedPosts, 10 * MINUTE_IN_SECONDS );

		return (int) $totalOrphanedPosts;
	}

	/**
	 * Returns the total amount of crawled posts.
	 *
	 * @since 1.0.0
	 * @version 1.1.8 Added `whereClause`, `filter` and `additionalFilters` parameters.
	 *
	 * @param  string $whereClause       An optional WHERE clause for search queries.
	 * @param  string $filter            An optional filter for the results.
	 * @param  array  $additionalFilters Additional filters to use when querying the data.
	 * @return int The total amount of crawled posts.
	 */
	private function getTotalCrawledPosts( $whereClause = '', $filter = 'all', $additionalFilters = [] ) {
		static $totalCrawledPosts = null;
		if ( null !== $totalCrawledPosts ) {
			return $totalCrawledPosts;
		}

		aioseoLinkAssistant()->helpers->maybeCreateTempTables();

		$query = aioseo()->core->db->start( 'aioseo_posts as ap' )
			->join( 'aioseotemp_la_included_posts as p', 'ap.post_id = p.ID' )
			->whereRaw( 'ap.link_scan_date IS NOT NULL' );

		$query = aioseoLinkAssistant()->helpers->applyPostsFilters( $query, $whereClause, $filter, $additionalFilters );

		$excludedPostIds = $this->getExcludedPostIds();
		if ( ! empty( $excludedPostIds ) ) {
			$query->whereNotIn( 'p.ID', $excludedPostIds );
		}

		return $query->count();
	}
}