<?php
namespace AIOSEO\Plugin\Addon\NewsSitemap\NewsSitemap;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves stylesheets for sitemaps.
 *
 * @since 1.0.8
 */
class Xsl {
	/**
	 * Generates the XSL stylesheet for the current sitemap.
	 *
	 * @since 1.0.8
	 *
	 * @return void
	 */
	public function generate() {
		aioseo()->sitemap->headers();

		$charset     = aioseo()->helpers->getCharset();
		$sitemapUrl  = wp_get_referer();
		$sitemapPath = trim( aioseo()->helpers->getPermalinkPath( $sitemapUrl ), '/' );

		// Remove everything after ? from sitemapPath to avoid caching issues.
		$sitemapPath = wp_parse_url( $sitemapPath, PHP_URL_PATH ) ?: '';

		// phpcs:disable VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$parameters    = aioseo()->helpers->getParametersFromUrl( $sitemapUrl );
		$linksPerIndex = aioseo()->options->sitemap->general->linksPerIndex;
		$xslParams     = aioseo()->sitemap->xsl->getXslData( $sitemapPath );
		// phpcs:enable VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable

		echo '<?xml version="1.0" encoding="' . esc_attr( $charset ) . '"?>';
		include_once AIOSEO_NEWS_SITEMAP_DIR . '/app/Views/xsl/news.php';
		exit;
	}
}