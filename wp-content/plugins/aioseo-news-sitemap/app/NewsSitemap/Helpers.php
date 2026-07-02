<?php
namespace AIOSEO\Plugin\Addon\NewsSitemap\NewsSitemap;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contains general helper methods specific to the sitemap.
 *
 * @since 1.0.5
 */
class Helpers {
	/**
	 * Returns the URLs of all active sitemaps.
	 *
	 * @since 1.0.5
	 *
	 * @return array $urls The sitemap URLs.
	 */
	public function getSitemapUrls() {
		static $urls = [];
		if ( $urls ) {
			return $urls;
		}

		if ( aioseo()->options->sitemap->news->enable ) {
			$urls[] = $this->getUrl();
		}

		return $urls;
	}

	/**
	 * Gets the data for vue.
	 *
	 * @since 1.0.5
	 *
	 * @param  array $data The Vue data array.
	 * @return array       An array of data.
	 */
	public function getVueData( $data = [] ) {
		$data['urls']['newsSitemapUrl'] = $this->getUrl();

		return $data;
	}

	/**
	 * Get the sitemap URL.
	 *
	 * @since 1.0.5
	 *
	 * @return string The sitemap URL.
	 */
	public function getUrl() {
		$filename = apply_filters( 'aioseo_news_sitemap_index_name', 'news' );

		return home_url( $filename . '-sitemap.xml' );
	}

	/**
	 * Get a list of common sitemap filename pattern.
	 *
	 * @since 1.0.5
	 *
	 * @return array The list of common patterns.
	 */
	public function getOtherSitemapPatterns() {
		return [
			'news[0-9]*\.xml'
		];
	}
}