<?php
namespace AIOSEO\Plugin\Pro\Sitemap;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Sitemap as CommonSitemap;

/**
 * Parses the current request and checks whether we need to serve a sitemap or a stylesheet.
 *
 * @since 4.2.1
 */
class RequestParser extends CommonSitemap\RequestParser {
	/**
	 * Checks whether we need to serve a sitemap or related stylesheet.
	 *
	 * @since 4.2.1
	 *
	 * @param  \WP  $wp The main WordPress environment instance.
	 * @return void
	 */
	public function checkRequest( $wp ) {
		$this->slug = $wp->request
			? aioseo()->helpers->cleanSlug( $wp->request )
			// We must fallback to the REQUEST URI in case the site uses plain permalinks.
			: aioseo()->helpers->cleanSlug( aioseo()->helpers->getRequestUrl() );

		// Check if we need to remove the trailing slash or redirect another sitemap URL like "wp-sitemap.xml".
		$this->maybeRedirect();

		aioseo()->addons->doAddonFunction( 'requestParser', 'checkRequest' );

		// The addons need to run before Core does, since the Video and News Sitemap will otherwise be mistaken for the regular one.
		parent::checkRequest( $wp );
	}
}