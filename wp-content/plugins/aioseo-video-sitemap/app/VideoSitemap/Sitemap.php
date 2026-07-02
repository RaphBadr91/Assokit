<?php
namespace AIOSEO\Plugin\Addon\VideoSitemap\VideoSitemap;

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
		if (
			'video' !== aioseo()->sitemap->type ||
			! aioseo()->options->sitemap->video->advancedSettings->enable ||
			! in_array( 'staticVideoSitemap', aioseo()->internalOptions->internal->deprecatedOptions, true ) ||
			aioseo()->options->deprecated->sitemap->video->advancedSettings->dynamic
		) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! file_exists( get_home_path() . $_SERVER['REQUEST_URI'] ) ) {
			aioseo()->sitemap->scheduleRegeneration();
		}
	}
}