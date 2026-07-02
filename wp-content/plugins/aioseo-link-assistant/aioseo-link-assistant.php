<?php
/**
 * Plugin Name: AIOSEO - Link Assistant
 * Plugin URI:  https://aioseo.com
 * Description: Adds Link Assistant to All in One SEO.
 * Author:      All in One SEO Team
 * Author URI:  https://aioseo.com
 * Version:     1.1.11
 * Text Domain: aioseo-link-assistant
 * Domain Path: languages
 *
 * @since     1.0.0
 * @author    All in One SEO
 * @package   AIOSEO\Plugin\Addon\LinkAssistant
 * @copyright Copyright © 2025, All in One SEO
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'AIOSEO_LINK_ASSISTANT_FILE', __FILE__ );
define( 'AIOSEO_LINK_ASSISTANT_DIR', __DIR__ );
define( 'AIOSEO_LINK_ASSISTANT_PATH', plugin_dir_path( AIOSEO_LINK_ASSISTANT_FILE ) );
define( 'AIOSEO_LINK_ASSISTANT_URL', plugin_dir_url( AIOSEO_LINK_ASSISTANT_FILE ) );

// Require our translation downloader.
require_once __DIR__ . '/extend/translations.php';

add_action( 'init', 'aioseo_link_assistant_translations' );
function aioseo_link_assistant_translations() {
	$translations = new AIOSEOTranslations(
		'plugin',
		'aioseo-link-assistant',
		'https://aioseo.com/aioseo-plugin/aioseo-link-assistant/packages.json'
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

// Plugin compatibility checks.
new AIOSEOExtend( 'AIOSEO - Link Assistant', 'aioseo_link_assistant_load', AIOSEO_LINK_ASSISTANT_FILE, '4.8.3' );

/**
 * Function to load the addon.
 *
 * @since 1.0.0
 *
 * @return void
 */
function aioseo_link_assistant_load() {
	$levels = aioseo()->addons->getAddonLevels( 'aioseo-link-assistant' );
	$extend = new AIOSEOExtend( 'AIOSEO - Link Assistant', '', AIOSEO_LINK_ASSISTANT_FILE, '4.8.3', $levels );

	$addon = aioseo()->addons->getAddon( 'aioseo-link-assistant' );
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

	if ( ! aioseo()->license->isAddonAllowed( 'aioseo-link-assistant' ) ) {
		$extend->requiresPlanLevel();

		return;
	}

	require_once __DIR__ . '/app/LinkAssistant.php';

	aioseoLinkAssistant();
}