<?php
namespace AIOSEO\Plugin\Addon\LinkAssistant\Traits;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Addon\LinkAssistant\Models;
use AIOSEO\Plugin\Common\Utils\Database;

/**
 * Handles all data Vue needs for the Links Report.
 *
 * @since 1.0.0
 */
trait LinksReport {
	/**
	 * Returns the data for the Links Report.
	 *
	 * @since 1.0.0
	 *
	 * @param  int    $limit             The limit.
	 * @param  int    $offset            The offset.
	 * @param  string $searchTerm        An optional search term.
	 * @param  string $filter            An optional filter for the results.
	 * @param  array  $additionalFilters Additional filters to use when querying the data.
	 * @return array                     The Links Report data.
	 */
	public function getLinksReportData( $limit = 20, $offset = 0, $searchTerm = '', $filter = 'all', $additionalFilters = [] ) {
		$whereClause = $this->getLinksReportWhereClause( $searchTerm );

		$posts = $filter && 'orphaned-posts' === $filter
			? $this->getReportOrphanedPosts( $limit, $offset, $whereClause, $filter, $additionalFilters )
			: Models\Link::getPosts( $limit, $offset, $whereClause, $filter, $additionalFilters );

		$totalPosts = $filter && 'orphaned-posts' === $filter
			? $this->getReportTotalOrphanedPosts( $whereClause, $filter, $additionalFilters )
			: Models\Link::getTotalPosts( $whereClause, $filter, $additionalFilters );

		$prioritizedPostIds = aioseoLinkAssistant()->cache->get( 'prioritized_posts' );
		if ( empty( $prioritizedPostIds ) ) {
			$prioritizedPostIds = [];
		}

		$page = 0 === $offset ? 1 : ( $offset / $limit ) + 1;

		return [
			'rows'              => $posts,
			'totals'            => [
				'page'  => $page,
				'pages' => ceil( $totalPosts / $limit ),
				'total' => $totalPosts
			],
			'filters'           => [
				[
					'slug'   => 'all',
					'name'   => __( 'All', 'aioseo-link-assistant' ),
					'count'  => Models\Link::getTotalPosts( '', 'all' ),
					'active' => ( ! $filter || 'all' === $filter ) && ! $searchTerm ? true : false
				],
				[
					'slug'   => 'linking-opportunities',
					'name'   => __( 'Linking Opportunities', 'aioseo-link-assistant' ),
					'count'  => Models\Suggestion::getTotalPosts(),
					'active' => 'linking-opportunities' === $filter ? true : false
				],
				[
					'slug'   => 'orphaned-posts',
					'name'   => __( 'Orphaned Posts', 'aioseo-link-assistant' ),
					'count'  => $this->getTotalOrphanedPosts(),
					'active' => 'orphaned-posts' === $filter ? true : false
				]
			],
			'additionalFilters' => $this->getLinksReportAdditionalFilters(),
			'prioritizedPosts'  => $prioritizedPostIds
		];
	}

	/**
	 * Get a where clause for the Links Report search term.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $searchTerm The search term.
	 * @return string             The search where clause.
	 */
	private function getLinksReportWhereClause( $searchTerm ) {
		if ( ! $searchTerm || 'null' === $searchTerm ) {
			return '';
		}

		$searchTerm = esc_sql( $searchTerm );
		if ( ! $searchTerm ) {
			return '';
		}

		$where = '';
		if ( intval( $searchTerm ) ) {
			$where .= '
				p.ID = ' . (int) $searchTerm . ' OR
			';
		}
		$where .= "
			p.post_title LIKE '%" . $searchTerm . "%' OR
			p.post_name LIKE '%" . $searchTerm . "%'
		";

		return "( $where )";
	}

	/**
	 * Returns the additional filters for the Links Report.
	 *
	 * @since 1.0.0
	 *
	 * @return array The additional filters.
	 */
	private function getLinksReportAdditionalFilters() {
		$additionalFilters = [];
		$postTypes         = $this->getIncludedPostTypes();
		if ( empty( $postTypes ) ) {
			return $additionalFilters;
		}

		$postTypeOptions = [
			[
				'label' => __( 'All Included Post Types', 'aioseo-link-assistant' ),
				'value' => 'all'
			]
		];

		foreach ( $postTypes as $postType ) {
			$postTypeObject = get_post_type_object( $postType );
			if ( ! is_object( $postTypeObject ) ) {
				continue;
			}

			$postTypeOptions[] = [
				'label' => $postTypeObject->labels->singular_name,
				'value' => $postTypeObject->name
			];

			$taxonomy = $this->getFirstTaxonomy( $postType );
			if ( empty( $taxonomy ) ) {
				continue;
			}

			$terms = get_terms( [
				'taxonomy'   => $taxonomy->name,
				'hide_empty' => true
			] );

			// If there are more than 20 terms, we'll also hide them because the list will take up the entire screen height or more.
			if ( empty( $terms ) || 20 < count( $terms ) ) {
				continue;
			}

			$termOptions = [
				[
					'label' => sprintf(
						// Translators: 1 - Plural label of a taxonomy (e.g. "Categories").
						__( 'All %1$s', 'aioseo-link-assistant' ),
						$taxonomy->label
					),
					'value' => 'all'
				]
			];

			foreach ( $terms as $term ) {
				$termOptions[] = [
					'label' => $term->name,
					'value' => $term->term_id
				];
			}

			$additionalFilters[] = [
				'name'      => 'term',
				'options'   => $termOptions,
				'dependsOn' => [
					'name'  => 'post-type',
					'value' => $postType
				]
			];
		}

		array_unshift( $additionalFilters, [
			'name'    => 'post-type',
			'options' => $postTypeOptions
		] );

		return $additionalFilters;
	}

	/**
	 * Returns the first assigned taxonomy object for the given post type.
	 *
	 * @since 1.0.0
	 *
	 * @param  string             $postType The post type name.
	 * @return \WP_Taxonomy|false           The taxonomy object.
	 */
	public function getFirstTaxonomy( $postType ) {
		$taxonomies = get_object_taxonomies( $postType, 'objects' );
		if ( empty( $taxonomies ) ) {
			return false;
		}

		$taxonomy = apply_filters( 'aioseo_link_assistant_taxonomies_filter', reset( $taxonomies ) );
		if ( empty( $taxonomy ) ) {
			return false;
		}

		return $taxonomy;
	}

	/**
	 * Returns the orphaned posts base query for the Links Report.
	 *
	 * @since 4.8.0
	 *
	 * @param  string   $whereClause       An optional WHERE clause for search queries.
	 * @param  string   $filter            An optional filter for the results.
	 * @param  array    $additionalFilters Additional filters to use when querying the data.
	 *
	 * @return Database The Database instance.
	 */
	private function getReportOrphanedPostsBase( $whereClause = '', $filter = 'all', $additionalFilters = [] ) {
		aioseoLinkAssistant()->helpers->maybeCreateTempTables();

		$query = aioseo()->core->db->start( 'aioseo_posts as ap' )
			->select( 'p.ID, p.post_title, p.post_date, p.post_status' )
			->join( 'aioseotemp_la_included_posts as p', 'ap.post_id = p.ID' )
			->whereRaw( 'ap.link_scan_date IS NOT NULL' )
			->orderBy( 'p.post_date DESC' );

		$query = aioseoLinkAssistant()->helpers->applyPostsFilters( $query, $whereClause, $filter, $additionalFilters );

		$excludedPostIds = $this->getExcludedPostIds();
		if ( ! empty( $excludedPostIds ) ) {
			$implodedPostIds = aioseo()->helpers->implodeWhereIn( $excludedPostIds );
			$query->whereRaw( "p.ID NOT IN ( $implodedPostIds )" );
		}

		$prefix        = aioseo()->core->db->prefix;
		$tableName     = $prefix . 'aioseo_links';
		$orphanedPosts = $query->whereRaw( "ap.post_ID NOT IN (
				SELECT al.linked_post_id
				FROM $tableName as al
				WHERE al.linked_post_ID IS NOT NULL AND al.linked_post_id != 0
				GROUP BY al.linked_post_id
			)" );

		return $orphanedPosts;
	}

	/**
	 * Returns the orphaned posts for the Links Report.
	 *
	 * @since 1.0.0
	 * @version 1.1.8 Added `filter` and `additionalFilters` parameters.
	 *
	 * @param  int    $limit             The limit.
	 * @param  int    $offset            The offset.
	 * @param  string $whereClause       An optional WHERE clause for search queries.
	 * @param  string $filter            An optional filter for the results.
	 * @param  array  $additionalFilters Additional filters to use when querying the data.
	 * @return array                     The orphaned posts with their links & suggestions.
	 */
	private function getReportOrphanedPosts( $limit = 20, $offset = 0, $whereClause = '', $filter = 'all', $additionalFilters = [] ) {
		aioseoLinkAssistant()->helpers->maybeCreateTempTables();

		$orphanedPosts = $this->getReportOrphanedPostsBase( $whereClause, $filter, $additionalFilters )
			->limit( $limit, $offset )
			->run()
			->result();

		if ( empty( $orphanedPosts ) ) {
			return [];
		}

		foreach ( $orphanedPosts as $post ) {
			if ( ! $post->post_title ) {
				$post->post_title = __( '(no title)' ); // phpcs:ignore AIOSEO.Wp.I18n.MissingArgDomain
			}

			$postStatusObject = get_post_status_object( $post->post_status );
			$postTypeObject   = get_post_type_object( get_post_type( $post->ID ) );

			$post->links               = $this->getPostLinks( $post->ID, 5, 0 );
			$post->context             = new \stdClass();
			$post->context->postStatus = $post->post_status;
			$post->context->postTitle  = aioseo()->helpers->getPostTitle( $post->ID );
			$post->context->permalink  = get_permalink( $post->ID );
			$post->context->editLink   = get_edit_post_link( $post->ID, '' );
			$post->context->postStatus = $postStatusObject;
			$post->context->postType   = aioseo()->helpers->getPostType( $postTypeObject );
		}

		return $orphanedPosts;
	}

	/**
	 * Returns the total amount of orphaned posts.
	 *
	 * @since 4.8.0
	 *
	 * @param  string $whereClause       An optional WHERE clause for search queries.
	 * @param  string $filter            An optional filter for the results.
	 * @param  array  $additionalFilters Additional filters to use when querying the data.
	 * @return int                       The total amount of orphaned posts.
	 */
	private function getReportTotalOrphanedPosts( $whereClause = '', $filter = 'all', $additionalFilters = [] ) {
		aioseoLinkAssistant()->helpers->maybeCreateTempTables();

		$totalOrphanedPosts = $this->getReportOrphanedPostsBase( $whereClause, $filter, $additionalFilters )
			->count( 'DISTINCT ap.post_ID' );

		return (int) $totalOrphanedPosts;
	}
}