<?php
/**
 * Astra Addon License Activation Notice
 *
 * @package Astra Addon
 * @since 4.12.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Astra_Addon_License_Notice
 *
 * Displays a license activation notice using Astra's notice library
 * when the Astra Pro license is not active.
 *
 * @since 4.12.0
 */
class Astra_Addon_License_Notice {
	/**
	 * Instance
	 *
	 * @var object Class object.
	 * @since 4.12.0
	 */
	private static $instance;

	/**
	 * Initiator
	 *
	 * @since 4.12.0
	 * @return object initialized object of class.
	 */
	public static function get_instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 *
	 * @since 4.12.0
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'register_license_notice' ) );
	}

	/**
	 * Register license activation notice
	 *
	 * @since 4.12.0
	 * @return void
	 */
	public function register_license_notice() {
		// Check if Astra_Notices class is available.
		if ( ! class_exists( 'Astra_Notices' ) ) {
			return;
		}

		// Check if license is active.
		$is_license_active = ASTRA_ADDON_BSF_PACKAGE && is_callable( 'BSF_License_Manager::bsf_is_active_license' ) ?
			call_user_func( 'BSF_License_Manager::bsf_is_active_license', $this->get_product_id() ) : false;

		// Only show notice if license is not active and it's a BSF package.
		if ( $is_license_active || ! ASTRA_ADDON_BSF_PACKAGE ) {
			return;
		}

		// Generate the notice HTML.
		$notice_html = $this->get_notice_html();

		// Add the notice using Astra_Notices library.
		Astra_Notices::add_notice(
			array(
				'id'                         => 'astra-addon-license-inactive',
				'type'                       => 'error',
				'message'                    => $notice_html,
				'show_if'                    => true,
				'repeat-notice-after'        => false,
				'display-with-other-notices' => true,
				'is_dismissible'             => true,
				'capability'                 => 'manage_options',
				'priority'                   => 8,
				'class'                      => 'astra-addon-license-notice',
			)
		);
	}

	/**
	 * Get the product ID for license check
	 *
	 * @since 4.12.0
	 * @return string Product ID
	 */
	private function get_product_id() {
		if ( is_callable( 'bsf_extract_product_id' ) ) {
			return call_user_func( 'bsf_extract_product_id', ASTRA_EXT_DIR );
		}
		return '';
	}

	/**
	 * Generate notice HTML content
	 *
	 * @since 4.12.0
	 * @return string HTML for the notice
	 */
	private function get_notice_html() {
		$activate_license_url = admin_url( 'admin.php?page=astra&path=settings' );
		$learn_more_url       = 'https://wpastra.com/?utm_source=wp&utm_medium=dashboard&utm_campaign=license-activation';
		$logo_url             = ASTRA_THEME_URI . 'inc/assets/images/astra-logo.svg';

		return sprintf(
			'<div class="astra-addon-license-notice-content">
				<div class="astra-addon-license-notice-logo">
					<img src="%s" alt="Astra Logo" />
				</div>
				<div class="astra-addon-license-notice-body-wrapper">
					<div class="astra-addon-license-notice-header">
						<strong>%s</strong>
					</div>
					<div class="astra-addon-license-notice-body">
						<p>%s</p>
					</div>
					<div class="astra-addon-license-notice-actions">
						<a href="%s" class="button button-primary" style="margin-right: 10px;">%s</a>
						<a href="%s" target="_blank" rel="noopener noreferrer" class="button">%s</a>
					</div>
				</div>
			</div>',
			esc_url( $logo_url ),
			esc_html__( 'Your Astra Pro license isn\'t active', 'astra-addon' ),
			esc_html__( 'Please activate your license to enable premium features, automatic updates, and access to support.', 'astra-addon' ),
			esc_url( $activate_license_url ),
			esc_html__( 'Activate License', 'astra-addon' ),
			esc_url( $learn_more_url ),
			esc_html__( 'Learn More', 'astra-addon' )
		);
	}
}

// Initialize the class.
Astra_Addon_License_Notice::get_instance();
