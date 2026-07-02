<?php
namespace AIOSEO\Plugin\Pro\Models;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Models as CommonModels;
use AIOSEO\Plugin\Common\Utils\Database;

/**
 * The Link DB Model.
 *
 * @since 4.8.6
 */
class Issue extends CommonModels\Model {
	/**
	 * The name of the table in the database, without the prefix.
	 *
	 * @since 4.8.6
	 *
	 * @var string
	 */
	protected $table = 'aioseo_seo_analyzer_objects';

	/**
	 * Fields that should be numeric values.
	 *
	 * @since 4.8.6
	 *
	 * @var array
	 */
	protected $numericFields = [ 'id', 'object_id' ];

	/**
	 * Fields that are nullable.
	 *
	 * @since 4.8.6
	 *
	 * @var array
	 */
	protected $nullFields = [ 'metadata' ];

	/**
	 * Fields that should be json encoded on save and decoded on get.
	 *
	 * @since 4.8.6
	 *
	 * @var array
	 */
	protected $jsonFields = [ 'metadata' ];

	/**
	 * Fields that should be boolean values.
	 *
	 * @since 4.8.6
	 *
	 * @var array
	 */
	protected $booleanFields = [ 'is_ignored' ];

	/**
	 * An array of fields attached to this resource.
	 *
	 * @since 4.8.6
	 *
	 * @var array
	 */
	protected $columns = [
		'id',
		'object_id',
		'object_type',
		'object_subtype',
		'code',
		'metadata',
		'is_ignored'
	];

	/**
	 * Get issue by id.
	 *
	 * @since 4.8.6
	 *
	 * @param  int   $id The Issue code.
	 * @return Issue     The Issue object.
	 */
	public static function getById( $id ) {
		$result = aioseo()->core->db->start( 'aioseo_seo_analyzer_objects' )
			->where( 'id', $id )
			->run()
			->model( 'AIOSEO\\Plugin\\Pro\\Models\\Issue' );

		return $result;
	}

	/**
	 * Returns the Issues codes.
	 *
	 * @since 4.8.6
	 *
	 * @param  array $codes The Issue codes.
	 * @return array        Array with Issues codes.
	 */
	public static function getIssuesByCodes( $codes = [] ) {
		$postsResults = [];
		$termsResults = [];

		if ( self::hasPostType() && self::hasPostStatus() ) {
			$postsResults = self::getPostsIssuesByCodes( $codes )
				->run()
				->result();
		}

		if ( self::hasTaxonomies() ) {
			$termsResults = self::getTermsIssuesByCodes( $codes )
				->run()
				->result();
		}

		if ( empty( $postsResults ) && empty( $termsResults ) ) {
			return [];
		}

		$results = [];
		foreach ( $postsResults as $post ) {
			$post->count            = isset( $results[ $post->code ] ) ? $results[ $post->code ]->count + (int) $post->count : (int) $post->count;
			$results[ $post->code ] = $post;
		}

		foreach ( $termsResults as $term ) {
			$term->count            = isset( $results[ $term->code ] ) ? $results[ $term->code ]->count + (int) $term->count : (int) $term->count;
			$results[ $term->code ] = $term;
		}

		return $results;
	}

	/**
	 * Sort results by status in the order: error, warning, passed.
	 *
	 * @since 4.8.6
	 *
	 * @param  array $results The results to sort.
	 * @return array          The sorted results.
	 */
	private static function sortResultsByStatus( $results ) {
		if ( empty( $results ) ) {
			return $results;
		}

		// Get status codes for efficient lookup
		$errorCodes   = aioseo()->seoAnalysis->getCodesByStatus( 'error' );
		$warningCodes = aioseo()->seoAnalysis->getCodesByStatus( 'warning' );
		$passedCodes  = aioseo()->seoAnalysis->getCodesByStatus( 'passed' );

		// Create status priority mapping
		$statusPriority = [];
		foreach ( $errorCodes as $code ) {
			$statusPriority[ $code ] = 1; // error = highest priority
		}
		foreach ( $warningCodes as $code ) {
			$statusPriority[ $code ] = 2; // warning = medium priority
		}
		foreach ( $passedCodes as $code ) {
			$statusPriority[ $code ] = 3; // passed = lowest priority
		}

		usort( $results, function( $a, $b ) use ( $statusPriority ) {
			$priorityA = isset( $statusPriority[ $a->code ] ) ? $statusPriority[ $a->code ] : 4; // unknown status = lowest
			$priorityB = isset( $statusPriority[ $b->code ] ) ? $statusPriority[ $b->code ] : 4; // unknown status = lowest

			if ( $priorityA !== $priorityB ) {
				return $priorityA - $priorityB; // Sort by priority (ascending)
			}

			// If same priority, sort by count descending (higher count first)
			return $b->count - $a->count;
		} );

		return $results;
	}

	/**
	 * Returns the query that gets posts issues by codes.
	 *
	 * @since 4.8.6
	 *
	 * @param  array    $codes The Issue codes.
	 * @return Database        Returns the Database class which can then be method chained for building the query.
	 */
	private static function getPostsIssuesByCodes( $codes = [] ) {
		$query = aioseo()->core->db->noConflict()
			->start( 'aioseo_seo_analyzer_objects as sao' )
			->select( 'COUNT(sao.id) as count, sao.code' )
			->join( 'posts as p', 'sao.object_id = p.ID' )
			->where( 'sao.is_ignored', 0 )
			->where( 'sao.object_type', 'post' );

		$query = self::filterPostTypes( $query );
		$query = self::filterPostStatuses( $query );
		$query = self::filterExcludedPosts( $query );

		if ( ! empty( $codes ) ) {
			$query->whereIn( 'code', $codes );
		}

		$query->groupBy( 'sao.code' );

		return $query;
	}

	/**
	 * Returns the query that gets terms issues by codes.
	 *
	 * @since 4.8.6
	 *
	 * @param  array    $codes The Issue codes.
	 * @return Database        Returns the Database class which can then be method chained for building the query.
	 */
	private static function getTermsIssuesByCodes( $codes = [] ) {
		$query = aioseo()->core->db->noConflict()
			->start( 'aioseo_seo_analyzer_objects as sao' )
			->select( 'COUNT(sao.id) as count, sao.code' )
			->join( 'terms as t', 'sao.object_id = t.term_id' )
			->where( 'sao.is_ignored', 0 )
			->where( 'sao.object_type', 'term' );

		$query = self::filterTaxonomies( $query );
		$query = self::filterExcludedTerms( $query );

		if ( ! empty( $codes ) ) {
			$query->whereIn( 'code', $codes );
		}

		$query->groupBy( 'sao.code' );

		return $query;
	}

	/**
	 * Returns the base query to get all posts for the given issue code.
	 *
	 * @since 4.8.6
	 *
	 * @param  array    $filters    Filters.
	 * @param  string   $searchTerm An optional search term.
	 * @return Database             Returns the Database class which can then be method chained for building the query.
	 */
	public static function getPostsQuery( $filters = [], $searchTerm = '' ) {
		$query = aioseo()->core->db->noConflict()
			->start( 'posts as p' )
			->select( [
				'sao.id as issueId',
				'sao.code',
				'sao.object_id',
				'sao.object_type',
				'sao.object_subtype',
				'sao.metadata',
				'sao.is_ignored',
				'p.post_title as title',
				'p.post_date as date',
				'p.post_status as status'
			] )
			->leftJoin( 'aioseo_seo_analyzer_objects as sao', 'sao.object_id = p.ID' )
			->where( 'sao.is_ignored', 0 )
			->where( 'sao.object_type', 'post' );

		if ( ! empty( $filters ) ) {
			foreach ( $filters as $filter ) {
				$query->where( $filter['column'], $filter['value'] );
			}
		}

		$query = self::filterPostTypes( $query );
		$query = self::filterPostStatuses( $query );
		$query = self::filterExcludedPosts( $query );

		if ( ! empty( $searchTerm ) ) {
			$escapedSearchTerm = esc_sql( aioseo()->core->db->db->esc_like( $searchTerm ) );
			$query->whereRaw( 'p.post_title LIKE \'%' . $escapedSearchTerm . '%\'' );
		}

		return $query;
	}

	/**
	 * Returns the base query to get all posts.
	 *
	 * @since 4.8.6
	 *
	 * @param  string   $searchTerm        An optional search term.
	 * @param  array    $additionalFilters An optional array of additional filters.
	 * @return Database                    Returns the Database class which can then be method chained for building the query.
	 */
	public static function getAllUrlsPostsQuery( $searchTerm = '', $additionalFilters = [] ) {
		$query = aioseo()->core->db->noConflict()
			->start( 'posts as p' )
			->select( 'sao.object_id, sao.object_type, sao.object_subtype, p.post_title as title, p.post_date as date, ap.keyphrases, p.post_status as status' )
			->leftJoin( 'aioseo_seo_analyzer_objects as sao', 'sao.object_id = p.ID' )
			->leftJoin( 'aioseo_posts as ap', 'ap.post_id = p.ID' )
			->where( 'sao.is_ignored', 0 )
			->where( 'sao.object_type', 'post' );

		$contentType = self::getContentTypeFilter( $additionalFilters );

		$query = self::filterPostTypes( $query, $contentType );
		$query = self::filterPostStatuses( $query );
		$query = self::filterExcludedPosts( $query );

		if ( ! empty( $searchTerm ) ) {
			$escapedSearchTerm = esc_sql( aioseo()->core->db->db->esc_like( $searchTerm ) );
			$query->whereRaw( 'p.post_title LIKE \'%' . $escapedSearchTerm . '%\'' );
		}

		return $query;
	}

	/**
	 * Apply the excluded posts filter.
	 *
	 * @since 4.8.6
	 *
	 * @param  Database $query The query.
	 * @return Database        Returns the Database class which can then be method chained for building the query.
	 */
	private static function filterExcludedPosts( $query ) {
		$settings = aioseo()->dynamicOptions->seoAnalysis->all();

		$excludePosts = [];
		if ( ! empty( $settings['excludePosts'] ) ) {
			foreach ( $settings['excludePosts'] as $item ) {
				$item = is_string( $item ) ? json_decode( $item ) : $item;

				if ( ! isset( $item->value ) ) {
					continue;
				}

				array_push( $excludePosts, $item->value );
			}
		}

		if ( ! empty( $excludePosts ) ) {
			$query->whereNotIn( 'sao.object_id', $excludePosts );
		}

		return $query;
	}

	/**
	 * Apply the post types filter.
	 *
	 * @since 4.8.6
	 *
	 * @param  Database $query       The query.
	 * @param  string   $contentType An optional content type.
	 * @return Database              Returns the Database class which can then be method chained for building the query.
	 */
	private static function filterPostTypes( $query, $contentType = null ) {
		$settings        = aioseo()->dynamicOptions->seoAnalysis->all();
		$publicPostTypes = aioseo()->helpers->getScannablePostTypes();
		$postTypes       = $publicPostTypes;

		if ( 1 !== (int) $settings['postTypes']['all'] ) {
			$included  = $settings['postTypes']['included'];
			$postTypes = empty( $included ) ? [] : array_intersect( $publicPostTypes, $included );
		}

		if ( ! empty( $contentType ) && ! empty( $postTypes ) ) {
			$postTypes = array_intersect( $postTypes, [ $contentType ] );
		}

		$query->whereIn( 'p.post_type', $postTypes );

		return $query;
	}

	/**
	 * Apply the post statuses filter.
	 *
	 * @since 4.8.6
	 *
	 * @param  Database $query The query.
	 * @return Database        Returns the Database class which can then be method chained for building the query.
	 */
	private static function filterPostStatuses( $query ) {
		$settings     = aioseo()->dynamicOptions->seoAnalysis->all();
		$postStatuses = aioseo()->helpers->getPublicPostStatuses( true );

		if ( 1 !== (int) $settings['postStatuses']['all'] && ! empty( $settings['postStatuses']['included'] ) ) {
			$postStatuses = array_intersect( $postStatuses, $settings['postStatuses']['included'] );
		}

		$query->whereIn( 'p.post_status', $postStatuses );

		return $query;
	}

	/**
	 * Returns the base query to get all terms for the given issue code.
	 *
	 * @since 4.8.6
	 *
	 * @param  array    $filters    Filters.
	 * @param  string   $searchTerm An optional search term.
	 * @return Database             Returns the Database class which can then be method chained for building the query.
	 */
	public static function getTermsQuery( $filters = [], $searchTerm = '' ) {
		$query = aioseo()->core->db
			->start( 'terms as t' )
			->select( 'sao.id as issueId, sao.code, sao.object_id, sao.object_type, sao.object_subtype, sao.metadata, sao.is_ignored, t.name as title, null as date, null as status' )
			->leftJoin( 'aioseo_seo_analyzer_objects as sao', 'sao.object_id = t.term_id' )
			->where( 'sao.is_ignored', 0 )
			->where( 'sao.object_type', 'term' );

		if ( ! empty( $filters ) ) {
			foreach ( $filters as $filter ) {
				$query->where( $filter['column'], $filter['value'] );
			}
		}

		$query = self::filterTaxonomies( $query );
		$query = self::filterExcludedTerms( $query );

		if ( ! empty( $searchTerm ) ) {
			$escapedSearchTerm = esc_sql( aioseo()->core->db->db->esc_like( $searchTerm ) );
			$query->whereRaw( 't.name LIKE \'%' . $escapedSearchTerm . '%\'' );
		}

		return $query;
	}

	/**
	 * Returns the base query to get all terms.
	 *
	 * @since 4.8.6
	 *
	 * @param  string   $searchTerm        An optional search term.
	 * @param  array    $additionalFilters An optional array of additional filters.
	 * @return Database                    Returns the Database class which can then be method chained for building the query.
	 */
	public static function getAllUrlsTermsQuery( $searchTerm = '', $additionalFilters = [] ) {
		$query = aioseo()->core->db->noConflict()
			->start( 'terms as t' )
			->select( 'sao.object_id, sao.object_type, sao.object_subtype, t.name as title, null as date, null as keyphrases, null as status' )
			->leftJoin( 'aioseo_seo_analyzer_objects as sao', 'sao.object_id = t.term_id' )
			->where( 'sao.is_ignored', 0 )
			->where( 'sao.object_type', 'term' );

		$contentType = self::getContentTypeFilter( $additionalFilters );

		$query = self::filterTaxonomies( $query, $contentType );
		$query = self::filterExcludedTerms( $query );

		if ( ! empty( $searchTerm ) ) {
			$escapedSearchTerm = esc_sql( aioseo()->core->db->db->esc_like( $searchTerm ) );
			$query->whereRaw( 't.name LIKE \'%' . $escapedSearchTerm . '%\'' );
		}

		return $query;
	}

	/**
	 * Apply the excluded terms filter.
	 *
	 * @since 4.8.6
	 *
	 * @param  Database $query The query.
	 * @return Database        Returns the Database class which can then be method chained for building the query.
	 */
	private static function filterExcludedTerms( $query ) {
		$settings = aioseo()->dynamicOptions->seoAnalysis->all();

		$excludeTerms = [];
		if ( ! empty( $settings['excludeTerms'] ) ) {
			foreach ( $settings['excludeTerms'] as $item ) {
				$item = json_decode( $item );

				array_push( $excludeTerms, $item->value );
			}
		}

		if ( ! empty( $excludeTerms ) ) {
			$query->whereNotIn( 't.term_id', $excludeTerms );
		}

		return $query;
	}

	/**
	 * Apply the taxonomies object_subtype filter.
	 *
	 * @since 4.8.6
	 *
	 * @param  Database $query       The query.
	 * @param  string   $contentType An optional content type.
	 * @return Database              Returns the Database class which can then be method chained for building the query.
	 */
	private static function filterTaxonomies( $query, $contentType = null ) {
		$settings   = aioseo()->dynamicOptions->seoAnalysis->all();
		$taxonomies = array_diff( aioseo()->helpers->getPublicTaxonomies( true ), [ 'product_attributes' ] );

		if ( 1 !== (int) $settings['taxonomies']['all'] ) {
			$included   = $settings['taxonomies']['included'];
			$taxonomies = empty( $included ) ? [] : array_intersect( $taxonomies, $included );
		}

		if ( ! empty( $contentType ) && ! empty( $taxonomies ) ) {
			$taxonomies = array_intersect( $taxonomies, [ $contentType ] );
		}

		$query->whereIn( 'sao.object_subtype', $taxonomies );

		return $query;
	}

	/**
	 * Returns all objects for the given issue code.
	 *
	 * @since 4.8.6
	 *
	 * @param  string $code       The Issue code.
	 * @param  int    $limit      The limit.
	 * @param  int    $offset     The offset.
	 * @param  string $searchTerm An optional search term.
	 * @return array              List of Issues with obj info.
	 */
	public static function getObjectsByIssueCode( $code, $limit, $offset, $searchTerm = '' ) {
		$postsQuery = '';
		$termsQuery = '';
		$filters    = [
			[
				'column' => 'sao.code',
				'value'  => $code
			]
		];

		if ( self::hasPostType() && self::hasPostStatus() ) {
			$postsQuery = trim( str_replace( '/* %d = %d */', '', self::getPostsQuery( $filters, $searchTerm )->query() ) );
		}

		if ( self::hasTaxonomies() ) {
			$termsQuery = trim( str_replace( '/* %d = %d */', '', self::getTermsQuery( $filters, $searchTerm )->query() ) );
		}

		$sql = '';
		if ( ! empty( $postsQuery ) && ! empty( $termsQuery ) ) {
			$sql = '(' . $postsQuery . ') UNION (' . $termsQuery . ')';
		} elseif ( ! empty( $postsQuery ) ) {
			$sql = $postsQuery;
		} elseif ( ! empty( $termsQuery ) ) {
			$sql = $termsQuery;
		}

		$objects = aioseo()->core->db->execute(
			aioseo()->core->db->db->prepare(
				$sql . ' ORDER BY date DESC LIMIT ' . $limit . ' OFFSET ' . $offset
			),
			true
		)->result();

		if ( empty( $objects ) ) {
			return [];
		}

		$result = [];
		foreach ( $objects as $post ) {
			$obj = self::parseResultObject( $post );
			if ( empty( $obj ) ) {
				continue;
			}

			array_push( $result, $obj );
		}

		return $result;
	}

	/**
	 * Returns the total amount of all objects for the given issue code.
	 *
	 * @since 4.8.6
	 *
	 * @param  string $code       The Issue code.
	 * @param  string $searchTerm An optional search term.
	 * @return int                The total amount.
	 */
	public static function getTotalObjectsByIssueCode( $code, $searchTerm = '' ) {
		$totalPosts = 0;
		$totalTerms = 0;
		$filters    = [
			[
				'column' => 'sao.code',
				'value'  => $code
			]
		];

		if ( self::hasPostType() && self::hasPostStatus() ) {
			$totalPosts = self::getPostsQuery( $filters, $searchTerm )->count();
		}

		if ( self::hasTaxonomies() ) {
			$totalTerms = self::getTermsQuery( $filters, $searchTerm )->count();
		}

		return $totalPosts + $totalTerms;
	}

	/**
	 * Returns all objects.
	 *
	 * @since 4.8.6
	 *
	 * @param  int    $limit             The limit.
	 * @param  int    $offset            The offset.
	 * @param  string $searchTerm        An optional search term.
	 * @param  array  $additionalFilters An optional array of additional filters.
	 * @return array                     List of Issues with post title.
	 */
	public static function getResults( $limit, $offset, $searchTerm = '', $additionalFilters = [] ) {
		$postsQuery = self::getAllUrlsPostsQuery( $searchTerm, $additionalFilters );
		$postsSql   = '';
		$termsQuery = self::getAllUrlsTermsQuery( $searchTerm, $additionalFilters );
		$termsSql   = '';

		$contentType   = self::getContentTypeFilter( $additionalFilters );
		$hasPostType   = self::hasPostType( $contentType );
		$hasTaxonomies = self::hasTaxonomies( $contentType );
		$hasPostStatus = self::hasPostStatus();

		if ( $hasPostType && $hasPostStatus ) {
			$postsSql = trim( str_replace( '/* %d = %d */', '', $postsQuery->query() ) );
		}

		if ( $hasTaxonomies ) {
			$termsSql = trim( str_replace( '/* %d = %d */', '', $termsQuery->query() ) );
		}

		if ( ! empty( $postsSql ) && ! empty( $termsSql ) ) {
			$objects = aioseo()->core->db->execute(
				aioseo()->core->db->db->prepare(
					'(' . $postsSql . ') UNION (' . $termsSql . ') ORDER BY date DESC LIMIT ' . $limit . ' OFFSET ' . $offset
				),
				true
			)->result();
		} elseif ( ! empty( $postsSql ) ) {
			$objects = $postsQuery
				->orderBy( 'date DESC' )
				->groupBy( 'sao.object_id', 'sao.object_subtype' )
				->limit( $limit, $offset )
				->run()
				->result();
		} elseif ( ! empty( $termsSql ) ) {
			$objects = $termsQuery
				->orderBy( 'date DESC' )
				->groupBy( 'sao.object_id', 'sao.object_subtype' )
				->limit( $limit, $offset )
				->run()
				->result();
		}

		if ( empty( $objects ) ) {
			return [];
		}

		$issuesCount = [];
		if ( $hasPostType && $hasPostStatus ) {
			$issuesCount = array_merge( $issuesCount, self::getPostsIssuesCount( $additionalFilters ) );
		}

		if ( $hasTaxonomies ) {
			$issuesCount = array_merge( $issuesCount, self::getTermsIssuesCount( $additionalFilters ) );
		}

		$result = [];
		foreach ( $objects as $obj ) {
			$key    = $obj->object_id . '-' . $obj->object_type;
			$counts = isset( $issuesCount[ $key ] ) ? $issuesCount[ $key ] : 0;
			$obj    = self::parseResultObject( $obj, $counts );
			if ( empty( $obj ) ) {
				continue;
			}

			array_push( $result, $obj );
		}

		return $result;
	}

	/**
	 * Returns the total number of objects.
	 *
	 * @since 4.8.6
	 *
	 * @param  string $searchTerm        An optional search term.
	 * @param  array  $additionalFilters An optional array of additional filters.
	 * @return int                        The total amount.
	 */
	public static function getTotalResults( $searchTerm = '', $additionalFilters = [] ) {
		$posts = 0;
		$terms = 0;

		$contentType   = self::getContentTypeFilter( $additionalFilters );
		$hasPostType   = self::hasPostType( $contentType );
		$hasTaxonomies = self::hasTaxonomies( $contentType );
		$hasPostStatus = self::hasPostStatus();

		if ( $hasPostType && $hasPostStatus ) {
			$posts = self::getAllUrlsPostsQuery( $searchTerm, $additionalFilters )
				->groupBy( 'sao.object_id', 'sao.object_subtype' )
				->count();
		}

		if ( $hasTaxonomies ) {
			$terms = self::getAllUrlsTermsQuery( $searchTerm, $additionalFilters )
				->groupBy( 'sao.object_id', 'sao.object_subtype' )
				->count();
		}

		return $posts + $terms;
	}

	/**
	 * Returns the query that gets issues count for posts by status.
	 *
	 * @since 4.8.6
	 *
	 * @param  array  $additionalFilters An optional array of additional filters.
	 * @param  string $status            The status of the issues to count.
	 * @return Database                  Returns the Database class which can then be method chained for building the query.
	 */
	private static function getPostsIssuesCountByStatus( $additionalFilters = [], $status = 'error' ) {
		$query = aioseo()->core->db->noConflict()
			->start( 'aioseo_seo_analyzer_objects as sao' )
			->select( 'COUNT(sao.id) as count, p.ID, sao.object_id, sao.object_type' )
			->join( 'posts as p', 'sao.object_id = p.ID' )
			->where( 'sao.is_ignored', 0 )
			->where( 'sao.object_type', 'post' );

		$errorCodes = aioseo()->seoAnalysis->getCodesByStatus( $status );
		if ( ! empty( $errorCodes ) ) {
			$query->whereIn( 'sao.code', $errorCodes );
		}

		$contentType = self::getContentTypeFilter( $additionalFilters );

		$query = self::filterPostTypes( $query, $contentType );
		$query = self::filterPostStatuses( $query );
		$query = self::filterExcludedPosts( $query );

		$query->groupBy( 'p.ID' );

		return $query;
	}

	/**
	 * Returns the query that gets issues count for terms.
	 *
	 * @since 4.8.6
	 *
	 * @param  array  $additionalFilters An optional array of additional filters.
	 * @param  string $status            The status of the issues to count.
	 * @return Database                  Returns the Database class which can then be method chained for building the query.
	 */
	private static function getTermsIssuesCountByStatus( $additionalFilters = [], $status = 'error' ) {
		$query = aioseo()->core->db->noConflict()
			->start( 'aioseo_seo_analyzer_objects as sao' )
			->select( 'COUNT(sao.id) as count, t.term_id, sao.object_id, sao.object_type' )
			->join( 'terms as t', 'sao.object_id = t.term_id' )
			->where( 'sao.is_ignored', 0 )
			->where( 'sao.object_type', 'term' );

		$errorCodes = aioseo()->seoAnalysis->getCodesByStatus( $status );
		if ( ! empty( $errorCodes ) ) {
			$query->whereIn( 'sao.code', $errorCodes );
		}

		$contentType = self::getContentTypeFilter( $additionalFilters );

		$query = self::filterTaxonomies( $query, $contentType );
		$query = self::filterExcludedTerms( $query );

		$query->groupBy( 't.term_id' );

		return $query;
	}

	/**
	 * Returns all issues for the given object id and object type.
	 *
	 * @since 4.8.6
	 *
	 * @param  int    $objectId   The object id.
	 * @param  string $objectType The object type (post|term).
	 * @param  string $searchTerm An optional search term.
	 * @return array              List of Issues with object info.
	 */
	public static function getIssuesByObject( $objectId, $objectType, $searchTerm = '' ) {
		$postsQuery = '';
		$termsQuery = '';
		$filters    = [
			[
				'column' => 'sao.object_id',
				'value'  => $objectId
			],
			[
				'column' => 'sao.object_type',
				'value'  => $objectType
			]
		];

		if ( self::hasPostType() && self::hasPostStatus() ) {
			$postsQuery = trim( str_replace( '/* %d = %d */', '', self::getPostsQuery( $filters, $searchTerm )->query() ) );
		}

		if ( self::hasTaxonomies() ) {
			$termsQuery = trim( str_replace( '/* %d = %d */', '', self::getTermsQuery( $filters, $searchTerm )->query() ) );
		}

		$sql = '';
		if ( ! empty( $postsQuery ) && ! empty( $termsQuery ) ) {
			$sql = '(' . $postsQuery . ') UNION (' . $termsQuery . ')';
		} elseif ( ! empty( $postsQuery ) ) {
			$sql = $postsQuery;
		} elseif ( ! empty( $termsQuery ) ) {
			$sql = $termsQuery;
		}

		$objects = aioseo()->core->db->execute(
			aioseo()->core->db->db->prepare(
				$sql . ' ORDER BY date DESC'
			),
			true
		)->result();

		if ( empty( $objects ) ) {
			return [];
		}

		$result = [];
		foreach ( $objects as $item ) {
			$obj = self::parseResultObject( $item );
			if ( empty( $obj ) ) {
				continue;
			}

			array_push( $result, $obj );
		}

		// Sort results by status (error, warning, passed)
		$result = self::sortResultsByStatus( $result );

		return $result;
	}

	/**
	 * Returns the total amount of issues for the given object id and object type.
	 *
	 * @since 4.8.6
	 *
	 * @param  int    $objectId   The object id.
	 * @param  string $objectType The object type.
	 * @param  string $searchTerm An optional search term.
	 * @return int                The total amount.
	 */
	public static function getTotalIssuesByObject( $objectId, $objectType, $searchTerm = '' ) {
		$posts   = 0;
		$terms   = 0;
		$filters = [
			[
				'column' => 'sao.object_id',
				'value'  => $objectId
			],
			[
				'column' => 'sao.object_type',
				'value'  => $objectType
			]
		];

		if ( self::hasPostType() && self::hasPostStatus() ) {
			$posts = self::getPostsQuery( $filters, $searchTerm )->orderBy( 'date DESC' )->count();
		}

		if ( self::hasTaxonomies() ) {
			$terms = self::getTermsQuery( $filters, $searchTerm )->orderBy( 'date DESC' )->count();
		}

		return $posts + $terms;
	}

	/**
	 * Inserts a new issue into the database.
	 *
	 * @since 4.8.6
	 *
	 * @param  Issue $model Issue object to be inserted.
	 * @return void
	 */
	public static function insert( $model ) {
		aioseo()->core->db->insert( 'aioseo_seo_analyzer_objects' )
			->set( [
				'object_id'      => $model->object_id, // @phpstan-ignore-line
				'object_type'    => $model->object_type, // @phpstan-ignore-line
				'object_subtype' => $model->object_subtype, // @phpstan-ignore-line
				'code'           => $model->code, // @phpstan-ignore-line
				'metadata'       => $model->metadata ?? null, // @phpstan-ignore-line
				'is_ignored'     => (int) $model->is_ignored, // @phpstan-ignore-line
			] )
			->run();
	}

	/**
	 * Delete all issues for the given object.
	 *
	 * @since 4.8.6
	 *
	 * @param int    $objectId   The object id
	 * @param string $objectType Object type (post, term)
	 * @return void
	 */
	public static function deleteAll( $objectId, $objectType ) {
		aioseo()->core->db->delete( 'aioseo_seo_analyzer_objects' )
			->where( 'object_id', $objectId )
			->where( 'object_type', $objectType )
			->run();
	}

	/**
	 * Receive the object and parse it to a new object.
	 *
	 * @since 4.8.6
	 *
	 * @param  object      $result The result object.
	 * @param  array       $counts The items count for the object separated by status.
	 * @return object|null         The new object.
	 */
	private static function parseResultObject( $result, $counts = [] ) {
		$resultId         = intval( $result->object_id );
		$isTruSeoEligible = false;

		switch ( $result->object_type ) {
			case 'post':
				$permalink = get_permalink( $resultId );
				$editLink  = get_edit_post_link( $resultId, '' );
				$isTruSeoEligible = ! aioseo()->helpers->isWooCommercePage( $resultId ) && ! aioseo()->helpers->isStaticPostsPage( $resultId ) && aioseo()->helpers->isTruSeoEligible( $resultId );
				break;
			case 'term':
				$permalink = get_term_link( $resultId, $result->object_subtype );
				$editLink  = get_edit_term_link( $resultId, $result->object_subtype );
				break;
			default:
				$permalink = '';
				$editLink  = '';
				break;
		}

		if ( empty( $permalink ) && empty( $editLink ) ) {
			return null;
		}

		$fixLink = $editLink;
		$canFix  = false;
		if ( @$result->code && strpos( $result->code, 'author-bio-' ) === 0 ) {
			$authorId = get_post_field( 'post_author', $resultId );
			$fixLink  = get_edit_user_link( $authorId );

			if ( (int) get_current_user_id() === (int) $authorId ) {
				$canFix = true;
			}
		}

		if ( ! empty( $result->code ) && ! $canFix ) {
			$canFix = aioseo()->seoAnalysis->userCanFixIssueByCode( $result->code );

			if ( ! $canFix ) {
				$fixLink = '';
			}
		}

		// Using `@` to avoid PHP undefined property warnings.
		$obj = [
			'issueId'          => (int) @$result->issueId,
			'code'             => @$result->code,
			'metadata'         => is_string( @$result->metadata ) ? json_decode( @$result->metadata ) : @$result->metadata,
			'id'               => (int) $resultId,
			'title'            => empty( $result->title ) ? __( '(no title)' ) : $result->title, // phpcs:ignore AIOSEO.Wp.I18n.MissingArgDomain
			'permalink'        => is_string( $permalink ) ? $permalink : null,
			'editLink'         => is_string( $editLink ) ? $editLink : null,
			'fixLink'          => is_string( $fixLink ) ? $fixLink : null,
			'type'             => @$result->object_type,
			'status'           => 'post' === @$result->object_type ? $result->status : null,
			'subtype'          => [
				'value' => @$result->object_subtype,
				'label' => self::getSubtypeName( @$result->object_type, @$result->object_subtype )
			],
			'count'            => 1111,
			'counts'           => $counts,
			'keyphrases'       => @$result->keyphrases ? json_decode( $result->keyphrases ) : [],
			'isTruSeoEligible' => $isTruSeoEligible
		];

		return (object) $obj;
	}

	/**
	 * Get the translated subtype name.
	 *
	 * @since 4.8.6
	 *
	 * @param  string $objectType    The object type (post|term).
	 * @param  string $objectSubtype The object subtype.
	 * @return string                The translated subtype name.
	 */
	private static function getSubtypeName( $objectType, $objectSubtype ) {
		switch ( $objectType ) {
			case 'term':
				$taxonomy = get_taxonomy( $objectSubtype );

				return $taxonomy ? $taxonomy->labels->singular_name : $objectSubtype;
			case 'post':
				$postType = get_post_type_object( $objectSubtype );

				return $postType ? $postType->labels->singular_name : $objectSubtype;
			default:
				return $objectSubtype;
		}
	}

	/**
	 * Check if the post type is included.
	 *
	 * @since 4.8.6
	 *
	 * @param  string|null $contentType An optional content type.
	 * @return bool                     True if the post type is included, false otherwise.
	 */
	private static function hasPostType( $contentType = null ) {
		$settings        = aioseo()->dynamicOptions->seoAnalysis->all();
		$displayAll      = 1 === (int) $settings['postTypes']['all'];
		$publicPostTypes = aioseo()->helpers->getScannablePostTypes();
		$postTypes       = $publicPostTypes;

		if ( ! $displayAll ) {
			$included  = $settings['postTypes']['included'];
			$postTypes = empty( $included ) ? [] : array_intersect( $publicPostTypes, $included );
		}

		if ( ! empty( $contentType ) && ! empty( $postTypes ) ) {
			$postTypes = array_intersect( $postTypes, [ $contentType ] );
		}

		return ! empty( $postTypes );
	}

	/**
	 * Check if the post type is included.
	 *
	 * @since 4.8.6
	 *
	 * @return bool True if the post type is included, false otherwise.
	 */
	private static function hasPostStatus() {
		$settings    = aioseo()->dynamicOptions->seoAnalysis->all();
		$displayAll  = 1 === (int) $settings['postStatuses']['all'];
		$hasIncluded = ! empty( $settings['postStatuses']['included'] );

		if ( $displayAll || $hasIncluded ) {
			return true;
		}

		return false;
	}

	/**
	 * Check if the taxonomies are included.
	 *
	 * @since 4.8.6
	 *
	 * @param  string|null $contentType An optional content type.
	 * @return bool                     True if the taxonomies are included, false otherwise.
	 */
	private static function hasTaxonomies( $contentType = null ) {
		$settings         = aioseo()->dynamicOptions->seoAnalysis->all();
		$displayAll       = 1 === (int) $settings['taxonomies']['all'];
		$publicTaxonomies = array_diff( aioseo()->helpers->getPublicTaxonomies( true ), [ 'product_attributes' ] );
		$taxonomies       = $publicTaxonomies;

		if ( ! $displayAll ) {
			$included   = $settings['taxonomies']['included'];
			$taxonomies = empty( $included ) ? [] : array_intersect( $publicTaxonomies, $included );
		}

		if ( ! empty( $contentType ) && ! empty( $taxonomies ) ) {
			$taxonomies = array_intersect( $taxonomies, [ $contentType ] );
		}

		return ! empty( $taxonomies );
	}

	/**
	 * Get the content types from additional filters.
	 *
	 * @since 4.8.6
	 *
	 * @param  array $additionalFilters An optional array of additional filters.
	 * @return array                    The content types.
	 */
	private static function getContentTypeFilter( $additionalFilters = [] ) {
		return isset( $additionalFilters['content_type'] ) && 'all' !== $additionalFilters['content_type']
			? $additionalFilters['content_type']
			: null;
	}

	/**
	 * Get the posts issues count.
	 *
	 * @since 4.8.6
	 *
	 * @param  array $additionalFilters An optional array of additional filters.
	 * @return array                    The posts issues count.
	 */
	private static function getPostsIssuesCount( $additionalFilters = [] ) {
		$result = [];
		$errors   = self::getPostsIssuesCountByStatus( $additionalFilters, 'error' )
			->run()
			->result();
		$warnings = self::getPostsIssuesCountByStatus( $additionalFilters, 'warning' )
			->run()
			->result();
		$passeds  = self::getPostsIssuesCountByStatus( $additionalFilters, 'passed' )
			->run()
			->result();

		foreach ( $errors as $item ) {
			$result[ $item->object_id . '-post' ]['error'] = (int) $item->count;
		}

		foreach ( $warnings as $item ) {
			$result[ $item->object_id . '-post' ]['warning'] = (int) $item->count;
		}

		foreach ( $passeds as $item ) {
			$result[ $item->object_id . '-post' ]['passed'] = (int) $item->count;
		}

		return $result;
	}

	/**
	 * Get the terms issues count.
	 *
	 * @since 4.8.6
	 *
	 * @param  array $additionalFilters An optional array of additional filters.
	 * @return array                    The terms issues count.
	 */
	private static function getTermsIssuesCount( $additionalFilters = [] ) {
		$result = [];
		$errors   = self::getTermsIssuesCountByStatus( $additionalFilters, 'error' )
			->run()
			->result();
		$warnings = self::getTermsIssuesCountByStatus( $additionalFilters, 'warning' )
			->run()
			->result();
		$passeds  = self::getTermsIssuesCountByStatus( $additionalFilters, 'passed' )
			->run()
			->result();

		foreach ( $errors as $item ) {
			$result[ $item->object_id . '-term' ]['error'] = (int) $item->count;
		}

		foreach ( $warnings as $item ) {
			$result[ $item->object_id . '-term' ]['warning'] = (int) $item->count;
		}

		foreach ( $passeds as $item ) {
			$result[ $item->object_id . '-term' ]['passed'] = (int) $item->count;
		}

		return $result;
	}
}