<?php
namespace AIOSEO\Plugin\Addon\LinkAssistant\Utils;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Addon\LinkAssistant\Models;
use AIOSEO\Plugin\Addon\LinkAssistant\Traits;

/**
 * Contains helper functions.
 *
 * @since 1.0.0
 */
class Helpers {
	use Traits\Overview;
	use Traits\LinksReport;
	use Traits\DomainsReport;
	use Traits\PostSettings;
	use Traits\Debug;

	/**
	 * Gets the data for Vue.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $page The current page.
	 * @return array        The data.
	 */
	public function getVueData( $data = [], $page = null ) {
		if ( 'link-assistant' === $page ) {
			static $menuData = null;
			if ( null === $menuData ) {
				$menuData = $this->getMenuData( $data );
			}

			return $menuData;
		}

		if ( 'post' === $page ) {
			static $postData = null;
			if ( null === $postData ) {
				$postData = $this->getPostData( $data );
			}

			return $postData;
		}

		return $data;
	}

	/**
	 * Adds the data for the settings menu.
	 *
	 * @since 1.0.0
	 *
	 * @param  array $data The data.
	 * @return array       The modified data.
	 */
	public function getMenuData( $data = [] ) {
		$this->maybeCreateTempTables();

		$data['linkAssistant'] = [
			'options'         => aioseoLinkAssistant()->options->all(),
			'internalOptions' => aioseoLinkAssistant()->internalOptions->all(),
			'overview'        => $this->getOverviewData(),
			'linksReport'     => $this->getLinksReportData( aioseo()->settings->tablePagination['linkAssistantLinksReport'] ),
			'domainsReport'   => $this->getDomainsReportData( aioseo()->settings->tablePagination['linkAssistantDomainsReport'] ),
			'suggestionsScan' => [
				'percent' => aioseoLinkAssistant()->helpers->getSuggestionsScanPercent()
			]
		];

		return $data;
	}

	/**
	 * Returns the links/suggestions and their totals for the given post.
	 *
	 * @since 1.0.0
	 *
	 * @param  int    $postId The post ID.
	 * @param  int    $limit  The limit.
	 * @param  int    $offset The offset.
	 * @param  string $type   The type of link/suggestion.
	 * @return array          The data.
	 */
	public function getPostLinks( $postId, $limit = 20, $offset = 0, $type = '' ) {
		$this->maybeCreateTempTables();

		if ( $type ) {
			return $this->getPostLinksHelper( $postId, $type, $limit, $offset );
		}

		$types = [
			'inboundInternal',
			'outboundInternal',
			'affiliate',
			'external',
			'suggestionsInbound',
			'suggestionsOutbound'
		];

		$data = [];
		foreach ( $types as $type ) {
			$data[ $type ] = $this->getPostLinksHelper( $postId, $type, $limit, $offset );
		}

		return $data;
	}

	/**
	 * Returns links/suggestions and their totals of the given type for the given post.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $type   The link/suggestion type.
	 * @param  int    $limit  The limit.
	 * @param  int    $offset The offset.
	 * @return array          The data.
	 */
	private function getPostLinksHelper( $postId, $type, $limit = 20, $offset = 0 ) {
		$newLimit   = null === $limit ? 10 : $limit;
		$totalLinks = Models\Link::getLinkTotals( $postId );

		switch ( $type ) {
			case 'inboundInternal':
				$total = ! empty( $totalLinks->inboundInternal ) ? (int) $totalLinks->inboundInternal : 0;

				return [
					'rows'   => array_values( Models\Link::getInboundInternalLinks( $postId, $limit, $offset ) ),
					'totals' => [
						'page'  => 0 === $offset ? 1 : ( $offset / $newLimit ) + 1,
						'pages' => ceil( $total / $newLimit ),
						'total' => $total
					]
				];
			case 'outboundInternal':
				$total = ! empty( $totalLinks->outboundInternal ) ? (int) $totalLinks->outboundInternal : 0;

				return [
					'rows'   => array_values( Models\Link::getOutboundInternalLinks( $postId, $limit, $offset ) ),
					'totals' => [
						'page'  => 0 === $offset ? 1 : ( $offset / $newLimit ) + 1,
						'pages' => ceil( $total / $newLimit ),
						'total' => $total
					]
				];
			case 'affiliate':
				$total = ! empty( $totalLinks->affiliate ) ? (int) $totalLinks->affiliate : 0;

				return [
					'rows'   => array_values( Models\Link::getAffiliateLinks( $postId, $limit, $offset ) ),
					'totals' => [
						'page'  => 0 === $offset ? 1 : ( $offset / $newLimit ) + 1,
						'pages' => ceil( $total / $newLimit ),
						'total' => $total
					]
				];
			case 'external':
				$total = ! empty( $totalLinks->external ) ? (int) $totalLinks->external : 0;

				return [
					'rows'   => array_values( Models\Link::getExternalLinks( $postId, $limit, $offset ) ),
					'totals' => [
						'page'  => 0 === $offset ? 1 : ( $offset / $newLimit ) + 1,
						'pages' => ceil( $total / $newLimit ),
						'total' => $total
					]
				];
			case 'suggestionsOutbound':
				$total = Models\Suggestion::getTotalOutboundSuggestions( $postId );

				return [
					'rows'   => array_values( Models\Suggestion::getOutboundSuggestions( $postId, $limit, $offset ) ),
					'totals' => [
						'page'  => 0 === $offset ? 1 : ( $offset / $newLimit ) + 1,
						'pages' => ceil( $total / $newLimit ),
						'total' => $total
					]
				];
			case 'suggestionsInbound':
				$total = Models\Suggestion::getTotalInboundSuggestions( $postId );

				return [
					'rows'   => array_values( Models\Suggestion::getInboundSuggestions( $postId, $limit, $offset ) ),
					'totals' => [
						'page'  => 0 === $offset ? 1 : ( $offset / $newLimit ) + 1,
						'pages' => ceil( $total / $newLimit ),
						'total' => $total
					]
				];
			default:
				return [];
		}
	}

	/**
	 * Checks if the given post is excluded from Link Assistant.
	 *
	 * @since 1.0.0
	 *
	 * @param  int  $postId The post ID.
	 * @return bool         Whether the post is excluded.
	 */
	public function isExcludedPost( $postId ) {
		$excludedPostIds      = $this->getExcludedPostIds();
		$includedPostTypes    = $this->getIncludedPostTypes();
		// We include auto-drafts here because all new posts are otherwise excluded before they are saved.
		$includedPostStatuses = array_merge( $this->getIncludedPostStatuses(), [ 'auto-draft' ] );
		$post                 = aioseo()->helpers->getPost( $postId );

		return in_array( (int) $postId, $excludedPostIds, true ) ||
			! in_array( $post->post_type, $includedPostTypes, true ) ||
			! in_array( $post->post_status, $includedPostStatuses, true );
	}

	/**
	 * Returns the IDs of posts that are excluded from Link Assistant.
	 *
	 * @since 1.0.0
	 *
	 * @return array The post IDs.
	 */
	public function getExcludedPostIds() {
		static $excludedPostIds = null;
		if ( null === $excludedPostIds ) {
			$excludedPostIds = [];
			$excludedPosts   = aioseoLinkAssistant()->options->main->excludePosts;
			foreach ( $excludedPosts as $excludedPost ) {
				$excludedPost = json_decode( $excludedPost );
				if ( ! empty( $excludedPost->value ) ) {
					$excludedPostIds[] = $excludedPost->value;
				}
			}
		}

		return $excludedPostIds;
	}

	/**
	 * Returns the post types that Link Assistant is enabled for.
	 *
	 * @since 1.0.0
	 *
	 * @return array The included post types.
	 */
	public function getIncludedPostTypes() {
		static $includedPostTypes = null;

		if ( null !== $includedPostTypes ) {
			return $includedPostTypes;
		}

		$includedPostTypes = [];
		$postTypes         = aioseoLinkAssistant()->options->main->postTypes->all();
		if ( ! empty( $postTypes['all'] ) ) {
			$includedPostTypes = $this->getScannablePostTypes();
		} else {
			// Determine the intersection to make sure that we only consider post types that are currently registered.
			$includedPostTypes = array_intersect(
				$postTypes['included'],
				$this->getScannablePostTypes()
			);
		}

		foreach ( $includedPostTypes as $k => $postType ) {
			if ( ! aioseo()->helpers->canEditPostType( $postType ) ) {
				unset( $includedPostTypes[ $k ] );
			}
		}

		$includedPostTypes = apply_filters( 'aioseo_link_assistant_post_types', $includedPostTypes );

		return $includedPostTypes;
	}

	/**
	 * Returns the post statuses that Link Assistant is enabled for.
	 *
	 * @since 1.0.0
	 *
	 * @return array The included post statuses.
	 */
	public function getIncludedPostStatuses() {
		static $includedPostStatuses = null;

		if ( null !== $includedPostStatuses ) {
			return $includedPostStatuses;
		}

		$includedPostStatuses = [];
		$postStatuses         = aioseoLinkAssistant()->options->main->postStatuses->all();
		if ( ! empty( $postStatuses['all'] ) ) {
			$includedPostStatuses = aioseo()->helpers->getPublicPostStatuses( true );
		} else {
			// Determine the intersection to make sure that we only consider post statuses that are currently registered.
			$includedPostStatuses = array_intersect(
				$postStatuses['included'],
				aioseo()->helpers->getPublicPostStatuses( true )
			);
		}

		$includedPostStatuses = apply_filters( 'aioseo_link_assistant_post_statuses', $includedPostStatuses );

		return $includedPostStatuses;
	}

	/**
	 * Deletes one or multiple links in the post content of a given post.
	 *
	 * @since 1.0.0
	 *
	 * @param  array|int $linkIds The Link IDs.
	 * @return void
	 */
	public function deleteLinksInPost( $linkIds ) {
		// NOTE: We can't save the post content until all links are deleted as it will trigger a re-scan on "save_post".
		if ( ! is_array( $linkIds ) ) {
			$linkIds = [ $linkIds ];
		}

		// We must reset the keys first since the WpTable component uses the model ID as the key for each record.
		$linkIds = array_values( $linkIds );
		if ( empty( $linkIds ) ) {
			return false;
		}

		$posts = [];
		foreach ( $linkIds as $linkId ) {
			$link = Models\Link::getLinkById( $linkId );
			if ( ! $link->exists() ) {
				continue;
			}

			if ( ! isset( $posts[ $link->post_id ] ) ) {
				$post = get_post( $link->post_id );

				// Replace encoded spaces with non-encoded ones first so that the paragraph can be found.
				$posts[ $link->post_id ] = preg_replace( '/&nbsp;/', ' ', (string) $post->post_content );
			}

			// Strip the HTML link tag from the anchor in the paragraph.
			$escapedAnchor        = aioseo()->helpers->escapeRegex( $link->anchor );
			$pattern              = "/(<a[^<>]*>)[\\r\\n\s]*($escapedAnchor)[\\r\\n\\s]*(<\/a[^<>]*>)/i";
			$paragraphWithoutLink = preg_replace( $pattern, '$2', (string) $link->paragraph_html );
			if ( preg_match( $pattern, (string) $paragraphWithoutLink ) ) {
				continue;
			}

			// Replace the paragraph in the post content with the new one. Do not use regex here to prevent "regular expression is too large" warning.
			$posts[ $link->post_id ] = str_ireplace( $link->paragraph_html, $paragraphWithoutLink, $posts[ $link->post_id ] );

			Models\Link::deleteLinkById( $linkId );
		}

		foreach ( $posts as $postId => $postContent ) {
			// Finally, we update the post with the modified post content.
			wp_update_post( [
				'ID'           => $postId,
				'post_content' => $postContent
			], true );
		}
	}

	/**
	 * Trims HTML paragraph from the start/end of the given string.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $string The string.
	 * @return string         The modified string.
	 */
	public function trimParagraphTags( $string ) {
		$string = preg_replace( '/^<p[^>]*>/', '', (string) $string );
		$string = preg_replace( '/<\/p>/', '', (string) $string );

		return trim( $string );
	}

	/**
	 * Get the percent completed for the suggestions scan.
	 *
	 * @since 1.0.0
	 *
	 * @return int The percent completed as an integer.
	 */
	public function getSuggestionsScanPercent() {
		// If this isn't set yet, the plugin has just been installed and the full site still needs to be scanned.
		// The option should be set on the next request.
		$minimumSuggestionScanDate = aioseoLinkAssistant()->internalOptions->internal->minimumSuggestionScanDate;
		if ( empty( $minimumSuggestionScanDate ) ) {
			return 0;
		}

		$aioseoPostsTableName = aioseo()->core->db->prefix . 'aioseo_posts';
		$postsTableName       = aioseo()->core->db->prefix . 'posts';
		$postTypes            = $this->getScannablePostTypes(); // Scan all post types so that results instantly show up when you include new ones.
		$postStatuses         = aioseo()->helpers->getPublicPostStatuses( true );
		$implodedPostTypes    = aioseo()->helpers->implodeWhereIn( $postTypes, true );
		$implodedPostStatuses = aioseo()->helpers->implodeWhereIn( $postStatuses, true );

		$totals = aioseo()->core->db->execute(
			"SELECT (
				SELECT count(*)
				FROM {$postsTableName}
				WHERE post_type IN ( $implodedPostTypes )
					AND post_status IN ( $implodedPostStatuses )
			) as totalPosts,
			(
				SELECT count(*)
				FROM {$postsTableName} as p
				LEFT JOIN {$aioseoPostsTableName} as ap ON ap.post_id = p.ID
				WHERE p.post_name != ''
					AND p.post_type IN ( $implodedPostTypes )
					AND p.post_status IN ( $implodedPostStatuses )
					AND ( ap.post_id IS NULL
						OR ap.link_suggestions_scan_date IS NULL
						OR ap.link_suggestions_scan_date < p.post_modified_gmt
						OR ap.link_suggestions_scan_date < '{$minimumSuggestionScanDate}'
					)
			) as scannedPosts
			FROM {$postsTableName}
			LIMIT 1",
			true
		)->result();

		if ( ! is_object( $totals[0] ) || 1 > $totals[0]->totalPosts ) {
			return 100;
		}

		return round( 100 - 100 * ( $totals[0]->scannedPosts / $totals[0]->totalPosts ) );
	}

	/**
	 * Returns the time that elapsed since the initial call to this function.
	 *
	 * @since 1.0.0
	 *
	 * @return int|null The time that has elapsed.
	 */
	public function timeElapsed() {
		static $last = null;

		$now    = microtime( true );
		$return = null !== $last ? $now - $last : null;

		if ( null === $last ) {
			$last = $now;
		}

		return $return;
	}

	/**
	 * Returns the total amount of posts on the site that are scannable.
	 * NOTE: This will always return a minimum of 1 to prevent division by 0 PHP errors.
	 *
	 * @since 1.0.0
	 *
	 * @return int The total amount of scannable posts.
	 */
	public function getTotalScannablePosts() {
		$totalPosts = aioseoLinkAssistant()->cache->get( 'suggestions_scan_total_posts' );
		if ( null === $totalPosts ) {
			$postTypes            = $this->getScannablePostTypes();
			$implodedPostTypes    = aioseo()->helpers->implodeWhereIn( $postTypes, true );
			$postStatuses         = aioseo()->helpers->getPublicPostStatuses( true );
			$implodedPostStatuses = aioseo()->helpers->implodeWhereIn( $postStatuses, true );

			$totalPosts = aioseo()->core->db->start( 'posts as p' )
				->whereRaw( "p.post_status IN ( $implodedPostStatuses )" )
				->whereRaw( "p.post_type IN ( $implodedPostTypes )" )
				->count();

			if ( empty( $totalPosts ) ) {
				$totalPosts = 1;
			}

			aioseoLinkAssistant()->cache->update( 'suggestions_scan_total_posts', $totalPosts );
		}

		return $totalPosts;
	}

	/**
	 * Applies wp_kses_post on the given string, but also allows some other tags we support.
	 *
	 * @since 1.0.1
	 *
	 * @param  string $string The string.
	 * @return string         The sanitized string.
	 */
	public function wpKsesPhrase( $string ) {
		$allowedHtmlTags = wp_kses_allowed_html( 'post' );

		$customTags = [
			'ta' => [
				'linkid' => [],
				'href'   => []
			]
		];

		$allowedHtmlTags = array_merge( $allowedHtmlTags, $customTags );

		return wp_kses( $string, $allowedHtmlTags );
	}

	/**
	 * Returns the scannable post types.
	 *
	 * @since 1.0.2
	 *
	 * @return array The scannable post types.
	 */
	public function getScannablePostTypes() {
		static $scannablePostTypes = null;
		if ( null !== $scannablePostTypes ) {
			return $scannablePostTypes;
		}

		// We exclude these post types to optimize performance.
		$nonSupportedPostTypes = [ 'attachment' ];
		$scannablePostTypes    = array_diff(
			aioseo()->helpers->getPublicPostTypes( true ),
			$nonSupportedPostTypes
		);

		return $scannablePostTypes;
	}

	/**
	 * Checks whether the current post can be scanned.
	 *
	 * @since 1.0.2
	 *
	 * @param  \WP_Post $post The post object.
	 * @return bool           Whether the post is scannable.
	 */
	public function isScannablePost( $post ) {
		static $postTypes    = null;
		static $postStatuses = null;

		if ( null === $postTypes ) {
			$postTypes    = array_diff( aioseo()->helpers->getPublicPostTypes( true ), [ 'attachment' ] );
			$postStatuses = aioseo()->helpers->getPublicPostStatuses( true );
		}

		if ( ! is_object( $post ) ) {
			return false;
		}

		if ( ! in_array( $post->post_type, $postTypes, true ) ) {
			return false;
		}

		if ( ! aioseo()->helpers->isValidPost( $post, $postStatuses ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Creates shared tables for common parts of Link Assistant related queries.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function maybeCreateTempTables() {
		static $created = false;
		if ( $created ) {
			return;
		}

		$tempTableName  = aioseo()->core->db->prefix . 'aioseotemp_la_included_posts';
		$postsTableName = aioseo()->core->db->prefix . 'posts';
		$postTypes      = aioseoLinkAssistant()->helpers->getIncludedPostTypes();
		$postStatuses   = aioseoLinkAssistant()->helpers->getIncludedPostStatuses();
		if ( empty( $postTypes ) || empty( $postStatuses ) ) {
			aioseo()->core->db->execute(
				"CREATE TEMPORARY TABLE IF NOT EXISTS {$tempTableName}
					( PRIMARY KEY(ID) )
				SELECT
					ID,
					post_name,
					post_title,
					post_type,
					post_status,
					COALESCE(post_date, '1970-01-01 00:00:00') AS post_date
				FROM {$postsTableName}"
			);

			$created = true;

			return;
		}

		$implodedPostTypes    = aioseo()->helpers->implodeWhereIn( $postTypes, true );
		$implodedPostStatuses = aioseo()->helpers->implodeWhereIn( $postStatuses, true );

		aioseo()->core->db->execute(
			"CREATE TEMPORARY TABLE IF NOT EXISTS {$tempTableName}
				( PRIMARY KEY(ID) )
			SELECT
				ID,
				post_name,
				post_title,
				post_type,
				post_status,
				COALESCE(post_date, '1970-01-01 00:00:00') AS post_date
			FROM {$postsTableName}
			WHERE
				post_type IN ( {$implodedPostTypes} )
				AND post_status IN ( {$implodedPostStatuses} );
			"
		);

		$created = true;
	}


	/**
	 * Apply filters to a links posts query.
	 *
	 * @since 1.1.8
	 *
	 * @param \AIOSEO\Plugin\Common\Utils\Database $query             The query object.
	 * @param  string                              $whereClause       An optional WHERE clause for search queries.
	 * @param  string                              $filter            An optional filter for the results.
	 * @param  array                               $additionalFilters Additional filters to use when querying the data.
	 * @return \AIOSEO\Plugin\Common\Utils\Database
	 */
	public function applyPostsFilters( $query, $whereClause = '', $filter = 'all', $additionalFilters = [] ) {
		if ( ! empty( $whereClause ) ) {
			$query->whereRaw( $whereClause );
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
							->join( 'term_taxonomy as tt', 'tr.term_taxonomy_id = tt.term_taxonomy_id' )
							->where( 'tt.taxonomy', $taxonomy->name );
					}
				} else {
					$query->join( 'term_relationships as tr', 'p.ID = tr.object_id' )
						->join( 'term_taxonomy as tt', 'tr.term_taxonomy_id = tt.term_taxonomy_id' )
						->where( 'tt.term_id', $additionalFilters['term'] );
				}
			}
		}

		return $query;
	}
}