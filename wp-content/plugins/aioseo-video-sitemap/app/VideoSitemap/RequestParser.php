<?php
namespace AIOSEO\Plugin\Addon\VideoSitemap\VideoSitemap;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parses the current request and checks whether we need to serve a sitemap or a stylesheet.
 *
 * @since 1.1.4
 */
class RequestParser {
	/**
	 * Checks whether we need to serve a sitemap or related stylesheet.
	 *
	 * @since 1.1.4
	 *
	 * @return void
	 */
	public function checkRequest() {
		if ( ! aioseo()->options->sitemap->video->enable ) {
			return;
		}

		$this->checkForXsl();

		$fileName       = aioseo()->sitemap->helpers->filename( 'video' );
		$indexesEnabled = aioseo()->options->sitemap->video->indexes;

		if ( ! $indexesEnabled ) {
			// If indexes are disabled, check for the root index.
			if ( preg_match( "/^{$fileName}\.xml(\.gz)?$/i", (string) aioseo()->sitemap->requestParser->slug, $match ) ) {
				aioseo()->sitemap->requestParser->setContext( 'video', $fileName );
				aioseo()->sitemap->generate();
			}

			return;
		}

		// First, check for the root index.
		if ( preg_match( "/^{$fileName}\.xml(\.gz)?$/i", (string) aioseo()->sitemap->requestParser->slug, $match ) ) {
			aioseo()->sitemap->requestParser->setContext( 'video', $fileName );
			aioseo()->sitemap->generate();

			return;
		}

		if (
			// Now, check for the other indexes.
			preg_match( "/^(?P<objectName>.+)-{$fileName}\.xml(\.gz)?$/i", (string) aioseo()->sitemap->requestParser->slug, $match ) ||
			preg_match( "/^(?P<objectName>.+)-{$fileName}(?P<pageNumber>\d+)\.xml(\.gz)?$/i", (string) aioseo()->sitemap->requestParser->slug, $match )
		) {
			$pageNumber = ! empty( $match['pageNumber'] ) ? $match['pageNumber'] : 0;
			aioseo()->sitemap->requestParser->setContext( 'video', $fileName, $match['objectName'], $pageNumber );
			aioseo()->sitemap->generate();
		}
	}

	/**
	 * Checks if we need to serve a stylesheet.
	 *
	 * @since 1.1.4
	 *
	 * @return void
	 */
	protected function checkForXsl() {
		// Trim off the URL params.
		$slug = preg_replace( '/\?.*$/', '', (string) aioseo()->sitemap->requestParser->slug );
		if ( preg_match( '/^video-sitemap\.xsl$/i', (string) $slug ) ) {
			aioseoVideoSitemap()->xsl->generate();
		}
	}
}