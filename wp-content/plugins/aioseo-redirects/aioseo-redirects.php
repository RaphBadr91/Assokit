<?php
/**
 * Plugin Name: AIOSEO - Redirects
 * Plugin URI:  https://aioseo.com
 * Description: Adds redirection support to AIOSEO.
 * Author:      All in One SEO Team
 * Author URI:  https://aioseo.com
 * Version:     1.4.14
 * Text Domain: aioseo-redirects
 * Domain Path: languages
 *
 * @since     1.0.0
 * @author    All in One SEO
 * @package   AIOSEO\Plugin\Addon\Redirects
 * @copyright Copyright © 2025, All in One SEO
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'AIOSEO_REDIRECTION_MANAGER_FILE', __FILE__ );
define( 'AIOSEO_REDIRECTION_MANAGER_DIR', __DIR__ );
define( 'AIOSEO_REDIRECTION_MANAGER_PATH', plugin_dir_path( AIOSEO_REDIRECTION_MANAGER_FILE ) );
define( 'AIOSEO_REDIRECTION_MANAGER_URL', plugin_dir_url( AIOSEO_REDIRECTION_MANAGER_FILE ) );

// Require our translation downloader.
require_once __DIR__ . '/extend/translations.php';

add_action( 'init', 'aioseo_addon_translations' );
function aioseo_addon_translations() {
	$translations = new AIOSEOTranslations(
		'plugin',
		'aioseo-redirects',
		'https://aioseo.com/aioseo-plugin/aioseo-redirects/packages.json'
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
if ( aioseoAddonIsDisabled( 'aioseo-redirects' ) ) {
	return;
}

// Plugin compatibility checks.
new AIOSEOExtend( 'AIOSEO - Redirects', 'aioseo_redirects_load', AIOSEO_REDIRECTION_MANAGER_FILE, '4.8.3' );

/**
 * Function to load the addon.
 *
 * @since 1.0.0
 *
 * @return void
 */
function aioseo_redirects_load() {
	$levels = aioseo()->addons->getAddonLevels( 'aioseo-redirects' );
	$extend = new AIOSEOExtend( 'AIOSEO - Redirects', '', AIOSEO_REDIRECTION_MANAGER_FILE, '4.8.3', $levels );

	$addon = aioseo()->addons->getAddon( 'aioseo-redirects' );
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

	if ( ! aioseo()->license->isAddonAllowed( 'aioseo-redirects' ) ) {
		$extend->requiresPlanLevel();

		return;
	}

	require_once __DIR__ . '/app/Redirects.php';

	aioseoRedirects();
}