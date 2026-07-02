<?php
namespace AIOSEO\Plugin\Pro\SearchStatistics\Stats;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Models\Post;

/**
 * Handles our post statistics.
 *
 * @since 4.3.0
 */
class Posts {
	/**
	 * Returns the filters for the posts table.
	 *
	 * @since 4.3.0
	 *
	 * @param  string $filter     The current filter.
	 * @param  string $searchTerm The current search term.
	 * @return array              The list of filters.
	 */
	public function getFilters( $filter, $searchTerm ) {
		return [
			[
				'slug'   => 'all',
				'name'   => __( 'All', 'aioseo-pro' ),
				'active' => ( ! $filter || 'all' === $filter ) && ! $searchTerm
			],
			[
				'slug'   => 'topLosing',
				'name'   => __( 'Top Losing', 'aioseo-pro' ),
				'active' => 'topLosing' === $filter
			],
			[
				'slug'   => 'topWinning',
				'name'   => __( 'Top Winning', 'aioseo-pro' ),
				'active' => 'topWinning' === $filter
			]
		];
	}

	/**
	 * Returns the additional filters for the posts table.
	 *
	 * @since 4.3.0
	 *
	 * @return array The list of additional filters.
	 */
	public function getAdditionalFilters() {
		$postTypes = aioseo()->searchStatistics->helpers->getIncludedPostTypes();
		if ( empty( $postTypes ) ) {
			return [];
		}

		$postTypeOptions = [
			[
				'label' => __( 'All Content Types', 'aioseo-pro' ),
				'value' => ''
			]
		];

		$additionalFilters = [];
		foreach ( $postTypes as $postType ) {
			$postTypeObject = get_post_type_object( $postType );
			if ( ! is_object( $postTypeObject ) ) {
				continue;
			}

			$postTypeOptions[] = [
				'label' => $postTypeObject->labels->singular_name,
				'value' => $postTypeObject->name
			];
		}

		$additionalFilters[] = [
			'name'    => 'postType',
			'options' => $postTypeOptions
		];

		return $additionalFilters;
	}

	/**
	 * Adds post objects to the row data.
	 *
	 * @since 4.3.0
	 *
	 * @param  array  $data The data.
	 * @param  string $type The type of data.
	 * @return array        The data with objects.
	 */
	public function addPostData( $data, $type ) {
		if ( 'statistics' === $type ) {
			$pages      = aioseo()->searchStatistics->helpers->setRowKey( $data['pages']['paginated']['rows'], 'page' );
			$topPages   = aioseo()->searchStatistics->helpers->setRowKey( $data['pages']['topPages']['rows'], 'page' );
			$topWinning = aioseo()->searchStatistics->helpers->setRowKey( $data['pages']['topWinning']['rows'], 'page' );
			$topLosing  = aioseo()->searchStatistics->helpers->setRowKey( $data['pages']['topLosing']['rows'], 'page' );

			$data['pages']['paginated']['rows']  = $this->mergeObjects( $pages );
			$data['pages']['topPages']['rows']   = $this->mergeObjects( $topPages );
			$data['pages']['topWinning']['rows'] = $this->mergeObjects( $topWinning );
			$data['pages']['topLosing']['rows']  = $this->mergeObjects( $topLosing );
		}

		if ( 'keywords' === $type ) {
			$pagesWithObjects = [];
			foreach ( $data as $keyword => $data ) {
				$pagesWithObjects[ $keyword ] = aioseo()->searchStatistics->helpers->setRowKey( $data, 'page' );
				$pagesWithObjects[ $keyword ] = $this->mergeObjects( $pagesWithObjects[ $keyword ] );
			}

			$data = $pagesWithObjects;
		}

		if ( 'contentRankings' === $type ) {
			$pages = aioseo()->searchStatistics->helpers->setRowKey( $data['paginated']['rows'], 'page' );

			$data['paginated']['rows'] = $this->mergeObjects( $pages );
		}

		return $data;
	}

	/**
	 * Returns the objects for the given rows by merging them into the rows.
	 *
	 * @since 4.3.0
	 *
	 * @param  array $rows The rows.
	 * @return array       The modified rows.
	 */
	private function mergeObjects( $rows ) {
		$objects = $this->getObjects( array_keys( $rows ) );

		foreach ( $objects as $page => $object ) {
			if ( ! isset( $rows[ $page ] ) ) {
				$rows[ $page ] = [];
			}

			$rows[ $page ] = array_merge( (array) $rows[ $page ], (array) $object );
		}

		return $rows;
	}

	/**
	 * Adds Pro specific data to the objects.
	 *
	 * @since 4.3.0
	 *
	 * @param  array $pages List of paths.
	 * @return array        The post objects.
	 */
	private function getObjects( $pages ) {
		if ( empty( $pages ) ) {
			return [];
		}

		$objects = aioseo()->core->db->start( 'aioseo_search_statistics_objects as asso' )
			->select( 'asso.*, COALESCE(p.post_title, "") as title' )
			->leftJoin( 'posts as p', 'asso.object_id = p.ID AND asso.object_type = "post"' )
			->whereIn( 'object_path_hash', array_map( 'sha1', array_unique( $pages ) ) )
			->run()->result();

		$objects    = aioseo()->searchStatistics->helpers->setRowKey( $objects, 'object_path' );
		$newObjects = [];
		foreach ( $pages as $path ) {
			$newObjects[ $path ] = [
				'objectTitle' => $path
			];

			if ( empty( $objects[ $path ] ) ) {
				continue;
			}

			$object = $objects[ $path ];

			$newObjects[ $path ]['objectId']         = ! empty( $object->object_id ) ? (int) $object->object_id : null;
			$newObjects[ $path ]['objectTitle']      = ! empty( $object->title ) ? aioseo()->helpers->decodeHtmlEntities( $object->title ) : $path;
			$newObjects[ $path ]['objectType']       = $object->object_type;
			$newObjects[ $path ]['inspectionResult'] = aioseo()->searchStatistics->urlInspection->get( $path );

			if ( 'post' === $object->object_type ) {
				static $postTypeObjects = [];
				if ( empty( $postTypeObjects[ $object->object_subtype ] ) ) {
					$postTypeObjects[ $object->object_subtype ] = aioseo()->helpers->getPostType( get_post_type_object( $object->object_subtype ) );
				}

				$newObjects[ $path ]['seoScore']      = (int) Post::getPost( $object->object_id )->seo_score ?? null;
				$newObjects[ $path ]['linkAssistant'] = aioseo()->searchStatistics->helpers->getLinkAssistantData( (int) $object->object_id );
				$newObjects[ $path ]['context']       = [
					'postType'    => $postTypeObjects[ $object->object_subtype ],
					'permalink'   => get_permalink( $object->object_id ),
					'editLink'    => get_edit_post_link( $object->object_id, '' ),
					'lastUpdated' => get_the_modified_date( get_option( 'date_format' ), $object->object_id )
				];
			}
		}

		return $newObjects;
	}

	/**
	 * Returns a list of posts with their slugs, based on a given search term.
	 *
	 * @since   4.3.0
	 * @version 4.4.1 Changed the string param $searchTerm to an array $args.
	 *
	 * @param  array $args The args to get post data.
	 * @return array       The post data.
	 */
	public function getPostData( $args = [] ) {
		$cacheHash  = sha1( implode( ',', $args ) );
		$cachedData = aioseo()->core->cache->get( "aioseo_search_statistics_post_data_{$cacheHash}" );
		if ( $cachedData ) {
			return $cachedData;
		}

		// Start the query.
		$postData = aioseo()->core->db->start( 'aioseo_search_statistics_objects as asso' )
			->select( 'p.ID', 'p.post_title', 'p.post_modified', 'asso.object_path' )
			->join( 'posts as p', 'asso.object_id = p.ID' )
			->where( 'asso.object_type', 'post' );

		// Add the search term to the query.
		if ( ! empty( $args['searchTerm'] ) && strlen( $args['searchTerm'] ) > 2 ) {
			$searchTerm = esc_sql( aioseo()->core->db->db->esc_like( strtolower( $args['searchTerm'] ) ) );
			$postData   = $postData->whereRaw( "asso.object_path LIKE '%{$searchTerm}%' OR p.post_title LIKE '%{$searchTerm}%'" );
		}

		// Run the query.
		$postData = $postData->run()->result();
		$postData = aioseo()->searchStatistics->helpers->setRowKey( $postData, 'object_path' );

		aioseo()->core->cache->update( "aioseo_search_statistics_post_data_{$cacheHash}", $postData, 15 * MINUTE_IN_SECONDS );

		return $postData;
	}

	/**
	 * Returns the paths for all post objects.
	 *
	 * @since 4.3.6
	 *
	 * @param  string $postType The post type to get the paths for.
	 * @return array            The list of paths.
	 */
	public function getPostObjectPaths( $postType = '' ) {
		$cachedData = aioseo()->core->cache->get( "aioseo_search_statistics_post_paths_{$postType}" );
		if ( $cachedData ) {
			return $cachedData;
		}

		$displayableObjects = aioseo()->core->db->start( 'aioseo_search_statistics_objects as asso' )
			->select( 'asso.object_path' )
			->where( 'asso.object_type', 'post' );

		if ( $postType ) {
			$displayableObjects = $displayableObjects->where( 'asso.object_subtype', $postType );
		}

		$displayableObjects = $displayableObjects->run()->result();
		$displayableObjects = wp_list_pluck( $displayableObjects, 'object_path' );

		aioseo()->core->cache->update( "aioseo_search_statistics_post_paths_{$postType}", $displayableObjects, WEEK_IN_SECONDS );

		return $displayableObjects;
	}
}