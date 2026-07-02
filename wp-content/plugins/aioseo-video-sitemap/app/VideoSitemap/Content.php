<?php
namespace AIOSEO\Plugin\Addon\VideoSitemap\VideoSitemap;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Determines which content should be included in the sitemap.
 *
 * @since 1.0.0
 */
class Content {
	/**
	 * Returns the entries for the requested sitemap.
	 *
	 * @since 1.0.0
	 *
	 * @return array The sitemap entries.
	 */
	public function get() {
		if ( ! aioseo()->sitemap->content->isEnabled() ) {
			return [];
		}

		// Check if requested sitemap has a dedicated method.
		if ( ! method_exists( $this, aioseo()->sitemap->type ) ) {
			return [];
		}

		return $this->{aioseo()->sitemap->type}();
	}

	/**
	 * Returns the total entries number for the requested sitemap.
	 *
	 * @since 1.0.10
	 *
	 * @return int The total entries number.
	 */
	public function getTotal() {
		if ( ! aioseo()->sitemap->content->isEnabled() ) {
			return 0;
		}

		// Check if requested sitemap has a dedicated method.
		if ( method_exists( $this, aioseo()->sitemap->type ) ) {
			return $this->{aioseo()->sitemap->type}( true );
		}

		// Fallback if requested sitemap does not have a dedicated method.
		return 0;
	}

	/**
	 * Returns the video sitemap entries.
	 *
	 * @since 1.0.0
	 *
	 * @param  boolean $countOnly Whether should retrieve all results or just the total count.
	 * @return array|int          The sitemap entries or the count.
	 */
	private function video( $countOnly = false ) {
		if ( ! aioseo()->sitemap->indexes ) {
			$videoPosts = $this->videoPosts( aioseo()->sitemap->helpers->includedPostTypes(), [ 'count' => $countOnly ] );
			$videoTerms = $this->videoTerms( aioseo()->sitemap->helpers->includedTaxonomies(), [ 'count' => $countOnly ] );

			if ( $countOnly ) {
				return $videoPosts + $videoTerms;
			}

			return array_merge( $videoPosts, $videoTerms );
		}

		if ( 'root' === aioseo()->sitemap->indexName ) {
			$indexes = aioseo()->sitemap->root->indexes();

			if ( $countOnly ) {
				return count( $indexes );
			}

			return $indexes;
		}

		// Parse index name to determine which exact index is being requested.
		aioseo()->sitemap->indexName = preg_replace( '#-video#', '', (string) aioseo()->sitemap->indexName );

		if ( in_array( aioseo()->sitemap->indexName, aioseo()->sitemap->helpers->includedPostTypes(), true ) ) {
			return $this->videoPosts( aioseo()->sitemap->indexName, [ 'count' => $countOnly ] );
		}
		if ( in_array( aioseo()->sitemap->indexName, aioseo()->sitemap->helpers->includedTaxonomies(), true ) ) {
			return $this->videoTerms( aioseo()->sitemap->indexName, [ 'count' => $countOnly ] );
		}

		return [];
	}

	/**
	 * Returns the video sitemap entries for a given post type.
	 *
	 * @since 1.0.0
	 *
	 * @param  string    $postType       The name of the post type.
	 * @param  array     $additionalArgs Any additional arguments for the post query.
	 * @return array|int                 The sitemap entries or the post count.
	 */
	public function videoPosts( $postType, $additionalArgs = [] ) {
		$posts = aioseoVideoSitemap()->query->videoPosts( $postType, $additionalArgs );

		if ( ! empty( $additionalArgs['count'] ) && $additionalArgs['count'] ) {
			return $posts;
		}

		if ( ! $posts ) {
			return [];
		}

		// Don't build the entries if we just need the post count for the root index.
		if ( ! empty( $additionalArgs['root'] ) && $additionalArgs['root'] ) {
			return $posts;
		}

		$entries = [];
		foreach ( $posts as $post ) {
			if ( ! $post->videos ) {
				continue;
			}
			$entries[] = [
				'loc'     => get_permalink( $post->ID ),
				'lastmod' => aioseo()->helpers->dateTimeToIso8601( $post->post_modified_gmt ),
				'videos'  => json_decode( $post->videos )
			];
		}

		return apply_filters( 'aioseo_video_sitemap_posts', $entries, $postType );
	}

	/**
	 * Returns the video sitemap entries for a given taxonomy.
	 *
	 * @since 1.0.0
	 *
	 * @param  string    $taxonomy       The name of the taxonomy.
	 * @param  array     $additionalArgs Any additional arguments for the post query.
	 * @return array|int                 The sitemap entries or the term count.
	 */
	public function videoTerms( $taxonomy, $additionalArgs = [] ) {
		$terms = aioseoVideoSitemap()->query->videoTerms( $taxonomy, $additionalArgs );

		if ( ! empty( $additionalArgs['count'] ) && $additionalArgs['count'] ) {
			return $terms;
		}

		if ( ! $terms ) {
			return [];
		}

		// Get all registered post types for the taxonomy.
		$postTypes = [];
		foreach ( get_post_types() as $postType ) {
			$taxonomies = get_object_taxonomies( $postType );
			foreach ( $taxonomies as $name ) {
				if ( $taxonomy === $name ) {
					$postTypes[] = $postType;
				}
			}
		}

		// Return if we're determining the root indexes.
		if ( ! empty( $additionalArgs['root'] ) && $additionalArgs['root'] ) {
			foreach ( $terms as $term ) {
				$term->lastmod = aioseo()->sitemap->content->getTermLastModified( $term->term_id );
			}

			return $terms;
		}

		$entries = [];
		foreach ( $terms as $term ) {
			if ( ! $term->videos ) {
				continue;
			}
			$entries[] = [
				'loc'     => get_term_link( $term->term_id ),
				'lastmod' => aioseo()->sitemap->content->getTermLastModified( $term->term_id ),
				'videos'  => json_decode( $term->videos )
			];
		}

		return apply_filters( 'aioseo_video_sitemap_terms', $entries, $taxonomy );
	}
}