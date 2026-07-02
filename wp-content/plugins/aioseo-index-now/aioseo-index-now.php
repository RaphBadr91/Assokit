<?php
/**
 * Plugin Name: AIOSEO - IndexNow
 * Plugin URI:  https://aioseo.com
 * Description: Adds IndexNow support to AIOSEO.
 * Author:      All in One SEO Team
 * Author URI:  https://aioseo.com
 * Version:     1.0.13
 * Text Domain: aioseo-index-now
 * Domain Path: languages
 *
 * @since     1.0.0
 * @author    All in One SEO
 * @package   AIOSEO\Plugin\Addon\IndexNow
 * @copyright Copyright © 2025, All in One SEO
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'AIOSEO_INDEX_NOW_FILE', __FILE__ );
define( 'AIOSEO_INDEX_NOW_DIR', __DIR__ );
define( 'AIOSEO_INDEX_NOW_PATH', plugin_dir_path( AIOSEO_INDEX_NOW_FILE ) );
define( 'AIOSEO_INDEX_NOW_URL', plugin_dir_url( AIOSEO_INDEX_NOW_FILE ) );

// Require our translation downloader.
require_once __DIR__ . '/extend/translations.php';

add_action( 'init', 'aioseo_index_now_translations' );
function aioseo_index_now_translations() {
	$translations = new AIOSEOTranslations(
		'plugin',
		'aioseo-index-now',
		'https://aioseo.com/aioseo-plugin/aioseo-index-now/packages.json'
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
if ( aioseoAddonIsDisabled( 'aioseo-index-now' ) ) {
	return;
}

// Plugin compatibility checks.
new AIOSEOExtend( 'AIOSEO - IndexNow', 'aioseo_index_now_load', AIOSEO_INDEX_NOW_FILE, '4.7.7' );

/**
 * Function to load the addon.
 *
 * @since 1.0.0
 *
 * @return void
 */
function aioseo_index_now_load() {
	$levels = aioseo()->addons->getAddonLevels( 'aioseo-index-now' );
	$extend = new AIOSEOExtend( 'AIOSEO - IndexNow', '', AIOSEO_INDEX_NOW_FILE, '4.7.7', $levels );

	$addon = aioseo()->addons->getAddon( 'aioseo-index-now' );
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

	if ( ! aioseo()->license->isAddonAllowed( 'aioseo-index-now' ) ) {
		$extend->requiresPlanLevel();

		return;
	}

	require_once __DIR__ . '/app/IndexNow.php';

	aioseoIndexNow();
}