<?php

if (!defined('UPDRAFTPLUS_DIR')) die('No direct access allowed');

if (!defined('WP_CLI') || !WP_CLI || !class_exists('WP_CLI_Command')) return;

/**
 * Implements Updraftplus CLI all commands
 */
class UpdraftPlus_CLI_Command_Base extends WP_CLI_Command {
	
	/**
	 * Register UpdraftPlus product key
	 *
	 * ## OPTIONS
	 *
	 * <product_key>
	 * : The product key
	 *
	 * ## EXAMPLES
	 *
	 * wp updraftplus register_product_key A1B2C3D4E5F6G7H8I
	 *
	 * @when after_wp_load
	 *
	 * @param Array $args A indexed array of command line arguments
	 */
	public function register_product_key($args) {
		if (empty($args[0])) WP_CLI::error(__("Missing parameter", 'updraftplus'));

		$product_key = sanitize_text_field($args[0]);
		$product_key_meta = array(
			'registered_at' => time(),
			'site_url' => network_site_url(),
		);
		
		update_site_option('updraftplus_product_key', $product_key);
		update_site_option('updraftplus_product_key_meta', $product_key_meta);
		
		WP_CLI::success(__("The product key has been registered successfully.", 'updraftplus'));
	}
}
