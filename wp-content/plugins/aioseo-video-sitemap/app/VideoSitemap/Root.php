<?php
namespace AIOSEO\Plugin\Addon\VideoSitemap\VideoSitemap;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Determines which indexes should appear in the sitemap root index.
 *
 * @since 1.0.0
 */
class Root {
	/**
	 * Returns the indexes for the sitemap root index.
	 *
	 * @since 1.0.0
	 *
	 * @return array The indexes.
	 */
	public function indexes() {
		if ( 'video' !== aioseo()->sitemap->type ) {
			return [];
		}

		$indexes   = [];
		$postTypes = aioseo()->sitemap->helpers->includedPostTypes();
		if ( $postTypes ) {
			foreach ( $postTypes as $postType ) {
				$indexes = array_merge( $indexes, $this->buildIndexesPostType( $postType ) );
			}
		}

		$taxonomies = aioseo()->sitemap->helpers->includedTaxonomies();
		if ( $taxonomies ) {
			foreach ( $taxonomies as $taxonomy ) {
				$indexes = array_merge( $indexes, $this->buildIndexesTaxonomy( $taxonomy ) );
			}
		}

		return $indexes;
	}

	/**
	 * Builds indexes for all eligible posts of a given post type.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $postType The post type.
	 * @return array            The indexes.
	 */
	public function buildIndexesPostType( $postType ) {
		if ( 'video' !== aioseo()->sitemap->type ) {
			return [];
		}

		$posts = aioseoVideoSitemap()->content->videoPosts( $postType, [ 'root' => true ] );
		if ( ! $posts ) {
			return [];
		}

		return aioseo()->sitemap->root->buildIndexes( $postType, $posts );
	}

	/**
	 * Builds indexes for all eligible terms of a given taxonomy.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $taxonomy The taxonomy.
	 * @return array            The indexes.
	 */
	public function buildIndexesTaxonomy( $taxonomy ) {
		if ( 'video' !== aioseo()->sitemap->type ) {
			return [];
		}

		$terms = aioseoVideoSitemap()->content->videoTerms( $taxonomy, [ 'root' => true ] );
		if ( ! $terms ) {
			return [];
		}

		return aioseo()->sitemap->root->buildIndexes( $taxonomy, $terms );
	}
}