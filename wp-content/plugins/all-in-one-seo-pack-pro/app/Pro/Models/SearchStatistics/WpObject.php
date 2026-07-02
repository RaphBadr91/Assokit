<?php
namespace AIOSEO\Plugin\Pro\Models\SearchStatistics;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Models as CommonModels;

/**
 * The Object DB Model.
 * It's called WPObject because Object is a reserved word in PHP.
 *
 * @since 4.3.0
 */
class WpObject extends CommonModels\Model {
	/**
	 * The name of the table in the database, without the prefix.
	 *
	 * @since 4.3.0
	 *
	 * @var string
	 */
	protected $table = 'aioseo_search_statistics_objects';

	/**
	 * Fields that should be numeric values.
	 *
	 * @since 4.3.0
	 *
	 * @var array
	 */
	protected $integerFields = [ 'id', 'object_id' ];

	/**
	 * List of fields that should be hidden when serialized.
	 *
	 * @since 4.3.0
	 *
	 * @var array
	 */
	protected $hidden = [ 'id' ];

	/**
	 * Fields that should be json encoded on save and decoded on get.
	 *
	 * @since 4.5.0
	 *
	 * @var array
	 */
	protected $jsonFields = [ 'inspection_result' ];

	/**
	 * Updates a given row.
	 *
	 * @since 4.3.0
	 *
	 * @param  array $data The new data.
	 * @return void
	 */
	public static function update( $data ) {
		if ( empty( $data['id'] ) ) {
			return;
		}

		$wpObject = aioseo()->core->db->start( 'aioseo_search_statistics_objects' )
										->where( 'id', $data['id'] )
										->run()
										->model( 'AIOSEO\\Plugin\\Pro\\Models\\SearchStatistics\\WpObject' );

		if ( ! $wpObject->exists() ) {
			return;
		}

		try {
			$wpObject->set( self::sanitizeAll( array_merge( json_decode( wp_json_encode( $wpObject ), true ), $data ) ) );
			$wpObject->save();
		} catch ( \Exception $e ) {
			// Do nothing. It only exists because the set() method above throws an exception if it fails.
		}
	}

	/**
	 * Gets the objects from the database.
	 *
	 * @since 4.8.2
	 *
	 * @param  array $args The arguments.
	 * @return array       The object rows.
	 */
	public static function getObjects( $args = [] ) { // phpcs:disable Generic.Files.LineLength.MaxExceeded
		$args = array_merge( [
			'filter'            => 'all',
			'searchTerm'        => '',
			'additionalFilters' => [],
			'paths'             => [],
			'count'             => true,
		], $args );

		$searchTerm        = esc_sql( sanitize_text_field( $args['searchTerm'] ) );
		$orderDir          = ! empty( $args['orderDir'] ) ? $args['orderDir'] : 'DESC';
		$limit             = ! empty( $args['limit'] ) ? intval( $args['limit'] ) : aioseo()->settings->tablePagination['searchStatisticsIndexStatus'];
		$offset            = ! empty( $args['offset'] ) ? intval( $args['offset'] ) : 0;
		$additionalFilters = ! empty( $args['additionalFilters'] ) ? $args['additionalFilters'] : [];
		$paths             = ! empty( $args['paths'] ) && is_array( $args['paths'] ) ? array_filter( $args['paths'] ) : [];
		$postType          = $additionalFilters['postType'] ?? '';
		$status            = $additionalFilters['status'] ?? '';
		$robotsTxtState    = $additionalFilters['robotsTxtState'] ?? '';
		$pageFetchState    = $additionalFilters['pageFetchState'] ?? '';
		$crawledAs         = $additionalFilters['crawledAs'] ?? '';

		switch ( $args['orderBy'] ?? '' ) {
			case 'title':
				$orderBy = 'wp.post_title';
				break;
			case 'lastCrawlTime':
				$orderBy = 'aio.last_crawl_time';
				break;
			default:
				$orderBy = 'aio.created';
		}

		/**
		 * Fetching objects from our table is probably the best way since it's constantly being updated {@see Objects::scanForPosts()},
		 * and it already has some of the necessary data (e.g. the "inspection_result" column).
		 */
		$query      = aioseo()->core->db->start( 'aioseo_search_statistics_objects as aio' )
						->select( 'aio.id, aio.object_id, aio.object_type, aio.object_subtype, aio.object_path, aio.inspection_result, aio.inspection_result_date, aio.verdict, aio.robots_txt_state, aio.indexing_state, aio.page_fetch_state, aio.coverage_state, aio.crawled_as, aio.last_crawl_time, wp.post_title, wp.post_type' )
						->join( 'posts as wp', 'aio.object_id = wp.ID', 'INNER' )
						->where( 'aio.object_type', 'post' )
						->whereIn( 'aio.object_subtype', aioseo()->helpers->getPublicPostTypes( true ) )
						->orderBy( "$orderBy $orderDir" )
						->limit( $limit, $offset );
		$totalQuery = aioseo()->core->db->noConflict()->start( 'aioseo_search_statistics_objects as aio' )
						->join( 'posts as wp', 'aio.object_id = wp.ID AND aio.object_type = "post"', 'INNER' )
						->where( 'aio.object_type', 'post' )
						->whereIn( 'aio.object_subtype', aioseo()->helpers->getPublicPostTypes( true ) );

		if ( $paths ) {
			$query->whereIn( 'object_path_hash', array_map( 'sha1', array_unique( $paths ) ) );
			$totalQuery->whereIn( 'object_path_hash', array_map( 'sha1', array_unique( $paths ) ) );
		}

		if ( $searchTerm ) {
			$query->whereRaw( 'wp.post_title LIKE \'%' . $searchTerm . '%\'' );
			$totalQuery->whereRaw( 'wp.post_title LIKE \'%' . $searchTerm . '%\'' );
		}

		if ( $postType ) {
			$query->where( 'aio.object_subtype', $postType );
			$totalQuery->where( 'aio.object_subtype', $postType );
		}

		// {@see \AIOSEO\Plugin\Common\SearchStatistics\IndexStatus::getUiOptions()} for all possible status.
		if ( $status ) {
			if ( 'submitted' === $status ) {
				$query->where( 'aio.verdict', 'PASS' );
				$totalQuery->where( 'aio.verdict', 'PASS' );
			}

			if (
				'crawled' === $status ||
				'discovered' === $status
			) {
				$query->whereRaw( 'LOWER(aio.coverage_state) LIKE \'%' . $status . '%\'' );
				$totalQuery->whereRaw( 'LOWER(aio.coverage_state) LIKE \'%' . $status . '%\'' );
			}

			if ( 'empty' === $status ) {
				$query->whereRaw( '( aio.coverage_state IS NULL OR aio.coverage_state = "" )' );
				$totalQuery->whereRaw( '( aio.coverage_state IS NULL OR aio.coverage_state = "" )' );
			}

			// This is supposed to cover all other possible statuses.
			if ( 'unknown|excluded|invalid|error' === $status ) {
				$query->whereRaw( "aio.coverage_state IS NOT NULL AND aio.coverage_state != '' AND aio.verdict != 'PASS' AND LOWER(aio.coverage_state) NOT LIKE '%crawled%' AND LOWER(aio.coverage_state) NOT LIKE '%discovered%'" );
				$totalQuery->whereRaw( "aio.coverage_state IS NOT NULL AND aio.coverage_state != '' AND aio.verdict != 'PASS' AND LOWER(aio.coverage_state) NOT LIKE '%crawled%' AND LOWER(aio.coverage_state) NOT LIKE '%discovered%'" );
			}
		}

		if ( $robotsTxtState ) {
			$query->where( 'aio.robots_txt_state', $robotsTxtState );
			$totalQuery->where( 'aio.robots_txt_state', $robotsTxtState );
		}

		if ( $pageFetchState ) {
			if ( false !== strpos( $pageFetchState, ',' ) ) {
				// It might be SOFT_404,BLOCKED_ROBOTS_TXT,NOT_FOUND...
				$pageFetchState = explode( ',', $pageFetchState );

				$query->whereIn( 'aio.page_fetch_state', $pageFetchState );
				$totalQuery->whereIn( 'aio.page_fetch_state', $pageFetchState );
			} else {
				$query->where( 'aio.page_fetch_state', $pageFetchState );
				$totalQuery->where( 'aio.page_fetch_state', $pageFetchState );
			}
		}

		if ( $crawledAs ) {
			$query->where( 'aio.crawled_as', $crawledAs );
			$totalQuery->where( 'aio.crawled_as', $crawledAs );
		}

		$total = $args['count'] ? $totalQuery->count() : null;
		$rows  = $query->run()->result();

		return [
			'rows'   => array_values( $rows ),
			'totals' => ! is_null( $total )
				? [
					'total' => $total,
					'pages' => $pages = ( 0 === $total ? 1 : ceil( $total / $limit ) ),
					'page'  => min( $pages, 0 === $offset ? 1 : ( $offset / $limit ) + 1 )
				]
				: []
		];
	}

	/**
	 * Gets a row by its path.
	 *
	 * @since 4.5.0
	 *
	 * @param  string        $path The path.
	 * @return WpObject|null       The object or null if not found.
	 */
	public static function getObject( $path ) {
		$wpObject = aioseo()->core->db->start( 'aioseo_search_statistics_objects' )
			->where( 'object_path_hash', sha1( $path ) )
			->run()
			->model( 'AIOSEO\\Plugin\\Pro\\Models\\SearchStatistics\\WpObject' );

		return $wpObject;
	}

	/**
	 * Gets a row by the given field => value.
	 *
	 * @since 4.5.3
	 *
	 * @param  string        $field The field to look for.
	 * @param  string        $value The value to look for.
	 * @return WpObject|null        The object or null if not found.
	 */
	public static function getObjectBy( $field, $value ) {
		$wpObject = aioseo()->core->db->start( 'aioseo_search_statistics_objects' );

		switch ( $field ) {
			case 'path':
				$wpObject = $wpObject->where( 'object_path_hash', sha1( $value ) );
				break;
			case 'post_id':
				$wpObject = $wpObject->where( 'object_id', $value );
				$wpObject = $wpObject->where( 'object_type', 'post' );
				break;
			case 'term_id':
				$wpObject = $wpObject->where( 'object_id', $value );
				$wpObject = $wpObject->where( 'object_type', 'term' );
				break;
			default:
				$wpObject = $wpObject->where( $field, $value );
		}

		$wpObject = $wpObject->run()->model( 'AIOSEO\\Plugin\\Pro\\Models\\SearchStatistics\\WpObject' );

		return $wpObject;
	}

	/**
	 * Checks if the inspection result is valid.
	 *
	 * @since   4.6.1
	 * @version 4.8.4 Changed to use the static method.
	 *
	 * @param  object $row The row to check.
	 * @return bool        Whether the inspection result is valid.
	 */
	public static function isInspectionResultValid( $row ) {
		// If there is no inspection result.
		if ( empty( $row->inspection_result ) ) {
			return false;
		}

		// If the inspection result is older than 30 days.
		if ( empty( $row->inspection_result_date ) || strtotime( $row->inspection_result_date ) < strtotime( '-1 month' ) ) {
			return false;
		}

		// If this object is not indexed and the inspection result is older than 1 day.
		if ( empty( $row->verdict ) || ( 'PASS' !== $row->verdict && strtotime( $row->inspection_result_date ) < strtotime( '-1 day' ) ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Bulk inserts a set of rows.
	 *
	 * @since 4.3.0
	 *
	 * @param  array $rows The rows to insert.
	 * @return void
	 */
	public static function bulkInsert( $rows ) {
		$currentDate = gmdate( 'Y-m-d H:i:s' );

		$addValues = [];
		foreach ( $rows as $row ) {
			$row = json_decode( wp_json_encode( $row ), true );

			if ( empty( $row['object_path'] ) ) {
				continue;
			}

			$addValues[] = vsprintf(
				"(%d, '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '$currentDate', '$currentDate')",
				array_values( self::sanitizeAll( $row ) )
			);
		}

		if ( empty( $addValues ) ) {
			return;
		}

		$tableName         = aioseo()->core->db->prefix . 'aioseo_search_statistics_objects';
		$implodedAddValues = implode( ',', $addValues );
		aioseo()->core->db->execute(
			"INSERT INTO $tableName (
				`object_id`, `object_type`, `object_subtype`, `object_path`, `object_path_hash`, `inspection_result`, `inspection_result_date`,
				`verdict`, `robots_txt_state`, `indexing_state`, `page_fetch_state`, `coverage_state`, `crawled_as`, `last_crawl_time`, `created`, `updated`
			)
			VALUES $implodedAddValues
			ON DUPLICATE KEY UPDATE
				`object_id` = VALUES(`object_id`),
				`object_type` = VALUES(`object_type`),
				`object_subtype` = VALUES(`object_subtype`),
				`inspection_result_date` = '0000-00-00 00:00:00',
				`updated` = VALUES(`updated`)"
		);
	}

	/**
	 * Parses an object row.
	 *
	 * @since {row}
	 *
	 * @param  object $row The row to format.
	 * @return array       The formatted row.
	 */
	public static function parseObject( $row ) {
		$parsed                = [];
		$parsed['id']          = intval( $row->id );
		$parsed['objectTitle'] = aioseo()->helpers->decodeHtmlEntities( $row->post_title );
		$parsed['objectId']    = intval( $row->object_id );
		$parsed['editLink']    = get_edit_post_link( $parsed['objectId'], 'url' );
		$parsed['permalink']   = get_permalink( $parsed['objectId'] );

		$inspectionResult  = json_decode( (string) $row->inspection_result, true );
		$indexStatusResult = $inspectionResult['indexStatusResult'] ?? [];
		$postTypeLabels    = aioseo()->helpers->getPostTypeLabels( $row->object_subtype );

		$parsed['postTypeLabels'] = [
			'singular' => $postTypeLabels->singular_name ?? ''
		];

		$parsed['path']                 = $row->object_path;
		$parsed['verdict']              = $row->verdict;
		$parsed['robotsTxtState']       = $row->robots_txt_state;
		$parsed['indexingState']        = $row->indexing_state;
		$parsed['pageFetchState']       = $row->page_fetch_state;
		$parsed['coverageState']        = $row->coverage_state;
		$parsed['crawledAs']            = $row->crawled_as;
		$parsed['lastCrawlTime']        = aioseo()->helpers->dateToWpFormat( $row->last_crawl_time );
		$parsed['userCanonical']        = ! empty( $indexStatusResult['userCanonical'] ) ? $indexStatusResult['userCanonical'] : null;
		$parsed['googleCanonical']      = ! empty( $indexStatusResult['googleCanonical'] ) ? $indexStatusResult['googleCanonical'] : null;
		$parsed['sitemap']              = ! empty( $indexStatusResult['sitemap'] ) ? $indexStatusResult['sitemap'] : [];
		$parsed['referringUrls']        = ! empty( $indexStatusResult['referringUrls'] )
			? array_map( [ aioseo()->helpers, 'decodeUrl' ], $indexStatusResult['referringUrls'] )
			: [];
		$parsed['richResultsResult']    = $inspectionResult['richResultsResult'] ?? null;
		$parsed['inspectionResultLink'] = $inspectionResult['inspectionResultLink'] ?? null;
		$parsed['isInspectionValid']    = self::isInspectionResultValid( $row );

		if ( $parsed['permalink'] ) {
			$parsed['richResultsTestLink'] = add_query_arg( [
				'url' => $parsed['permalink']
			], 'https://search.google.com/test/rich-results' );
		}

		return $parsed;
	}

	/**
	 * Sanitize all the Model field values.
	 *
	 * @since 4.8.2
	 *
	 * @param  array $fields All the field values.
	 * @return array         The sanitized field values.
	 */
	public static function sanitizeAll( $fields ) {
		$sanitized        = [];
		$inspectionResult = $fields['inspection_result'] ?? [];
		$isr              = $inspectionResult['indexStatusResult'] ?? [];

		$sanitized['object_id']              = ! empty( $fields['object_id'] ) ? (int) $fields['object_id'] : null;
		$sanitized['object_type']            = ! empty( $fields['object_type'] ) ? sanitize_text_field( $fields['object_type'] ) : null;
		$sanitized['object_subtype']         = ! empty( $fields['object_subtype'] ) ? sanitize_text_field( $fields['object_subtype'] ) : null;
		$sanitized['object_path']            = ! empty( $fields['object_path'] ) ? sanitize_text_field( $fields['object_path'] ) : null;
		$sanitized['object_path_hash']       = ! empty( $fields['object_path'] ) ? sha1( $fields['object_path'] ) : null;
		$sanitized['inspection_result']      = ! empty( $inspectionResult ) ? $inspectionResult : null;
		$sanitized['inspection_result_date'] = ! empty( $fields['inspection_result_date'] ) ? $fields['inspection_result_date'] : null;
		$sanitized['verdict']                = ! empty( $isr['verdict'] ) ? sanitize_text_field( $isr['verdict'] ) : null;
		$sanitized['robots_txt_state']       = ! empty( $isr['robotsTxtState'] ) ? sanitize_text_field( $isr['robotsTxtState'] ) : null;
		$sanitized['indexing_state']         = ! empty( $isr['indexingState'] ) ? sanitize_text_field( $isr['indexingState'] ) : null;
		$sanitized['page_fetch_state']       = ! empty( $isr['pageFetchState'] ) ? sanitize_text_field( $isr['pageFetchState'] ) : null;
		$sanitized['coverage_state']         = ! empty( $isr['coverageState'] ) ? sanitize_text_field( $isr['coverageState'] ) : null;
		$sanitized['crawled_as']             = ! empty( $isr['crawledAs'] ) ? sanitize_text_field( $isr['crawledAs'] ) : null;
		$sanitized['last_crawl_time']        = ! empty( $isr['lastCrawlTime'] ) ? date( 'Y-m-d H:i:s', strtotime( $isr['lastCrawlTime'] ) ) : null;

		return $sanitized;
	}
}