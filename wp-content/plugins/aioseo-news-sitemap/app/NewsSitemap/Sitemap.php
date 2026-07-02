<?php
namespace AIOSEO\Plugin\Addon\NewsSitemap\NewsSitemap;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles our sitemaps.
 *
 * @since 1.0.0
 */
class Sitemap {
	/**
	 * Checks if static file should be served and generates it if it doesn't exist.
	 *
	 * This essentially acts as a safety net in case a file doesn't exist yet or has been deleted.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function doesFileExist() {
		return;
	}
}