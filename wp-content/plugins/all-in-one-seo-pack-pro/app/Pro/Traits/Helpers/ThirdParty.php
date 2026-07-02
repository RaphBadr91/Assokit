<?php
namespace AIOSEO\Plugin\Pro\Traits\Helpers;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contains all third-party related helper methods.
 *
 * @since 4.1.4
 */
trait ThirdParty {
	/**
	 * Returns the first WooCommerce brand if there is one.
	 *
	 * Supports the WooCommerce Brands and Perfect Brands plugins.
	 *
	 * @since 4.0.13
	 *
	 * @param  int    $id The product ID.
	 * @return string     The product brand.
	 */
	public function getWooCommerceBrand( $id = null ) {
		if ( ! $this->isWooCommerceActive() ||
			( ! $this->isWooCommerceBrandsActive() && ! $this->isPerfectBrandsActive() ) ) {
			return '';
		}

		$product = $this->getPost( $id );
		if ( ! is_object( $product ) || 'product' !== $product->post_type ) {
			return '';
		}

		$taxonomies = [
			'pwb-brand'     => $this->isPerfectBrandsActive(),
			'product_brand' => $this->isWooCommerceBrandsActive()
		];

		foreach ( $taxonomies as $taxonomy => $isActive ) {
			if ( ! $isActive || ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$primaryTerm = aioseo()->standalone->primaryTerm->getPrimaryTerm( $id, $taxonomy );
			if ( $primaryTerm ) {
				return $primaryTerm->name;
			}

			$terms = get_the_terms( $product->ID, $taxonomy );
			if ( ! empty( $terms[0]->name ) ) {
				return $terms[0]->name;
			}
		}

		return '';
	}

	/**
	 * Checks if the WooCommerce Brands plugin is active.
	 *
	 * @since 4.0.13
	 *
	 * @return bool Whether the plugin is active.
	 */
	public function isWooCommerceBrandsActive() {
		return class_exists( 'WC_Brands' );
	}

	/**
	 * Checks if the Perfect Brands plugin is active.
	 *
	 * @since 4.0.13
	 *
	 * @return bool Whether the plugin is active.
	 */
	public function isPerfectBrandsActive() {
		return class_exists( '\Perfect_WooCommerce_Brands\Perfect_Woocommerce_Brands' )
			|| class_exists( '\QuadLayers\PWB\Plugin' );
	}

	/**
	 * Checks if the WooCommerce UPC, EAN & ISBN plugin is active.
	 *
	 * @since 4.2.6
	 *
	 * @return bool Whether the plugin is active.
	 */
	public function isWooCommerceUpcEanIsbnActive() {
		return class_exists( 'Woo_GTIN' );
	}

	/**
	 * Checks whether EDD is active.
	 *
	 * @since 4.0.13
	 *
	 * @return bool Whether EDD is active.
	 */
	public function isEddActive() {
		return class_exists( 'Easy_Digital_Downloads' );
	}

	/**
	 * Checks whether EDD Reviews is active.
	 *
	 * @since 4.0.13
	 *
	 * @return bool Whether EDD Reviews is active.
	 */
	public function isEddReviewsActive() {
		return class_exists( 'EDD_Reviews' );
	}

	/**
	 * Checks whether MemberMouse is active.
	 *
	 * @since 4.6.4
	 *
	 * @return bool Whether MemberMouse is active.
	 */
	public function isMemberMouseActive() {
		return is_plugin_active( 'membermouse/index.php' );
	}

	/**
	 * Checks whether MemberMouse Courses is active.
	 *
	 * @since 4.6.4
	 *
	 * @return bool Whether MemberMouse Courses is active.
	 */
	public function isMemberMouseCoursesActive() {
		return $this->isMemberMouseActive() && defined( 'membermouse\courses\VERSION' );
	}

	/**
	 * Checks whether MemberPress is active.
	 *
	 * @since 4.6.4
	 *
	 * @return bool Whether MemberPress is active.
	 */
	public function isMemberPressActive() {
		return defined( 'MEPR_PLUGIN_NAME' );
	}

	/**
	 * Checks whether MemberPress Courses is active.
	 *
	 * @since 4.6.4
	 *
	 * @return bool Whether MemberPress Courses is active.
	 */
	public function isMemberPressCoursesActive() {
		return $this->isMemberPressActive() && defined( 'memberpress\courses\VERSION' );
	}

	/**
	 * Checks whether WishList Member is active.
	 *
	 * @since 4.6.4
	 *
	 * @return bool Whether WishList Member is active.
	 */
	public function isWishListMemberActive() {
		return defined( 'WLM_PLUGIN_FILE' );
	}

	/**
	 * Checks whether WishList Member CourseCure is active.
	 *
	 * @since 4.6.4
	 *
	 * @return bool Whether WishList Member CourseCure is active.
	 */
	public function isWishListCourseCureActive() {
		return $this->isWishListMemberActive() && defined( 'WishlistCourses\PLUGIN_URL' );
	}
}