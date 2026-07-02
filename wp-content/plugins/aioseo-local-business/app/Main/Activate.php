<?php
namespace AIOSEO\Plugin\Addon\LocalBusiness\Main;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activate class.
 *
 * @since 1.1.0
 */
class Activate {
	/**
	 * Construct method.
	 *
	 * @since 1.1.0
	 */
	public function __construct() {
		register_deactivation_hook( AIOSEO_LOCAL_BUSINESS_FILE, [ $this, 'deactivate' ] );
	}

	/**
	 * Runs on deactivation.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function deactivate() {
		aioseo()->options->flushRewriteRules();
	}
}