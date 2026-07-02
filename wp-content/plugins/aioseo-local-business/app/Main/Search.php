<?php
namespace AIOSEO\Plugin\Addon\LocalBusiness\Main;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Models;

/**
 * The Search class..
 *
 * @since 1.1.0
 */
class Search {
	/**
	 * The JSON searchable fields.
	 *
	 * @since 1.1.0
	 *
	 * @var string An array of searchable fields.
	 */
	private $searchFields = [
		'streetLine1',
		'city',
		'zipCode'
	];

	/**
	 * Whether to force an enhanced query.
	 *
	 * @since 1.1.0
	 *
	 * @var boolean
	 */
	private $forceEnhance = false;

	/**
	 * Holds the errors if there are any.
	 *
	 * @since 1.1.0
	 *
	 * @var bool|string
	 */
	private $errorDetected = false;

	/**
	 * Class constructor.
	 *
	 * @since 1.1.0
	 */
	public function __construct() {
		$this->hooks();
	}

	/**
	 * Hook where we need to enhance the search query.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'pre_get_posts', [ $this, 'maybeEnhanceSearch' ], 50 );
		add_filter( 'the_excerpt', [ $this, 'maybeEnhanceSearchResults' ], 50 );
	}

	/**
	 * Filter the query join and search to include location results.
	 *
	 * @since 1.1.0
	 *
	 * @param  \WP_Query $query The main query.
	 * @return void
	 */
	public function maybeEnhanceSearch( $query ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		// phpcs:disable HM.Security.ValidatedSanitizedInput.InputNotSanitized, HM.Security.NonceVerification.Recommended
		$enhance = (
			is_search() &&
			! is_admin() &&
			$this->enhancedSearchActivated() &&
			(
				! isset( $_GET['post_type'] ) ||
				aioseoLocalBusiness()->postType->getName() === sanitize_text_field( wp_unslash( $_GET['post_type'] ) )
			)
		);
		// phpcs:enable

		if ( $this->forceEnhance || $enhance ) {
			remove_action( 'pre_get_posts', [ $this, 'maybeEnhanceSearch' ], 50 );

			add_filter( 'posts_join', [ $this, 'join' ], 50 );
			add_filter( 'posts_search', [ $this, 'search' ], 50, 2 );
			add_filter( 'posts_groupby', [ $this, 'groupBy' ] );
			add_filter( 'posts_results', [ $this, 'maybeDisableEnhancedSearch' ] );
		}
	}

	/**
	 * Left join the aioseo_posts table.
	 *
	 * @since 1.1.0
	 *
	 * @param  string $join The current $join string.
	 * @return string       Adds a left join for location info on table aioseo_posts.
	 */
	public function join( $join ) {
		remove_filter( 'posts_join', [ $this, 'join' ], 50 );

		$join .= ' LEFT JOIN ' . aioseo()->core->db->db->prefix . 'aioseo_posts aioseo_posts ON ( ' . aioseo()->core->db->db->posts . '.ID = aioseo_posts.post_id AND aioseo_posts.local_seo IS NOT NULL ) '; // phpcs:ignore Generic.Files.LineLength.MaxExceeded

		return $join;
	}

	/**
	 * Adds our search parameters.
	 *
	 * @since 1.1.0
	 *
	 * @param  string    $searchWhere The current search where string.
	 * @param  \WP_Query $query       The current WP_Query.
	 * @return string                 Adds to the search where string to account for locations.
	 */
	public function search( $searchWhere, $query = null ) {
		remove_filter( 'posts_search', [ $this, 'search' ], 50 );

		$searchTerms = $query->get( 'search_terms' );
		if ( empty( $searchTerms ) ) {
			return $searchWhere;
		}

		$localWhere    = ' ( ';
		$localWhereAnd = '';
		foreach ( $searchTerms as $item ) {
			$localWhere    .= $localWhereAnd . $this->getLocalWhere( $item );
			$localWhereAnd = ' AND ';
		}
		$localWhere .= ' ) ';

		$searchWhere = preg_replace( '/^\s*AND\s*\(/', " AND ( {$localWhere} OR ( ", (string) $searchWhere );
		$searchWhere .= ' ) ';

		return $searchWhere;
	}

	/**
	 * Group by ID.
	 *
	 * @since 1.1.0
	 *
	 * @param  string $groupBy The current groupBy string.
	 * @return string          Adds a group_by string for query consistency.
	 */
	public function groupBy( $groupBy ) {
		if ( empty( $groupBy ) ) {
			$groupBy = aioseo()->core->db->db->posts . '.ID ';
		}

		return $groupBy;
	}

	/**
	 * We'll proactively disable enhanced search if a SQL error was found.
	 *
	 * @since 1.1.0
	 *
	 * @param  array $posts The found search posts.
	 * @return array        The found search posts ( $posts ) is not modified.
	 */
	public function maybeDisableEnhancedSearch( $posts ) {
		$this->errorDetected = aioseo()->core->db->lastError();

		// Let's do nothing if the enhanced search is deactivated.
		if ( ! $this->enhancedSearchActivated() ) {
			return $posts;
		}

		// Let's clear the notification if the search is working again.
		$notification = Models\Notification::getNotificationByName( 'local-business-enhanced-search' );
		if ( '' === $this->errorDetected ) {
			$notification->delete();

			return $posts;
		}

		if ( ! $notification->exists() ) {
			// Let user know we've found an error.
			Models\Notification::addNotification( [
				'slug'              => uniqid(),
				'addon'             => 'localBusiness',
				'notification_name' => 'local-business-enhanced-search',
				'title'             => __( 'Local Business - Enhanced Search', 'aioseo-local-business' ),
				'content'           => sprintf(
				// Translators: 1 - Opening link tag, 2 - Closing link tag.
					__( 'Enhanced Search cannot be enabled on your website because there is a search query conflict. To learn more about this, %1$sclick here%2$s.', 'aioseo-local-business' ), // phpcs:ignore Generic.Files.LineLength.MaxExceeded
					'<a href="' . aioseo()->helpers->utmUrl( trailingslashit( AIOSEO_MARKETING_URL ) . 'docs/enhanced-search-query-conflict/', 'notifications-center', 'v3-migration-title-formats-blank' ) . '" target="_blank">', // phpcs:ignore Generic.Files.LineLength.MaxExceeded
					'</a>'
				),
				'type'              => 'error',
				'level'             => [ 'all' ],
				'button1_label'     => '',
				'button1_action'    => '',
				'start'             => gmdate( 'Y-m-d H:i:s' )
			] );
		}

		// Disable enhanced search if an error was found.
		aioseo()->options->localBusiness->locations->general->enhancedSearch = false;

		// Clear this cache so the test can run again.
		aioseoLocalBusiness()->cache->delete( 'enhancedSearchResult' );

		return $posts;
	}

	/**
	 * Return a full where string query.
	 *
	 * @since 1.1.0
	 *
	 * @param  string $term Search term separated on WP_Query.
	 * @return string       A where for a single search term for each searchField.
	 */
	private function getLocalWhere( $term ) {
		$exclusionPrefix = apply_filters( 'wp_query_search_exclusion_prefix', '-' );

		$exclude = $exclusionPrefix && ( substr( $term, 0, 1 ) === $exclusionPrefix );
		if ( $exclude ) {
			$likeOp = 'NOT REGEXP';
			$term   = substr( $term, 1 );
		} else {
			$likeOp = 'REGEXP';
		}

		$localWhere = ' ( ';
		$likeTerms  = [];
		foreach ( $this->searchFields as $field ) {
			$likeTerms[] = "\"$field\":\"[^\"]*{$term}";
		}

		$localWhere .= aioseo()->core->db->db->prepare( " aioseo_posts.local_seo {$likeOp} %s", implode( '|', $likeTerms ) );

		$localWhere .= ' ) ';

		return $localWhere;
	}

	/**
	 * Adds html output to the search results.
	 *
	 * @since 1.1.0
	 *
	 * @param  string $excerpt The current post excerpt.
	 * @return string          A excerpt enhanced with the location business info.
	 */
	public function maybeEnhanceSearchResults( $excerpt ) {
		if (
			is_search() &&
			! is_admin() &&
			aioseo()->options->localBusiness->locations->general->enhancedSearch &&
			aioseo()->options->localBusiness->locations->general->enhancedSearchExcerpt
		) {
			if ( get_post_type() === aioseoLocalBusiness()->postType->getName() ) {
				ob_start(); ?>
				<div class="aioseo-local-seo-details">
					<?php aioseoLocalBusiness()->locations->outputBusinessInfo( get_the_ID() ); ?>
				</div>
				<?php
				$excerpt = ob_get_clean();
			}
		}

		return $excerpt;
	}

	/**
	 * Test if our enhanced search does not throw any SQL errors.
	 *
	 * @since 1.1.0
	 *
	 * @return bool Search query succeeded.
	 */
	public function testSearch() {
		$testResult = aioseoLocalBusiness()->cache->get( 'enhancedSearchResult' );
		if ( null !== $testResult ) {
			return $testResult;
		}

		$this->forceEnhance = true;
		new \WP_Query( [
			's'              => 'test',
			'posts_per_page' => 1
		] );
		$this->forceEnhance = false;

		$testResult = ! $this->errorDetected;

		// We'll only cache the result if the query is working.
		// When it stops working we proactively disable enhancedSearch and clear this cache so the test can run until the query is fixed.
		if ( $testResult ) {
			aioseoLocalBusiness()->cache->update( 'enhancedSearchResult', true, 0 );
		}

		return $testResult;
	}

	/**
	 * Returns if multiple locations + enhanced search are both on.
	 *
	 * @since 1.2.9.1
	 *
	 * @return bool Is activated.
	 */
	private function enhancedSearchActivated() {
		return aioseo()->options->localBusiness->locations->general->multiple &&
				aioseo()->options->localBusiness->locations->general->enhancedSearch;
	}
}