<?php
namespace AIOSEO\Plugin\Addon\VideoSitemap\VideoSitemap;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contains general helper methods specific to the sitemap.
 *
 * @since 1.0.0
 */
class Helpers {
	use Traits\Debug;

	/**
	 * Returns the URLs of all active sitemaps.
	 *
	 * @since 1.0.0
	 *
	 * @return array $urls The sitemap URLs.
	 */
	public function getSitemapUrls() {
		static $urls = [];
		if ( $urls ) {
			return $urls;
		}

		if ( aioseo()->options->sitemap->video->enable ) {
			$urls[] = $this->getUrl();
		}

		return $urls;
	}

	/**
	 * Gets the data for vue.
	 *
	 * @since 1.0.10
	 *
	 * @param  array $data The Vue data array.
	 * @return array       An array of data.
	 */
	public function getVueData( $data = [] ) {
		$data['urls']['videoSitemapUrl'] = $this->getUrl();

		return $data;
	}

	/**
	 * Get the sitemap URL.
	 *
	 * @since 1.0.10
	 *
	 * @return string The sitemap URL.
	 */
	public function getUrl() {
		// Check if user has a custom filename from the V3 migration.
		$filename = aioseo()->sitemap->helpers->filename( 'video' ) ?: 'video-sitemap';

		return home_url( $filename . '.xml' );
	}

	/**
	 * Get a list of common sitemap filename pattern.
	 *
	 * @since 1.0.10
	 *
	 * @return array The list of common patterns.
	 */
	public function getOtherSitemapPatterns() {
		return [
			'video[0-9s]*\.xml',
		];
	}
}