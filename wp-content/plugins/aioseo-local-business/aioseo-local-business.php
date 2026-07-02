<?php
/**
 * Plugin Name: AIOSEO - Local Business
 * Plugin URI:  https://aioseo.com
 * Description: Adds Local Business support to All in One SEO.
 * Author:      All in One SEO Team
 * Author URI:  https://aioseo.com
 * Version:     1.3.12
 * Text Domain: aioseo-local-business
 * Domain Path: languages
 *
 * @since     1.0.0
 * @author    All in One SEO
 * @package   AIOSEO\Plugin\Addon\LocalBusiness
 * @copyright Copyright © 2025, All in One SEO
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'AIOSEO_LOCAL_BUSINESS_FILE', __FILE__ );
define( 'AIOSEO_LOCAL_BUSINESS_DIR', __DIR__ );
define( 'AIOSEO_LOCAL_BUSINESS_PATH', plugin_dir_path( AIOSEO_LOCAL_BUSINESS_FILE ) );
define( 'AIOSEO_LOCAL_BUSINESS_URL', plugin_dir_url( AIOSEO_LOCAL_BUSINESS_FILE ) );

// Require our translation downloader.
require_once __DIR__ . '/extend/translations.php';

add_action( 'init', 'aioseo_local_business_translations' );
function aioseo_local_business_translations() {
	$translations = new AIOSEOTranslations(
		'plugin',
		'aioseo-local-business',
		'https://aioseo.com/aioseo-plugin/aioseo-local-business/packages.json'
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
if ( aioseoAddonIsDisabled( 'aioseo-local-business' ) ) {
	return;
}

// Plugin compatibility checks.
new AIOSEOExtend( 'AIOSEO - Local Business', 'aioseo_local_business_load', AIOSEO_LOCAL_BUSINESS_FILE, '4.9.0' );

/**
 * Function to load the addon.
 *
 * @since 1.0.0
 *
 * @return void
 */
function aioseo_local_business_load() {
	$levels = aioseo()->addons->getAddonLevels( 'aioseo-local-business' );
	$extend = new AIOSEOExtend( 'AIOSEO - Local Business', '', AIOSEO_LOCAL_BUSINESS_FILE, '4.9.0', $levels );

	$addon = aioseo()->addons->getAddon( 'aioseo-local-business' );
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

	if ( ! aioseo()->license->isAddonAllowed( 'aioseo-local-business' ) ) {
		$extend->requiresPlanLevel();

		return;
	}

	require_once __DIR__ . '/app/LocalBusiness.php';

	aioseoLocalBusiness();
}