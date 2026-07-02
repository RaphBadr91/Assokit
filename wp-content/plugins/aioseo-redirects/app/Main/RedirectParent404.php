<?php
namespace AIOSEO\Plugin\Addon\Redirects\Main;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Addon\Redirects\Models;

/**
 * Main class to run our 404 redirects.
 *
 * @since 1.2.2
 */
class RedirectParent404 {
	/**
	 * The redirect being parsed.
	 *
	 * @since 1.2.2
	 *
	 * @var Models\Redirect404
	 */
	private $redirect = null;

	/**
	 * Class constructor.
	 *
	 * @since 1.2.2
	 *
	 * @param Models\Redirect404 $redirect The redirect to parse.
	 */
	public function __construct( $redirect ) {
		$this->redirect = $redirect;
	}

	/**
	 * Redirects to a post parent.
	 *
	 * @since 1.2.2
	 *
	 * @return void
	 */
	public function postParentRedirect() {
		// Try to redirect to a parent post.
		if ( ! empty( $this->redirect->parent_posts ) ) {
			foreach ( $this->redirect->parent_posts as $parentPost ) {
				$parentPost = get_post( $parentPost );
				// Try published parents until we find a valid one.
				if ( 'publish' !== $parentPost->post_status ) {
					continue;
				}

				aioseoRedirects()->helpers->do404Redirect( get_permalink( $parentPost ), 'PARENT-POST' );
			}
		}
	}

	/**
	 * Redirects to a term parent.
	 *
	 * @since 1.2.2
	 *
	 * @param  string $taxonomy A taxonomy name.
	 * @return void
	 */
	public function termParentRedirect( $taxonomy = '' ) {
		// Try to redirect to a parent tax/term.
		if ( ! empty( $this->redirect->parent_terms ) ) {
			foreach ( $this->redirect->parent_terms as $tax => $terms ) {
				if ( ! empty( $taxonomy ) && $tax !== $taxonomy ) {
					continue;
				}

				foreach ( $terms as $term ) {
					$parentTerm = get_term( $term, $tax );
					// Try valid terms until we find one.
					if ( ! is_a( $parentTerm, 'WP_Term' ) ) {
						continue;
					}

					aioseoRedirects()->helpers->do404Redirect( get_term_link( $parentTerm ), 'PARENT-TERM' );
				}
			}
		}
	}

	/**
	 * Redirects to a post type archive.
	 *
	 * @since 1.2.2
	 *
	 * @return void
	 */
	public function postTypeArchiveRedirect() {
		// Try the post type archive.
		if ( ! empty( $this->redirect->post_type ) ) {
			if ( aioseo()->helpers->getPostTypeFeature( $this->redirect->post_type, 'has_archive' ) ) {
				aioseoRedirects()->helpers->do404Redirect( get_post_type_archive_link( $this->redirect->post_type ), 'PARENT-CPT-ARCHIVE' );
			}
		}
	}

	/**
	 * Tries a WooCommerce redirect.
	 *
	 * @since 1.2.2
	 *
	 * @return void
	 */
	public function woocommerceParentRedirect() {
		if ( ! aioseo()->helpers->isWooCommerceActive() || ! aioseoRedirects()->options->advanced404s->redirectToParentWoocommerce ) {
			return;
		}

		// We only support product and product category.
		if ( 'product' !== $this->redirect->post_type && 'product_cat' !== $this->redirect->taxonomy ) {
			return;
		}

		// Try the shop page.
		$shopPage = wc_get_page_id( 'shop' );
		if ( ! empty( $shopPage ) ) {
			aioseoRedirects()->helpers->do404Redirect( get_permalink( $shopPage ), 'PARENT-WC' );
		}
	}
}