<?php
namespace AIOSEO\Plugin\Addon\LinkAssistant\Models;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Models as CommonModels;

/**
 * The Suggestion DB Model.
 *
 * @since 1.0.0
 */
class Suggestion extends CommonModels\Model {
	/**
	 * The name of the table in the database, without the prefix.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $table = 'aioseo_links_suggestions';

	/**
	 * Fields that should be numeric values.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $integerFields = [ 'id', 'post_id' ];

	/**
	 * Fields that should be JSON encoded.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $jsonFields = [ 'phrases' ];

	/**
	 * Appended as an extra column, but not stored in the DB.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $appends = [ 'context' ];

	/**
	 * Returns the inbound suggestions for the given post.
	 *
	 * @since 1.0.0
	 *
	 * @param  int   $postId The post ID.
	 * @param  int   $limit  The limit.
	 * @param  int   $offset The offset.
	 * @return array         The inbound suggestions.
	 */
	public static function getInboundSuggestions( $postId, $limit = 10, $offset = 0 ) {
		$post = get_post( $postId );
		if ( ! is_a( $post, 'WP_Post' ) ) {
			return [];
		}

		// If the post hasn't been published, there's no need to get inbound suggestions since the permalink isn't final.
		if ( 'publish' !== $post->post_status ) {
			return [];
		}

		$query   = self::getBaseInboundSuggestionsQuery( $postId );
		$postIds = $query->select( 'als.post_id' )
			->join( 'aioseo_posts as ap', 'als.post_id = ap.post_id' )
			->orderBy( 'ap.pillar_content DESC' )
			->limit( $limit, $offset )
			->run()
			->result();

		if ( empty( $postIds ) ) {
			return [];
		}

		$mappedPostIds = array_map( function ( $row ) {
			return $row->post_id;
		}, $postIds );

		$inboundSuggestions = aioseo()->core->db->start( 'aioseo_links_suggestions as als' )
			->select( 'als.*, ap.pillar_content' )
			->leftJoin( 'aioseo_posts as ap', 'als.post_id = ap.post_id' )
			->where( 'als.linked_post_id', $postId )
			->whereIn( 'als.post_id', $mappedPostIds )
			->orderBy( 'ap.pillar_content DESC' )
			->run()
			->models( 'AIOSEO\\Plugin\\Addon\\LinkAssistant\\Models\\Suggestion' );

		$groupedSuggestions = [];
		$targetPermalink    = get_permalink( $postId );
		foreach ( $inboundSuggestions as $inboundSuggestion ) {
			if ( ! isset( $groupedSuggestions[ $inboundSuggestion->post_id ] ) ) {
				$groupedSuggestions[ $inboundSuggestion->post_id ] = [
					'suggestions'          => [],
					'permalink'            => $targetPermalink,
					'isCornerstoneContent' => (int) $inboundSuggestion->pillar_content,
				];
			}

			$groupedSuggestions[ $inboundSuggestion->post_id ]['suggestions'][] = $inboundSuggestion;
		}

		$groupedSuggestionsWithContext = [];
		foreach ( $groupedSuggestions as $linkedPostId => $groupedSuggestion ) {
			$post = aioseo()->helpers->getPost( $linkedPostId );
			if ( ! is_a( $post, 'WP_Post' ) ) {
				continue;
			}

			$postTypeObject = get_post_type_object( get_post_type( $linkedPostId ) );

			$groupedSuggestion['context'] = [
				'postTitle'            => aioseo()->helpers->getPostTitle( $linkedPostId ),
				'permalink'            => get_permalink( $linkedPostId ),
				'editLink'             => get_edit_post_link( $linkedPostId, '' ),
				'isCornerstoneContent' => boolval( $groupedSuggestion['isCornerstoneContent'] ),
				'postType'             => aioseo()->helpers->getPostType( $postTypeObject )
			];

			$groupedSuggestionsWithContext[] = $groupedSuggestion;
		}

		return $groupedSuggestionsWithContext;
	}

	/**
	 * Returns the outbound suggestions for the given post.
	 *
	 * @since 1.0.0
	 *
	 * @param  int   $postId The post ID.
	 * @param  int   $limit  The limit.
	 * @param  int   $offset The offset.
	 * @return array         The outbound suggestions.
	 */
	public static function getOutboundSuggestions( $postId, $limit = 10, $offset = 0 ) {
		$subQuery = self::getBaseOutboundSuggestionsQuery( $postId )
			->select( 'als.phrase' )
			->join( 'aioseo_posts as ap', 'als.linked_post_id = ap.post_id' )
			->groupBy( 'ap.pillar_content' )
			->orderBy( 'ap.pillar_content DESC' )
			->query();

		$limitClause = $limit ? "LIMIT $limit OFFSET $offset" : '';

		// We need to the distinct phrases through a subquery because the double GROUP BY clause
		// can return duplicate phrases if one links to a cornerstone post and the other doesn't.
		$phrases = aioseo()->core->db->execute(
			"SELECT DISTINCT phrase
			FROM (
				$subQuery
			) as x
			$limitClause",
			true
		)->result();

		if ( empty( $phrases ) ) {
			return [];
		}

		$mappedPhrases = array_map( function ( $row ) {
			return $row->phrase;
		}, $phrases );

		// TODO: Consider moving index from phrase to new phrase hash column.
		$outboundSuggestions = aioseo()->core->db->start( 'aioseo_links_suggestions as als' )
			->select( 'als.*, ap.pillar_content' )
			->join( 'posts as p', 'als.linked_post_id = p.ID' )
			->leftJoin( 'aioseo_posts as ap', 'als.linked_post_id = ap.post_id' )
			->where( 'als.post_id', $postId )
			->where( 'p.post_status', 'publish' )
			->whereIn( 'als.phrase', $mappedPhrases )
			->orderBy( 'ap.pillar_content DESC' )
			->run()
			->result();

		$groupedSuggestions = [];
		foreach ( $outboundSuggestions as $outboundSuggestion ) {
			if ( ! isset( $groupedSuggestions[ $outboundSuggestion->phrase ] ) ) {
				$groupedSuggestions[ $outboundSuggestion->phrase ] = [
					'suggestions' => []
				];
			}

			$postId = $outboundSuggestion->linked_post_id;
			$post   = aioseo()->helpers->getPost( $postId );
			if (
				! is_a( $post, 'WP_Post' ) ||
				'publish' !== $post->post_status
			) {
				continue;
			}

			// Update the outbound suggestion context.
			$outboundSuggestion->context                       = new \stdClass();
			$outboundSuggestion->context->postTitle            = aioseo()->helpers->getPostTitle( $postId );
			$outboundSuggestion->context->permalink            = get_permalink( $postId );
			$outboundSuggestion->context->editLink             = get_edit_post_link( $postId, '' );
			$outboundSuggestion->context->isCornerstoneContent = (int) $outboundSuggestion->pillar_content;

			$postTypeObject                         = get_post_type_object( get_post_type( $postId ) );
			$outboundSuggestion->context->postType  = aioseo()->helpers->getPostType( $postTypeObject );

			$groupedSuggestions[ $outboundSuggestion->phrase ]['suggestions'][] = $outboundSuggestion;
		}

		$numericGroupedSuggestions = array_values( $groupedSuggestions );

		return $numericGroupedSuggestions;
	}

	/**
	 * Returns the total amount of inbound suggestions for the given post.
	 *
	 * @since 1.0.0
	 *
	 * @param  int  $postId The post ID.
	 * @return int          The total amount of inbound suggestions.
	 */
	public static function getTotalInboundSuggestions( $postId ) {
		$post = get_post( $postId );
		if ( ! is_a( $post, 'WP_Post' ) ) {
			return 0;
		}

		// If the post hasn't been published, there's no need to get inbound suggestions since the permalink isn't final.
		if ( 'publish' !== $post->post_status ) {
			return 0;
		}

		$subquery = self::getBaseInboundSuggestionsQuery( $postId )
			->select( 'count(*)' );
		$subquery = $subquery->query();

		$count = aioseo()->core->db->execute(
			"SELECT count(*) as totalInboundSuggestions
			FROM ( {$subquery} ) as x",
			true
		)->result();

		return ! empty( $count[0]->totalInboundSuggestions ) ? (int) $count[0]->totalInboundSuggestions : 0;
	}

	/**
	 * Returns the total amount of outbound suggestions for the given post.
	 *
	 * @since 1.0.0
	 *
	 * @param  int  $postId The post ID.
	 * @return int          The total amount of outbound suggestions.
	 */
	public static function getTotalOutboundSuggestions( $postId ) {
		$subquery = self::getBaseOutboundSuggestionsQuery( $postId )
			->select( 'count(*)' );
		$subquery = $subquery->query();

		$count = aioseo()->core->db->execute(
			"SELECT count(*) as totalOutboundSuggestions
			FROM ( {$subquery} ) as x",
			true
		)->result();

		return ! empty( $count[0]->totalOutboundSuggestions ) ? (int) $count[0]->totalOutboundSuggestions : 0;
	}

	/**
	 * Returns the base query for inbound suggestions.
	 *
	 * @since 1.0.0
	 *
	 * @param  int                                  $postId The post ID.
	 * @return \AIOSEO\Plugin\Common\Utils\Database         The Database instance.
	 */
	private static function getBaseInboundSuggestionsQuery( $postId ) {
		$query = self::getBaseSuggestionsQuery();

		return $query->where( 'als.linked_post_id', $postId )
			->groupBy( 'als.post_id' );
	}

	/**
	 * Returns the base query for inbound suggestions.
	 *
	 * @since 1.0.0
	 *
	 * @param  int                                  $postId The post ID.
	 * @return \AIOSEO\Plugin\Common\Utils\Database         The Database instance.
	 */
	private static function getBaseOutboundSuggestionsQuery( $postId ) {
		$query = self::getBaseSuggestionsQuery();

		return $query->where( 'als.post_id', $postId )
			->groupBy( 'als.phrase' );
	}

	/**
	 * Returns the base query for suggestions.
	 *
	 * @since 1.0.0
	 *
	 * @return \AIOSEO\Plugin\Common\Utils\Database The Database instance.
	 */
	private static function getBaseSuggestionsQuery() {
		return aioseo()->core->db->start( 'aioseo_links_suggestions as als' )
			->join( 'posts as p', 'als.linked_post_id = p.ID' )
			->where( 'p.post_status', 'publish' )
			->where( 'als.dismissed', 0 );
	}

	/**
	 * Returns the total amount of posts with suggestions.
	 *
	 * @since 1.0.0
	 *
	 * @return int The total amount of posts.
	 */
	public static function getTotalPosts() {
		aioseoLinkAssistant()->helpers->maybeCreateTempTables();

		$firstSubQuery = aioseo()->core->db->noConflict()->start( 'aioseo_links_suggestions' )
			->select( 'post_id' )
			->where( 'dismissed', 0 );

		$secondSubQuery = aioseo()->core->db->noConflict()->start( 'aioseo_links_suggestions' )
			->select( 'linked_post_id' )
			->where( 'dismissed', 0 );

		$excludedPostIds = aioseoLinkAssistant()->helpers->getExcludedPostIds();
		if ( ! empty( $excludedPostIds ) ) {
			$firstSubQuery->whereNotIn( 'post_id', $excludedPostIds );
			$secondSubQuery->whereNotIn( 'post_id', $excludedPostIds );
		}

		$tempTableName = aioseo()->core->db->prefix . 'aioseotemp_la_included_posts';

		$count = aioseo()->core->db->execute(
			"SELECT count(*) AS totalPosts
			FROM ( {$firstSubQuery->query()} UNION {$secondSubQuery->query()} ) AS x
			JOIN {$tempTableName} AS p ON x.post_id = p.ID",
			true
		)->result();

		return ! empty( $count[0]->totalPosts ) ? (int) $count[0]->totalPosts : 0;
	}

	/**
	 * Sanitizes the suggestion object.
	 *
	 * @since 1.0.0
	 *
	 * @param  array $suggestion The suggestion data.
	 * @return array             The sanitized suggestion data.
	 */
	public static function sanitizeSuggestion( $suggestion ) {
		$sanitizedSuggestion = [];

		foreach ( $suggestion as $k => $v ) {
			switch ( $k ) {
				case 'post_id':
				case 'linked_post_id':
					$v = intval( $v );
					break;
				case 'anchor':
				case 'phrase':
				case 'paragraph':
					$v = sanitize_text_field( $v );
					break;
				case 'phrase_html':
				case 'paragraph_html':
				case 'original_phrase_html':
					$v = aioseoLinkAssistant()->helpers->wpKsesPhrase( $v );
					break;
				default:
					break;
			}

			if ( empty( $v ) ) {
				return [];
			}

			$sanitizedSuggestion[ $k ] = esc_sql( $v );
		}

		return $sanitizedSuggestion;
	}

	/**
	 * Checks whether the given suggestion is valid.
	 *
	 * @since 1.0.0
	 *
	 * @param  array $suggestion The suggestion data.
	 * @return bool              Whether the suggestion is valid or not.
	 */
	public static function validateSuggestion( $suggestion ) {
		$propsToCheck = [
			'linked_post_id',
			'anchor',
			'phrase',
			'phrase_html',
			'original_phrase_html',
			'paragraph',
			'paragraph_html'
		];

		foreach ( $propsToCheck as $prop ) {
			$value = wp_strip_all_tags( $suggestion[ $prop ] );
			if ( empty( $value ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Dismisses the given suggestion.
	 *
	 * @since 1.0.0
	 *
	 * @param  int  $suggestionId The suggestion ID.
	 * @return void
	 */
	public static function dismissSuggestion( $suggestionId ) {
		$suggestion = aioseo()->core->db->start( 'aioseo_links_suggestions' )
			->where( 'id', $suggestionId )
			->run()
			->model( 'AIOSEO\\Plugin\\Addon\\LinkAssistant\\Models\\Suggestion' );

		if ( $suggestion->exists() ) {
			$suggestion->set( [ 'dismissed' => true ] );
			$suggestion->save();
		}
	}

	/**
	 * Deletes the given suggestion.
	 *
	 * @since 1.0.0
	 *
	 * @param  int  $suggestionId The Suggestion ID.
	 * @return void
	 */
	public static function deleteSuggestionById( $suggestionId ) {
		aioseo()->core->db->delete( 'aioseo_links_suggestions' )
			->where( 'id', $suggestionId )
			->run();
	}

	/**
	 * Deletes all non-dismissed suggestions for the given post.
	 *
	 * @since 1.0.0
	 *
	 * @param  array $postsToScan The posts that we're about to scan.
	 * @return void
	 */
	public static function deleteNonDismissedSuggestions( $postsToScan ) {
		if ( ! is_array( $postsToScan ) ) {
			$postsToScan = [ $postsToScan ];
		}

		$postIds = array_map( function( $post ) {
			return $post->ID;
		}, $postsToScan );

		aioseo()->core->db->delete( 'aioseo_links_suggestions' )
			->whereIn( 'post_id', $postIds )
			->where( 'dismissed', 0 )
			->run();
	}
}