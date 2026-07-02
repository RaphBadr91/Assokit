<?php
namespace AIOSEO\Plugin\Pro\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the cornerstone content posts filter.
 *
 * @since 4.4.8
 */
class CornerstoneContent {
	/**
	 * The name of the query argument that is used to activate this filter.
	 *
	 * @since 4.4.8
	 *
	 * @var string
	 */
	private $queryArg = 'aioseo-cornerstone-content';

	/**
	 * Class constructor.
	 *
	 * @since 4.4.8
	 */
	public function __construct() {
		if ( ! aioseo()->license->hasCoreFeature( 'general', 'cornerstone-content' ) ) {
			return;
		}

		add_action( 'admin_init', [ $this, 'addTableFilterLink' ], 11 );
		add_filter( 'posts_where', [ $this, 'filterPosts' ] );
	}

	/**
	 * Adds the cornerstone content filter link to the posts table.
	 *
	 * @since 4.4.8
	 *
	 * @return void
	 */
	public function addTableFilterLink() {
		global $pagenow;

		// Only add the filter link on the posts list page.
		if ( 'edit.php' !== $pagenow ) {
			return;
		}

		$pageBuilderActive       = false;
		$pageBuilderIntegrations = aioseo()->standalone->pageBuilderIntegrations;
		foreach ( $pageBuilderIntegrations as $pageBuilderIntegration ) {
			if ( $pageBuilderIntegration->isActive() ) {
				$pageBuilderActive = true;
				break;
			}
		}

		// If a page builder is active, make sure at least a single post is flagged as cornerstone content.
		if ( $pageBuilderActive ) {
			$totalPosts = aioseo()->core->db->start( 'aioseo_posts as ap' )
				->join( 'posts as p', 'p.ID = ap.post_id' )
				->where( 'ap.pillar_content', 1 )
				->count();

			if ( 0 === $totalPosts ) {
				return;
			}
		}

		foreach ( aioseo()->helpers->getPublicPostTypes( true ) as $postType ) {
			$enabled = true;

			$dynamicOptions = aioseo()->dynamicOptions->noConflict();
			if (
				$dynamicOptions->searchAppearance->postTypes->has( $postType, false ) &&
				$dynamicOptions->{$postType}->has( 'advanced', false ) &&
				! $dynamicOptions->advanced->showMetaBox
			) {
				$enabled = false;
			}

			if ( ! apply_filters( 'aioseo_cornerstone_content_filter_enable', $enabled, $postType ) ) {
				continue;
			}

			add_filter( 'views_edit-' . $postType, [ $this, 'addFilterLink' ] );
		}
	}

	/**
	 * Adds the cornerstone content filter link to the posts table.
	 *
	 * @since 4.4.8
	 *
	 * @param  array  $filters The filters for the posts table.
	 * @return array           The modified filters.
	 */
	public function addFilterLink( $filters ) {
		if ( 'web-story' === $this->getCurrentPostType() ) {
			return $filters;
		}

		$postStatuses = aioseo()->helpers->getPublicPostStatuses( true );
		unset( $postStatuses['trash'] );

		$totalPosts = aioseo()->core->db->start( 'aioseo_posts as ap' )
			->join( 'posts as p', 'p.ID = ap.post_id' )
			->where( 'ap.pillar_content', 1 )
			->where( 'p.post_type', $this->getCurrentPostType() )
			->whereIn( 'p.post_status', $postStatuses )
			->count();

		$filters['aioseo_cornerstone_content'] = sprintf(
			'<a href="%1$s"%2$s>%3$s</a> (%4$s)',
			esc_url( $this->getUrl() ),
			( $this->isActive() ) ? ' class="current" aria-current="page"' : '',
			__( 'Cornerstone Content', 'aioseo-pro' ),
			$totalPosts
		);

		return $filters;
	}

	/**
	 * Returns the post type that is currently being viewed on the edit.php page.
	 *
	 * @since 4.4.8
	 *
	 * @return string The post type.
	 */
	private function getCurrentPostType() {
		if ( empty( $_GET['post_type'] ) ) { // phpcs:ignore HM.Security.NonceVerification.Recommended
			return 'post';
		}

		$postType = sanitize_text_field( wp_unslash( $_GET['post_type'] ) ); // phpcs:ignore HM.Security.NonceVerification.Recommended

		return ! empty( $postType ) ? $postType : 'post';
	}

	/**
	 * Returns the URL for the filter with the relevant query args.
	 *
	 * @since 4.4.8
	 *
	 * @return string The URL.
	 */
	protected function getUrl() {
		$queryArgs = [
			$this->queryArg => 1,
			'post_type'     => $this->getCurrentPostType()
		];

		return add_query_arg( $queryArgs, 'edit.php' );
	}

	/**
	 * Checks whether the cornerstone content filter is active.
	 *
	 * @since 4.4.8
	 *
	 * @return bool Whether the filter is active.
	 */
	protected function isActive() {
		return isset( $_GET[ $this->queryArg ] ); // phpcs:ignore HM.Security.NonceVerification.Recommended
	}

	/**
	 * Filters the posts based on whether the cornerstone content filter is active.
	 *
	 * @since 4.4.8
	 *
	 * @param  string $where The WHERE clause.
	 * @return string        The modified WHERE clause.
	 */
	public function filterPosts( $where ) {
		if ( ! $this->isActive() ) {
			return $where;
		}

		$postStatuses = aioseo()->helpers->getPublicPostStatuses( true );
		unset( $postStatuses['trash'] );

		$implodedPostStatuses = aioseo()->helpers->implodeWhereIn( $postStatuses, true );

		$postsTableName       = aioseo()->core->db->prefix . 'posts';
		$aioseoPostsTableName = aioseo()->core->db->prefix . 'aioseo_posts';

		$where .= " AND $postsTableName.ID IN ( SELECT post_id FROM $aioseoPostsTableName WHERE pillar_content = 1 )";
		$where .= " AND post_status IN ( $implodedPostStatuses )";

		return $where;
	}
}