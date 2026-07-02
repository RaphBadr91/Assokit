<?php
namespace AIOSEO\Plugin\Addon\VideoSitemap\VideoSitemap;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves stylesheets for sitemaps.
 *
 * @since 1.1.4
 */
class Xsl {
	/**
	 * Generates the XSL stylesheet for the current sitemap.
	 *
	 * @since 1.1.4
	 *
	 * @return void
	 */
	public function generate() {
		aioseo()->sitemap->headers();

		$charset     = aioseo()->helpers->getCharset();
		$sitemapUrl  = wp_get_referer();
		$sitemapPath = aioseo()->helpers->getPermalinkPath( $sitemapUrl );

		// Remove everything after ? from sitemapPath to avoid caching issues.
		$sitemapPath = wp_parse_url( $sitemapPath, PHP_URL_PATH ) ?: '';

		// Get Sitemap info by URL.
		preg_match( '/\/(.*?)-?video-sitemap([0-9]*)\.xml/', (string) $sitemapPath, $sitemapInfo );

		$sitemapName = ! empty( $sitemapInfo[1] ) ? $sitemapInfo[1] : '';
		if ( post_type_exists( $sitemapName ) ) {
			$postTypeObject = get_post_type_object( $sitemapName );
			$sitemapName    = $postTypeObject->labels->singular_name;
		}
		if ( taxonomy_exists( $sitemapName ) ) {
			$taxonomyObject = get_taxonomy( $sitemapName );
			$sitemapName    = $taxonomyObject->labels->singular_name;
		}

		$currentPage = ! empty( $sitemapInfo[2] ) ? (int) $sitemapInfo[2] : 1;

		// phpcs:disable VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$linksPerIndex = aioseo()->options->sitemap->video->linksPerIndex;
		$sitemapParams = aioseo()->helpers->getParametersFromUrl( $sitemapUrl );
		$xslParams     = aioseo()->sitemap->xsl->getXslData( trim( $sitemapPath, '/' ) );
		// phpcs:enable VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable

		$title = sprintf(
			// Translators: 1 - The sitemap name, 2 - The current page.
			__( '%1$s Video Sitemap %2$s', 'aioseo-video-sitemap' ),
			$sitemapName,
			$currentPage > 1 ? $currentPage : ''
		);
		$title = trim( $title );

		echo '<?xml version="1.0" encoding="' . esc_attr( $charset ) . '"?>';
		include_once AIOSEO_VIDEO_SITEMAP_DIR . '/app/Views/xsl/video.php';
		exit;
	}
}