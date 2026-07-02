<?php
namespace AIOSEO\Plugin\Addon\LinkAssistant\Models;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Models as CommonModels;

/**
 * The Link DB Model.
 *
 * @since 1.0.0
 */
class Link extends CommonModels\Model {
	/**
	 * The name of the table in the database, without the prefix.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $table = 'aioseo_links';

	/**
	 * Fields that should be numeric values.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $integerFields = [ 'id', 'post_id', 'linked_post_id' ];

	/**
	 * Fields that are nullable.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $nullFields = [ 'linked_post_id' ];

	/**
	 * Fields that should be boolean values.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $booleanFields = [
		'internal',
		'affiliate',
		'external'
	];

	/**
	 * Appended as an extra column, but not stored in the DB.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $appends = [ 'context' ];

	/**
	 * Returns the Link with the given ID.
	 *
	 * @since 1.0.0
	 *
	 * @param  int  $linkId The Link ID.
	 * @return Link         The Link.
	 */
	public static function getLinkById( $linkId ) {
		return aioseo()->core->db->start( 'aioseo_links' )
			->where( 'id', $linkId )
			->run()
			->model( 'AIOSEO\\Plugin\\Addon\\LinkAssistant\\Models\\Link' );
	}

	/**
	 * Returns all links for the given post.
	 *
	 * @since 1.0.0
	 *
	 * @param  int         $postId      The post ID.
	 * @param  string      $whereClause An optional WHERE clause for search queries.
	 * @return array[Link]              The Links.
	 */
	public static function getLinks( $postId, $whereClause = '' ) {
		$query = aioseo()->core->db->start( 'aioseo_links' )
			->where( 'post_id', $postId );

		if ( ! empty( $whereClause ) ) {
			$query->whereRaw( $whereClause );
		}

		return $query->run()
			->models( 'AIOSEO\\Plugin\\Addon\\LinkAssistant\\Models\\Link' );
	}

	/**
	 * Returns inbound internal links that refer to the given post.
	 *
	 * @since 1.0.0
	 *
	 * @param  int         $linkedPostId The ID of the post the Link refers to.
	 * @param  int         $limit        The limit.
	 * @param  int         $offset       The offset.
	 * @return array[Link]               The Links.
	 */
	public static function getInboundInternalLinks( $linkedPostId, $limit = 20, $offset = 0 ) {
		$links = aioseo()->core->db->start( 'aioseo_links as al' )
			->select( '*' )
			->join( 'posts as p', 'al.post_id = p.ID' ) // Ensure the post isn't orphaned.
			->where( 'al.linked_post_id', $linkedPostId )
			->limit( $limit, $offset )
			->run()
			->models( 'AIOSEO\\Plugin\\Addon\\LinkAssistant\\Models\\Link' );

		foreach ( $links as $link ) {
			$link->context            = new \stdClass();
			$link->context->permalink = get_permalink( $link->post_id );
			$link->context->postTitle = aioseo()->helpers->getPostTitle( $link->post_id );
			$link->context->editLink  = get_edit_post_link( $link->post_id, '' );

			$postTypeObject           = get_post_type_object( get_post_type( $link->post_id ) );
			$link->context->postType  = aioseo()->helpers->getPostType( $postTypeObject );
		}

		return $links;
	}

	/**
	 * Returns outbound internal links for the given post.
	 *
	 * @since 1.0.0
	 *
	 * @param  int         $postId The post ID.
	 * @param  int         $limit  The limit.
	 * @param  int         $offset The offset.
	 * @return array[Link]         The Links.
	 */
	public static function getOutboundInternalLinks( $postId, $limit = 20, $offset = 0 ) {
		$links = aioseo()->core->db->start( 'aioseo_links' )
			->select( '*' )
			->where( 'post_id', $postId )
			->where( 'internal', 1 )
			->limit( $limit, $offset )
			->run()
			->models( 'AIOSEO\\Plugin\\Addon\\LinkAssistant\\Models\\Link' );

		foreach ( $links as $link ) {
			$link->context            = new \stdClass();
			$link->context->postTitle = aioseo()->helpers->getPostTitle( $link->linked_post_id );
			$link->context->editLink  = get_edit_post_link( $link->linked_post_id, '' );

			$postTypeObject           = get_post_type_object( get_post_type( $link->linked_post_id ) );
			$link->context->postType  = aioseo()->helpers->getPostType( $postTypeObject );
		}

		return $links;
	}

	/**
	 * Returns affiliate links for the given post.
	 *
	 * @since 1.0.0
	 *
	 * @param  int         $postId The post ID.
	 * @param  int         $limit  The limit.
	 * @param  int         $offset The offset.
	 * @return array[Link]         The Links.
	 */
	public static function getAffiliateLinks( $postId, $limit = 20, $offset = 0 ) {
		$links = aioseo()->core->db->start( 'aioseo_links' )
			->select( '*' )
			->where( 'post_id', $postId )
			->where( 'affiliate', 1 )
			->limit( $limit, $offset )
			->run()
			->models( 'AIOSEO\\Plugin\\Addon\\LinkAssistant\\Models\\Link' );

		return $links;
	}

	/**
	 * Returns external links for the given post.
	 *
	 * @since 1.0.0
	 *
	 * @param  int         $postId The post ID.
	 * @param  int         $limit  The limit.
	 * @param  int         $offset The offset.
	 * @return array[Link]         The Links.
	 */
	public static function getExternalLinks( $postId, $limit = 20, $offset = 0 ) {
		$links = aioseo()->core->db->start( 'aioseo_links' )
			->select( '*' )
			->where( 'post_id', $postId )
			->where( 'external', 1 )
			->limit( $limit, $offset )
			->run()
			->models( 'AIOSEO\\Plugin\\Addon\\LinkAssistant\\Models\\Link' );

		return $links;
	}

	/**
	 * Returns the link totals for a given post.
	 *
	 * @since 1.1.0
	 *
	 * @param  int         $postId The post ID.
	 * @return object|null         The totals.
	 */
	public static function getLinkTotals( $postId ) {
		static $totalLinks = [];
		if ( isset( $totalLinks[ $postId ] ) ) {
			return $totalLinks[ $postId ];
		}

		$aioseoLinksTableName = aioseo()->core->db->prefix . 'aioseo_links';
		$postsTableName       = aioseo()->core->db->prefix . 'posts';

		$totals = aioseo()->core->db->execute(
			"SELECT count(IF(external = 1, 1, NULL)) as external,
				count(IF(internal = 1, 1, NULL)) as outboundInternal,
				count(IF(affiliate = 1, 1, NULL)) as affiliate,
				(
					SELECT count(*)
					FROM {$aioseoLinksTableName} as al1
					JOIN {$postsTableName} as p ON al1.post_id = p.ID
					WHERE al1.linked_post_id = {$postId}
				) as inboundInternal
			FROM {$aioseoLinksTableName}
			WHERE post_id = {$postId}",
			true
		)->result();

		$totalLinks[ $postId ] = ! empty( $totals[0] ) ? $totals[0] : null;

		return $totalLinks[ $postId ];
	}

	/**
	 * Deletes all Links for the given post.
	 *
	 * @since 1.0.0
	 *
	 * @param  int  $postId The Post ID.
	 * @return void
	 */
	public static function deleteLinks( $postId ) {
		aioseo()->core->db->delete( 'aioseo_links' )
			->where( 'post_id', $postId )
			->run();
	}

	/**
	 * Deletes the Link with the given ID.
	 *
	 * @since 1.0.0
	 *
	 * @param  int  $linkId The Link ID.
	 * @return void
	 */
	public static function deleteLinkById( $linkId ) {
		aioseo()->core->db->delete( 'aioseo_links' )
			->where( 'id', $linkId )
			->run();
	}

	/**
	 * Sanitizes the link object.
	 *
	 * @since 1.0.0
	 *
	 * @param  array $link The link data.
	 * @return array       The sanitized link data.
	 */
	public static function sanitizeLink( $link ) {
		$nullFields    = [ 'linked_post_id' ];
		$booleanFields = [
			'internal',
			'affiliate',
			'external'
		];

		$sanitizedLink = [];
		foreach ( $link as $k => $v ) {
			switch ( $k ) {
				case 'post_id':
				case 'linked_post_id':
					if ( null === $v && in_array( $k, $nullFields, true ) ) {
						break;
					}
					$v = intval( $v );
					break;
				case 'internal':
				case 'external':
				case 'affiliate':
					$v = rest_sanitize_boolean( $v );
					break;
				case 'url':
					$v = esc_url( $v );
					break;
				case 'hostname':
				case 'anchor':
				case 'phrase':
				case 'paragraph':
					$v = sanitize_text_field( $v );
					break;
				case 'phrase_html':
				case 'paragraph_html':
					$v = aioseoLinkAssistant()->helpers->wpKsesPhrase( $v );
					break;
				default:
					break;
			}

			if (
				empty( $v ) &&
				! in_array( $k, $booleanFields, true ) &&
				! in_array( $k, $nullFields, true )
			) {
				return [];
			}

			$sanitizedLink[ $k ] = esc_sql( $v );
		}

		return $sanitizedLink;
	}

	/**
	 * Checks whether the given link object is a valid one in the context of Link Assistant.
	 * There are a number of reasons why a link might be invalid, such as empty props or because the link is wrapped around HTML elements like an image but actually doesn't contain text.
	 *
	 * @since 1.0.0
	 *
	 * @param  array $link The link data.
	 * @return bool        Whether the link is valid or not.
	 */
	public static function validateLink( $link ) {
		$propsToCheck = [
			'url',
			'hostname',
			'anchor',
			'phrase',
			'phrase_html',
			'paragraph',
			'paragraph_html'
		];

		foreach ( $propsToCheck as $prop ) {
			$value = wp_strip_all_tags( $link[ $prop ] );
			if ( empty( $value ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Returns posts with their links for the Links Report.
	 *
	 * @since 1.0.0
	 *
	 * @param  int    $limit             The limit.
	 * @param  int    $offset            The offset.
	 * @param  string $whereClause       An optional WHERE clause for search queries.
	 * @param  string $filter            An optional filter for the results.
	 * @param  array  $additionalFilters Additional filters to use when querying the data.
	 * @return array                     The posts with their links.
	 */
	public static function getPosts( $limit = 20, $offset = 0, $whereClause = '', $filter = '', $additionalFilters = [] ) {
		$query = self::getPostsBaseQuery( $filter, $additionalFilters )
			->select( 'p.ID, p.post_title, p.post_date, p.post_status' )
			->orderBy( 'p.post_date DESC' )
			->limit( $limit, $offset );

		if ( ! empty( $whereClause ) ) {
			$query->whereRaw( $whereClause );
		}

		$posts = $query->run()
			->result();

		if ( empty( $posts ) ) {
			return [];
		}

		foreach ( $posts as $post ) {
			$post->links = aioseoLinkAssistant()->helpers->getPostLinks( $post->ID, 5, 0 );

			$postStatusObject = get_post_status_object( $post->post_status );
			$postTypeObject   = get_post_type_object( get_post_type( $post->ID ) );

			$post->context             = new \stdClass();
			$post->context->postTitle  = aioseo()->helpers->getPostTitle( $post->ID );
			$post->context->permalink  = get_permalink( $post->ID );
			$post->context->editLink   = get_edit_post_link( $post->ID, '' );
			$post->context->postStatus = $postStatusObject;
			$post->context->postType   = aioseo()->helpers->getPostType( $postTypeObject );
		}

		return $posts;
	}

	/**
	 * Returns the total amount of posts for the Links Report.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $whereClause       An optional WHERE clause for search queries.
	 * @param  string $filter            An optional filter for the results.
	 * @param  array  $additionalFilters An optional array of additional filters.
	 * @return int                       The total amount of posts.
	 */
	public static function getTotalPosts( $whereClause = '', $filter = '', $additionalFilters = [] ) {
		$includedPostTypes    = aioseoLinkAssistant()->helpers->getIncludedPostTypes();
		$includedPostStatuses = aioseoLinkAssistant()->helpers->getIncludedPostStatuses();
		if ( empty( $includedPostTypes ) || empty( $includedPostStatuses ) ) {
			return 0;
		}

		static $totalPosts = [];

		$cacheKey = md5( $whereClause . $filter . implode( ',', $additionalFilters ) );
		if ( isset( $totalPosts[ $cacheKey ] ) ) {
			return $totalPosts[ $cacheKey ];
		}

		$query = self::getPostsBaseQuery( $filter, $additionalFilters );
		if ( ! empty( $whereClause ) ) {
			$query->whereRaw( $whereClause );
		}

		$totalPosts[ $cacheKey ] = $query->count();

		return $totalPosts[ $cacheKey ];
	}

	/**
	 * Returns the base query for the Links Report posts.
	 *
	 * @since 1.0.0
	 *
	 * @param  string                               $filter            An optional filter for the results.
	 * @param  array                                $additionalFilters An optional array of filters to use.
	 * @return \AIOSEO\Plugin\Common\Utils\Database                    The query object.
	 */
	private static function getPostsBaseQuery( $filter, $additionalFilters = [] ) {
		aioseoLinkAssistant()->helpers->maybeCreateTempTables();

		$query = aioseo()->core->db->start( 'aioseotemp_la_included_posts as p' )
			->join( 'aioseo_posts as ap', 'p.ID = ap.post_id' )
			->whereRaw( 'ap.link_scan_date IS NOT NULL' );

		$excludedPostIds = aioseoLinkAssistant()->helpers->getExcludedPostIds();
		if ( ! empty( $excludedPostIds ) ) {
			$query->whereNotIn( 'p.ID', $excludedPostIds );
		}

		if ( ! empty( $filter ) ) {
			$prefix = aioseo()->core->db->prefix;
			switch ( $filter ) {
				case 'linking-opportunities':
					$linkSuggestionsTableName = $prefix . 'aioseo_links_suggestions';
					$postsTableName           = $prefix . 'posts';

					$query->whereRaw(
						"(
							p.ID IN (
								SELECT als.post_id
								FROM $linkSuggestionsTableName as als
								JOIN $postsTableName as p2 ON als.linked_post_id = p2.ID
								WHERE als.dismissed = 0 AND p2.post_status = 'publish'
								GROUP BY als.post_id
							) OR p.ID IN (
								SELECT als2.linked_post_id
								FROM $linkSuggestionsTableName as als2
								JOIN $postsTableName as p3 ON als2.linked_post_id = p3.ID
								WHERE als2.dismissed = 0 AND p3.post_status = 'publish'
								GROUP BY als2.linked_post_id
							)
						)");
					break;
				case 'orphaned-posts':
					$linksTableName = $prefix . 'aioseo_links';

					$query->whereRaw( "p.ID IN (
						SELECT al.linked_post_id
						FROM $linksTableName as al
						WHERE al.internal = 1
						GROUP BY al.linked_post_id
					)" );
					break;
				default:
					break;
			}
		}

		if ( ! empty( $additionalFilters ) ) {
			if ( ! empty( $additionalFilters['post-type'] ) ) {
				$postTypes = aioseoLinkAssistant()->helpers->getIncludedPostTypes();
				if ( in_array( $additionalFilters['post-type'], $postTypes, true ) ) {
					$query->where( 'p.post_type', $additionalFilters['post-type'] );
				}
			}

			if ( ! empty( $additionalFilters['term'] ) ) {
				if ( 'all' === $additionalFilters['term'] ) {
					$taxonomy = aioseoLinkAssistant()->helpers->getFirstTaxonomy( $filter );
					if ( ! empty( $taxonomy ) ) {
						$query->join( 'term_relationships as tr', 'p.ID = tr.object_id' )
							->join( 'term_taxonomy as tt', 'tr.term_taxonomy_id = tt.term_id' )
							->where( 'tt.taxonomy', $taxonomy->name );
					}
				} else {
					$query->join( 'term_relationships as tr', 'p.ID = tr.object_id' )
						->where( 'tr.term_taxonomy_id', $additionalFilters['term'] );
				}
			}
		}

		return $query;
	}

	/**
	 * Returns the total amount of internal posts that have been linked to on the site.
	 *
	 * @since 1.0.0
	 *
	 * @return int The amount of internal links on the site.
	 */
	public static function getSiteTotalLinkedPosts() {
		aioseoLinkAssistant()->helpers->maybeCreateTempTables();

		$whereClause     = '';
		$excludedPostIds = aioseoLinkAssistant()->helpers->getExcludedPostIds();
		if ( ! empty( $excludedPostIds ) ) {
			$implodedPostIds = aioseo()->helpers->implodeWhereIn( $excludedPostIds );
			$whereClause     = " AND p.ID NOT IN ( {$implodedPostIds} )";
		}

		$tempTableName    = aioseo()->core->db->prefix . 'aioseotemp_la_included_posts';
		$aioseoLinksTable = aioseo()->core->db->prefix . 'aioseo_links';

		$count = aioseo()->core->db->execute(
			"SELECT count(*) as totalLinkedPosts
			FROM (
				SELECT al.linked_post_id
				FROM {$aioseoLinksTable} as al
				JOIN {$tempTableName} as p ON al.linked_post_id = p.ID
				WHERE al.internal = 1
					AND al.linked_post_id != 0
					{$whereClause}
				GROUP BY al.linked_post_id
			) as x",
			true
		)->result();

		return ! empty( $count[0]->totalLinkedPosts ) ? (int) $count[0]->totalLinkedPosts : 0;
	}

	/**
	 * Returns links grouped per domain/hostname for the Domains Report.
	 *
	 * @since 1.0.0
	 *
	 * @param  int    $limit       The limit.
	 * @param  int    $offset      The offset.
	 * @param  string $whereClause An optional WHERE clause for search queries.
	 * @return array               The domains.
	 */
	public static function getDomains( $limit = 20, $offset = 0, $whereClause = '' ) {
		aioseoLinkAssistant()->helpers->maybeCreateTempTables();

		// First, we get a list of hostnames with external links.
		$query = aioseo()->core->db->start( 'aioseo_links as al' )
			->select( 'al.hostname as hostname, count( al.hostname ) as count' )
			->join( 'aioseotemp_la_included_posts as p', 'al.post_id = p.ID' )
			->where( 'al.external', 1 )
			->groupBy( 'hostname' )
			->orderBy( 'count DESC' )
			->orderBy( 'hostname ASC' )
			->limit( $limit, $offset );

		if ( $whereClause ) {
			$query->whereRaw( $whereClause );
		}

		$hostnames = $query->run()
			->result();

		$hostnames = array_map( function( $hostname ) {
			return $hostname->hostname;
		}, $hostnames );

		// Then, we get posts with links to the relevant hostname for each of the hostnames.
		$domainsWithPosts = [];
		foreach ( $hostnames as $hostname ) {
			$domainsWithPosts = array_merge( $domainsWithPosts, [
				$hostname => self::getDomainPostLinks( $hostname )
			] );
		}

		// The WpTable component requires an array so we can't have keys on the first level and need the object into an array.
		$usedDomains    = [];
		$domainsAsArray = [];
		foreach ( $domainsWithPosts as $domain => $posts ) {
			if ( ! in_array( $domain, $usedDomains, true ) ) {
				$usedDomains[] = $domain;
			}

			$index                              = array_search( $domain, $usedDomains, true );
			$domainsAsArray[ $index ][ $domain ] = $posts;
		}

		// Finally, we need to re-sort the domains by the amount links they have.
		$sortedDomains = [];
		foreach ( $domainsAsArray as $wrapper ) {
			foreach ( $wrapper as $hostname => $posts ) {
				foreach ( $hostnames as $index => $hostname2 ) {
					if ( $hostname === $hostname2 ) {
						$sortedDomains[ $index ] = $wrapper;
					}
				}
			}
		}
		ksort( $sortedDomains );

		return $sortedDomains;
	}

	/**
	 * Returns the total amount of domains with links.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $whereClause An optional WHERE clause for search queries.
	 * @return int                 The total amount of domains.
	 */
	public static function getTotalDomains( $whereClause = '' ) {
		aioseoLinkAssistant()->helpers->maybeCreateTempTables();

		$tempTableName        = aioseo()->core->db->prefix . 'aioseotemp_la_included_posts';
		$aioseoLinksTableName = aioseo()->core->db->prefix . 'aioseo_links';
		$whereClause          = $whereClause ? ' AND ' . $whereClause : '';

		$count = aioseo()->core->db->execute(
			"SELECT count(*) as totalDomains
			FROM (
				SELECT hostname
				FROM {$aioseoLinksTableName} as al
				JOIN {$tempTableName} as p ON al.post_id = p.ID
				WHERE al.external = 1
					{$whereClause}
				GROUP BY hostname
			) as x",
			true
		)->result();

		return ! empty( $count[0]->totalDomains ) ? (int) $count[0]->totalDomains : 0;
	}

	/**
	 * Returns posts that have links to the given hostname.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $hostname The hostname.
	 * @param  int    $limit    The limit.
	 * @param  int    $offset   The offset.
	 * @return array            The posts with their links.
	 */
	public static function getDomainPostLinks( $hostname, $limit = 5, $offset = 0 ) {
		aioseoLinkAssistant()->helpers->maybeCreateTempTables();

		// First, figure out which posts link to the current hostname.
		// We can't use the result of this as a subquery here because MySQL doesn't support LIMIT clauses in subqueries yet.
		$postIds = aioseo()->core->db->start( 'aioseo_links as al' )
			->select( 'al.post_id' )
			->join( 'aioseotemp_la_included_posts as p', 'al.post_id = p.ID' )
			->where( 'al.hostname', $hostname )
			->where( 'al.external', 1 )
			->groupBy( 'al.post_id' )
			->limit( $limit, $offset )
			->run()
			->result();

		$postIds = array_map( function( $postId ) {
			return $postId->post_id;
		}, $postIds );

		// Then, get all links for those posts.
		$links = aioseo()->core->db->start( 'aioseo_links' )
			->where( 'hostname', $hostname )
			->where( 'external', 1 )
			->whereIn( 'post_id', $postIds )
			->run()
			->result();

		$posts = [];
		foreach ( $links as $link ) {
			$posts[ $link->post_id ]['links'][] = $link;
		}

		// Now, we just need to add the context.
		$index            = 0;
		$postsWithContext = [];
		foreach ( $posts as $postId => $post ) {
			$postObject      = aioseo()->helpers->getPost( $postId );
			$postTypeObject  = get_post_type_object( get_post_type( $postId ) );
			$post['context'] = [
				'postTitle'   => aioseo()->helpers->decodeHtmlEntities( $postObject->post_title ),
				'publishDate' => $postObject->post_date,
				'permalink'   => get_permalink( $postId ),
				'editLink'    => get_edit_post_link( $postId, '' ),
				'postType'    => aioseo()->helpers->getPostType( $postTypeObject )
			];

			// TODO: Look into improving the structure of this data because this just sucks.
			if ( 0 === $index ) {
				$totalPosts = self::getTotalDomainPosts( $hostname );
				$totalLinks = self::getTotalDomainLinks( $hostname );

				$post['totals'] = [
					'page'       => ( $offset + $limit ) / $limit,
					'pages'      => ceil( $totalPosts / $limit ),
					'total'      => $totalPosts,
					'totalLinks' => $totalLinks
				];
			}

			$postsWithContext[] = $post;
			$index++;
		}

		return $postsWithContext;
	}

	/**
	 * Returns the total amount of posts with external links refering to the given hostname/domain.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $hostname The hostname.
	 * @return int              The total amount of posts.
	 */
	public static function getTotalDomainPosts( $hostname ) {
		aioseoLinkAssistant()->helpers->maybeCreateTempTables();

		$tempTableName        = aioseo()->core->db->prefix . 'aioseotemp_la_included_posts';
		$aioseoLinksTableName = aioseo()->core->db->prefix . 'aioseo_links';

		$count = aioseo()->core->db->execute(
			"SELECT count(*) as totalPosts
			FROM (
				SELECT al.post_id
				FROM {$aioseoLinksTableName} as al
				JOIN {$tempTableName} as p ON al.post_id = p.ID
				WHERE al.external = 1
					AND al.hostname = '{$hostname}'
				GROUP BY al.post_id
			) as x",
			true
		)->result();

		return ! empty( $count[0]->totalPosts ) ? (int) $count[0]->totalPosts : 0;
	}

	/**
	 * Returns all external links referring to the given hostname/domain.
	 *
	 * @since 1.0.0
	 *
	 * @param  string      $hostname The hostname.
	 * @return array[Link]           The Links.
	 */
	public static function getDomainLinks( $hostname ) {
		aioseoLinkAssistant()->helpers->maybeCreateTempTables();

		return aioseo()->core->db->start( 'aioseo_links al' )
			->join( 'aioseotemp_la_included_posts as p', 'al.post_id = p.ID' )
			->where( 'al.hostname', $hostname )
			->where( 'al.external', 1 )
			->run()
			->models( 'AIOSEO\\Plugin\\Addon\\LinkAssistant\\Models\\Link' );
	}

	/**
	 * Returns the total amount of external links referring to the given hostname/domain.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $hostname The hostname.
	 * @return int              The total amount of links.
	 */
	public static function getTotalDomainLinks( $hostname ) {
		aioseoLinkAssistant()->helpers->maybeCreateTempTables();

		return aioseo()->core->db->start( 'aioseo_links as al' )
			->join( 'aioseotemp_la_included_posts as p', 'al.post_id = p.ID' )
			->where( 'al.hostname', $hostname )
			->where( 'al.external', 1 )
			->count();
	}
}