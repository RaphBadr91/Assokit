<?php
namespace AIOSEO\Plugin\Addon\IndexNow\Main;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP;

/**
 * Verifies the IndexNow key.
 *
 * @since 1.0.0
 */
class Verify {
	/**
	 * Class constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		if (
			is_admin() ||
			wp_doing_ajax() ||
			wp_doing_cron() ||
			aioseo()->helpers->isRestApiRequest()
		) {
			return;
		}

		$apiKey = aioseoIndexNow()->options->indexNow->apiKey;
		if ( empty( $apiKey ) ) {
			return;
		}

		/**
		 * Hook on `parse_request` action hook in order to have the earliest access on
		 * the `WP::request` property.
		 */
		add_action( 'parse_request', [ $this, 'generateVerifyPage' ] );
	}

	/**
	 * Watch for txt requests that match the key and generates the txt file needed to verify the API key.
	 *
	 * @since 1.0.0
	 *
	 * @param  WP $wp The WordPress environment instance.
	 * @return void
	 */
	public function generateVerifyPage( $wp ) {
		$slug = $wp->request ?? aioseo()->helpers->cleanSlug( $wp->request );
		if ( ! $slug && isset( $_SERVER['REQUEST_URI'] ) ) {
			// We must fallback to the REQUEST URI in case the site uses plain permalinks.
			$slug = aioseo()->helpers->cleanSlug( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) );
		}

		if ( ! $slug ) {
			return;
		}

		$apiKey     = aioseoIndexNow()->options->indexNow->apiKey;
		$pattern    = $apiKey . '.txt';

		if (
			$apiKey
			&& $pattern === $slug
		) {
			/**
			 * Make sure this content is not buffered and prevent third-party plugins from messing with the output.
			 *
			 * @link https://github.com/awesomemotive/aioseo/issues/3820
			 */
			if ( ob_get_level() ) {
				ob_end_clean();
			}

			header( 'Content-Type: text/plain' ); // Tell the browser this page is not HTML content.
			header( 'X-Robots-Tag: noindex' );

			echo esc_html( $apiKey );
			exit;
		}
	}
}