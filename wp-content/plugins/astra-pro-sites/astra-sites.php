<?php
/**
 * Astra Sites
 *
 * @package Astra Sites
 */

/**
 * Set constants.
 */

if ( ! defined( 'ASTRA_SITES_NAME' ) ) {
	define( 'ASTRA_SITES_NAME', 'Starter Templates' );
}

if ( ! defined( 'ASTRA_SITES_VER' ) ) {
	define( 'ASTRA_SITES_VER', '4.4.49' );
}

if ( ! defined( 'ASTRA_SITES_FILE' ) ) {
	define( 'ASTRA_SITES_FILE', __FILE__ );
}

if ( ! defined( 'ASTRA_SITES_BASE' ) ) {
	define( 'ASTRA_SITES_BASE', plugin_basename( ASTRA_SITES_FILE ) );
}

if ( ! defined( 'ASTRA_SITES_DIR' ) ) {
	define( 'ASTRA_SITES_DIR', plugin_dir_path( ASTRA_SITES_FILE ) );
}

if ( ! defined( 'ASTRA_SITES_URI' ) ) {
	define( 'ASTRA_SITES_URI', plugins_url( '/', ASTRA_SITES_FILE ) );
}

// Load AI Builder.
if ( file_exists( ASTRA_SITES_DIR . 'inc/lib/ai-builder/ai-builder.php' ) ) {
	require_once ASTRA_SITES_DIR . 'inc/lib/ai-builder/ai-builder.php';
}

// Load ST Importer.
if ( file_exists( ASTRA_SITES_DIR . 'inc/lib/starter-templates-importer/starter-templates-importer.php' ) ) {
	require_once ASTRA_SITES_DIR . 'inc/lib/starter-templates-importer/starter-templates-importer.php';
}

// Load Getting Started.
if ( file_exists( ASTRA_SITES_DIR . 'inc/lib/getting-started/getting-started.php' ) ) {
	require_once ASTRA_SITES_DIR . 'inc/lib/getting-started/getting-started.php';
}

// Load One Onboarding.
if ( file_exists( ASTRA_SITES_DIR . 'inc/lib/one-onboarding/loader.php' ) ) {
	require_once ASTRA_SITES_DIR . 'inc/lib/one-onboarding/loader.php';
}

require_once ASTRA_SITES_DIR . 'inc/classes/class-astra-sites.php';

// BSF_Quick_Links.
if ( ! class_exists( 'BSF_Quick_Links' ) ) {
	require_once ASTRA_SITES_DIR . 'inc/lib/bsf-quick-links/class-bsf-quick-links.php';
}

// Astra Notices.
require_once ASTRA_SITES_DIR . 'inc/lib/astra-notices/class-astra-notices.php';

// BSF Analytics Tracker.
if ( ! class_exists( 'BSF_Analytics_Loader' ) ) {
	require_once ASTRA_SITES_DIR . 'admin/bsf-analytics/class-bsf-analytics-loader.php';
}

add_action( 'init', 'astra_pro_sites_init_bsf_analytics', 5 );

/**
 * Initializes BSF Analytics.
 *
 * @since 4.4.15
 * @return void
 */
function astra_pro_sites_init_bsf_analytics() {
	if ( ! class_exists( 'BSF_Analytics_Loader' ) || ! is_callable( 'BSF_Analytics_Loader::get_instance' ) ) {
		return;
	}

	$bsf_analytics = BSF_Analytics_Loader::get_instance();
	$bsf_analytics->set_entity(
		array(
			'astra_sites' => array(
				'product_name'        => 'Premium Starter Templates',
				'path'                => ASTRA_SITES_DIR . 'admin/bsf-analytics',
				'author'              => 'Brainstorm Force',
				'time_to_display'     => '+24 hours',
				'deactivation_survey' => apply_filters(
					'astra_sites_bsf_analytics_deactivation_survey_data',
					array(
						array(
							'id'                => 'deactivation-survey-astra-pro-sites',
							'popup_logo'        => ASTRA_SITES_URI . 'inc/lib/onboarding/assets/images/logo.svg',
							'plugin_slug'       => 'astra-pro-sites',
							'plugin_version'    => ASTRA_SITES_VER,
							'popup_title'       => __( 'Quick Feedback', 'astra-sites' ),
							'support_url'       => 'https://wpastra.com/starter-templates-support/',
							'popup_description' => __( 'If you have a moment, please share why you are deactivating Starter Templates:', 'astra-sites' ),
							'show_on_screens'   => array( 'plugins' ),
						),
					)
				),
			),
		)
	);
}
