<?php
/**
 * Astra Pro Sites
 *
 * @since 1.0.0
 * @package Astra Pro Sites
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Astra_Pro_Sites' ) ) :

	/**
	 * Astra Pro Sites
	 *
	 * @since 1.0.0
	 */
	class Astra_Pro_Sites {

		/**
		 * Instance
		 *
		 * @since 1.0.0
		 * @access private
		 * @var object Class object.
		 */
		private static $instance = null;

		/**
		 * Initiator
		 *
		 * @since 1.0.0
		 * @return mixed 
		 */
		public static function set_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}
		/**
		 * Constructor.
		 *
		 * @since 1.0.0
		 */
		private function __construct() {

			self::includes();

			add_action( 'admin_init', array( $this, 'admin_notices' ), 1 );         
			add_filter( 'astra_sites_localize_vars', array( $this, 'update_vars' ) );
			add_filter( 'astra_sites_render_localize_vars', array( $this, 'update_vars' ) );
			add_filter( 'astra_sites_api_params', array( $this, 'api_request_params' ) );
			add_filter( 'astra_sites_menu_page_title', array( $this, 'page_title' ) );

		}

		/**
		 * Include Files.
		 *
		 * @since 1.0.7
		 * 
		 * @return void
		 */
		private static function includes() {
			require_once ASTRA_PRO_SITES_DIR . 'classes/class-astra-pro-sites-update.php';
			require_once ASTRA_PRO_SITES_DIR . 'classes/class-astra-pro-sites-white-label.php';
		}

		/**
		 * API Request Params
		 *
		 * @since 1.0.5
		 *
		 * @param  array<string, string> $args API request arguments.
		 * @return array<string, string>       Filtered API request params.
		 */
		public function api_request_params( $args = array() ) {

			$args['site_url']     = site_url();
			$args['purchase_key'] = Astra_Sites::get_instance()->get_license_key();

			return $args;
		}

		/**
		 * Page Title
		 *
		 * @since 1.0.0
		 *
		 * @param  string $title Page Title.
		 * @return string        Filtered Page Title.
		 */
		public function page_title( $title = '' ) {
			return Astra_Pro_Sites_White_Label::get_option( 'astra-sites', 'name', ASTRA_SITES_NAME );
		}

		/**
		 * Update Vars
		 *
		 * @since 1.0.0
		 *
		 * @param  array<string, string> $vars Localize variables.
		 * @return array<string, string>        Filtered localize variables.
		 */
		public function update_vars( $vars = array() ) {

			$vars['getProText'] = __( 'Get Access!', 'astra-sites' );
			$vars['getProURL']  = admin_url( 'plugins.php?bsf-inline-license-form=astra-pro-sites' );

			return $vars;
		}

		/**
		 * Admin Notices
		 *
		 * @since 1.0.0
		 * @return void
		 */
		public function admin_notices() {

			if ( ! class_exists( 'Astra_Notices' ) ) {
				return;
			}

			Astra_Notices::add_notice(
				array(
					'type'    => 'error',
					'class'   => 'astra-sites-notice',
					'show_if' => ( is_plugin_active( 'astra-sites/astra-sites.php' ) ) ? true : false,
					/* translators: %1$s white label plugin name and %2$s deactivation link */
					'message' => sprintf( __( 'You have two versions of the %1$s activated, click here to&nbsp;<a href="%2$s">Deactivate one</a>.', 'astra-sites' ), Astra_Pro_Sites_White_Label::get_option( 'astra-sites', 'name', ASTRA_SITES_NAME ), esc_url( $this->deactivation_link() ) ),
				)
			);

			add_filter( 'plugin_action_links_' . ASTRA_PRO_SITES_BASE, array( $this, 'action_links' ) );
		}


		/**
		 * Plugin Deactivation Link
		 *
		 * @since 1.0.0
		 *
		 * @param  string $slug Plugin Slug.
		 * @return string       Plugin Deactivation Link.
		 */
		private function deactivation_link( $slug = 'astra-sites' ) {

			$deactivate_url = admin_url( 'plugins.php' );
			if ( is_plugin_active_for_network( (string) ASTRA_SITES_BASE ) ) {
				$deactivate_url = network_admin_url( 'plugins.php' );
			}
			return add_query_arg(
				array(
					'action'        => 'deactivate',
					'plugin'        => rawurlencode( $slug . '/' . $slug . '.php' ),
					'plugin_status' => 'all',
					'paged'         => '1',
					'_wpnonce'      => wp_create_nonce( 'deactivate-plugin_' . $slug . '/' . $slug . '.php' ),
				),
				$deactivate_url
			);
		}

		/**
		 * Show action links on the plugin screen.
		 *
		 * @since 1.0.0
		 *
		 * @param mixed $links Plugin Action links.
		 * @return array<string, string>        Filtered plugin action links.
		 */
		public function action_links( $links = array() ) {

			if ( is_plugin_active( 'astra-sites/astra-sites.php' ) ) {
				return $links;
			}

			if ( ! class_exists( 'Astra_Sites' ) ) {
				require_once ASTRA_PRO_SITES_DIR . '/inc/classes/class-astra-sites.php';
			}

			// Get the starter templates URL.
			$url = esc_url( Astra_Sites::get_starter_templates_url() );

			$action_links = array(
				'settings' => '<a href="' . esc_url( $url ) . '" aria-label="' . esc_attr__( 'Get Started', 'astra-sites' ) . '">' . esc_html__( 'Get Started', 'astra-sites' ) . '</a>',
			);

			return array_merge( $action_links, $links );
		}       

	}

	/**
	 * Kicking this off by calling 'set_instance()' method
	 */
	Astra_Pro_Sites::set_instance();

endif;
