<?php
/**
 * Plugin Name: AIOSEO - News Sitemap
 * Plugin URI:  https://aioseo.com
 * Description: Adds Google News Sitemap support to All in One SEO.
 * Author:      All in One SEO Team
 * Author URI:  https://aioseo.com
 * Version:     1.0.20
 * Text Domain: aioseo-news-sitemap
 * Domain Path: languages
 *
 * @since     1.0.0
 * @author    All in One SEO
 * @package   AIOSEO\Plugin\Addon\NewsSitemap
 * @copyright Copyright © 2025, All in One SEO
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'AIOSEO_NEWS_SITEMAP_FILE', __FILE__ );
define( 'AIOSEO_NEWS_SITEMAP_DIR', __DIR__ );
define( 'AIOSEO_NEWS_SITEMAP_PATH', plugin_dir_path( AIOSEO_NEWS_SITEMAP_FILE ) );
define( 'AIOSEO_NEWS_SITEMAP_URL', plugin_dir_url( AIOSEO_NEWS_SITEMAP_FILE ) );

// Require our translation downloader.
require_once __DIR__ . '/extend/translations.php';

add_action( 'init', 'aioseo_news_sitemap_translations' );
function aioseo_news_sitemap_translations() {
	$translations = new AIOSEOTranslations(
		'plugin',
		'aioseo-news-sitemap',
		'https://aioseo.com/aioseo-plugin/aioseo-news-sitemap/packages.json'
	);
	$translations->init();

	// @NOTE: The slugs here need to stay as aioseo-addon.
	$addonTranslations = new AIOSEOTranslations(
		'plugin',
		'aioseo-addon',
		'https://aioseo.com/aioseo-plugin/aioseo-addon/packages.json'
	);
	$addonTranslations->init();
}

// Require our plugin compatibility checker.
require_once __DIR__ . '/extend/init.php';

// Check if this plugin should be disabled.
if ( aioseoAddonIsDisabled( 'aioseo-news-sitemap' ) ) {
	return;
}

// Plugin compatibility checks.
new AIOSEOExtend( 'AIOSEO - News Sitemap', 'aioseo_news_sitemap_load', AIOSEO_NEWS_SITEMAP_FILE, '4.8.3' );

/**
 * Function to load the News Sitemap addon.
 *
 * @since 1.0.0
 *
 * @return void
 */
function aioseo_news_sitemap_load() {
	$levels = aioseo()->addons->getAddonLevels( 'aioseo-news-sitemap' );
	$extend = new AIOSEOExtend( 'AIOSEO - News Sitemap', '', AIOSEO_NEWS_SITEMAP_FILE, '4.8.3', $levels );

	$addon = aioseo()->addons->getAddon( 'aioseo-news-sitemap' );
	if ( ! $addon->hasMinimumVersion ) {
		$extend->requiresUpdate();

		return;
	}

	if ( ! aioseo()->pro ) {
		$extend->requiresPro();

		return;
	}

	// We don't want to return if the plan is only expired.
	if ( aioseo()->license->isExpired() ) {
		$extend->requiresUnexpiredLicense();
		$extend->disableNotices = true;
	}

	if ( aioseo()->license->isInvalid() || aioseo()->license->isDisabled() ) {
		$extend->requiresActiveLicense();

		return;
	}

	if ( ! aioseo()->license->isAddonAllowed( 'aioseo-news-sitemap' ) ) {
		$extend->requiresPlanLevel();

		return;
	}

	require_once __DIR__ . '/app/NewsSitemap.php';

	aioseoNewsSitemap();
}