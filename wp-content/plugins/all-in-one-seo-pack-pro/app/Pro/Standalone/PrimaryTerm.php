<?php
namespace AIOSEO\Plugin\Pro\Standalone;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Standalone\PrimaryTerm as CommonPrimaryTerm;
use AIOSEO\Plugin\Common\Models as CommonModels;

/**
 * Handles the Primary Term feature.
 *
 * @since 4.3.6
 */
class PrimaryTerm extends CommonPrimaryTerm {
	/**
	 * Class constructor.
	 *
	 * @since 4.4.1
	 */
	public function __construct() {
		parent::__construct();

		add_filter( 'post_link_category', [ $this, 'postLinkCategory' ], 10, 3 );
		add_filter( 'wc_product_post_type_link_product_cat', [ $this, 'postLinkCategory' ], 10, 3 );
		add_filter( 'post_type_link', [ $this, 'postTypeLink' ], 10, 2 );
	}

	/**
	 * Filters the post link category to change the category to the primary one.
	 *
	 * @since 4.4.1
	 *
	 * @param  \WP_Term|null $category   The category.
	 * @param  array         $categories The categories.
	 * @param  \WP_Post      $post       The post.
	 * @return \WP_Term|null             The category.
	 */
	public function postLinkCategory( $category, $categories = null, $post = null ) {
		$post = get_post( $post );
		if ( ! is_a( $post, 'WP_Post' ) ) {
			return $category;
		}

		$taxonomy = 'category';
		if ( aioseo()->helpers->isWooCommerceActive() && 'product' === $post->post_type ) {
			$taxonomy = 'product_cat';
		}

		$primaryTerm = $this->getPrimaryTerm( $post->ID, $taxonomy );
		if ( ! is_a( $primaryTerm, 'WP_Term' ) ) {
			return $category;
		}

		return $primaryTerm;
	}

	/**
	 * Returns the primary post term for the given taxonomy name.
	 *
	 * @since 4.3.6
	 *
	 * @param  int            $postId       The post ID.
	 * @param  string         $taxonomyName The taxonomy name.
	 * @return \WP_Term|false               The term or false.
	 */
	public function getPrimaryTerm( $postId, $taxonomyName ) {
		$aioseoPost   = CommonModels\Post::getPost( $postId );
		$primaryTerms = ! empty( $aioseoPost->primary_term ) ? $aioseoPost->primary_term : false;

		if ( ! $primaryTerms || empty( $primaryTerms->{$taxonomyName} ) ) {
			return apply_filters( 'aioseo_post_primary_term', false, $taxonomyName );
		}

		$term = get_term( $primaryTerms->{$taxonomyName}, $taxonomyName );
		if ( is_wp_error( $term ) ) {
			return apply_filters( 'aioseo_post_primary_term', false, $taxonomyName );
		}

		return apply_filters( 'aioseo_post_primary_term', $term, $taxonomyName );
	}

	/**
	 * Filter to allow product_brand in the permalinks for products.
	 *
	 * @since 4.8.7
	 *
	 * @param  string  $permalink The existing permalink URL.
	 * @param \WP_Post $post      The post object.
	 * @return string             The new permalink URL.
	 */
	public function postTypeLink( $permalink, $post ) {
		$brandTaxonomy = aioseo()->helpers->isPerfectBrandsActive() ? 'pwb-brand' : 'product_brand';

		// Abort if post is not a product or if the brand is not set.
		$brandName = aioseo()->helpers->getWooCommerceBrand( $post->ID );
		if ( empty( $brandName ) ) {
			return $permalink;
		}

		return str_replace( '%' . $brandTaxonomy . '%', sanitize_title( $brandName ), $permalink );
	}
}