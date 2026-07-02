<?php
/**
 * Plugin Name: Premium Starter Templates
 * Plugin URI: https://wpastra.com/
 * Description: Starter Templates is all in one solution for complete starter sites, single page templates, blocks & images. This plugin offers the premium library of ready templates & provides quick access to beautiful Pixabay images that can be imported in your website easily.
 * Version: 4.4.49
 * Requires at least: 6.6
 * Requires PHP: 7.4
 * Author: Brainstorm Force
 * Author URI: https://www.brainstormforce.com
 * Text Domain: astra-sites
 *
 * @package Astra Pro Sites
 */

// Check PHP version before loading the plugin.
if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	add_action( 'admin_notices', 'astra_pro_sites_php_version_notice' );
	return;
}
/**
 * Display notice if PHP version is below 7.4
 * 
 * @return void
 */
function astra_pro_sites_php_version_notice() {
	$plugin_name = 'Premium Starter Templates';
	?>
	<div class="error">
		<p><?php echo esc_html( $plugin_name . ' requires PHP version 7.4 or higher. Please upgrade your PHP version.' ); ?></p>
	</div>
	<?php
}

define( 'ASTRA_PRO_SITES_NAME', 'Premium Starter Templates' );
define( 'ASTRA_PRO_SITES_VER', '4.4.49' );
define( 'ASTRA_PRO_SITES_FILE', __FILE__ );
define( 'ASTRA_PRO_SITES_BASE', plugin_basename( ASTRA_PRO_SITES_FILE ) );
define( 'ASTRA_PRO_SITES_DIR', plugin_dir_path( ASTRA_PRO_SITES_FILE ) );
define( 'ASTRA_PRO_SITES_URI', plugins_url( '/', ASTRA_PRO_SITES_FILE ) );

if ( ! function_exists( 'astra_pro_sites_setup' ) ) :

	/**
	 * Astra Sites Setup
	 *
	 * @since 1.0.0
	 * @return void
	 */
	function astra_pro_sites_setup() {

		require_once ASTRA_PRO_SITES_DIR . 'classes/class-astra-pro-sites.php';

		// Graupi.
		require_once 'class-brainstorm-update-astra-pro-sites.php';

		if ( ! class_exists( 'Astra_Sites' ) ) {
			require_once ASTRA_PRO_SITES_DIR . 'astra-sites.php';
		}
	}

	add_action( 'plugins_loaded', 'astra_pro_sites_setup', 11 );

endif;


if ( ! function_exists( 'astra_pro_sites_fetch_bundled_products' ) ) :

	/**
	 * Fetch Bundled Products
	 *
	 * @since 1.1.2 Checking required plugins on `register_activation_hook` hook instead of `admin_init`.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	function astra_pro_sites_fetch_bundled_products() {
		update_site_option( 'bsf_force_check_extensions', true );
	}

	register_activation_hook( __FILE__, 'astra_pro_sites_fetch_bundled_products' );

endif;


if ( ! function_exists( 'astra_sites_redirect_to_onboarding' ) ) :

	/**
	 * Redirect to onboarding.
	 *
	 * @since 3.3.0
	 * @return void
	 */
	function astra_sites_redirect_to_onboarding() {
		if ( ! get_option( 'st_start_onboarding', false ) ) {
			return;
		}

		delete_option( 'st_start_onboarding' );
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {

			if ( ! class_exists( 'Astra_Sites' ) ) {
				require_once ASTRA_PRO_SITES_DIR . '/inc/classes/class-astra-sites.php';
			}
			// Use helper function to get URL with appropriate ci parameter based on flags.
			$redirect_url = Astra_Sites::get_starter_templates_url();
			wp_safe_redirect( $redirect_url );
			exit();
		}
	}

	add_action( 'admin_init', 'astra_sites_redirect_to_onboarding' );

endif;

if ( ! function_exists( 'astra_pro_sites_activate' ) ) :

	/**
	 * Astra pro sites activate.
	 *
	 * @since 4.1.2
	 * @return void
	 */
	function astra_pro_sites_activate() {
		update_option( 'st_start_onboarding', true );
	}
	register_activation_hook( __FILE__, 'astra_pro_sites_activate' );

endif;
