<?php
/**
 * Polylang Compatibility
 *
 * @package     Astra Addon
 * @link        https://wpastra.com/
 * @since       Astra 4.12.0
 */

if ( ! class_exists( 'Astra_Polylang_Compatibility' ) ) {

	/**
	 * Polylang Compatibility
	 */
	// @codingStandardsIgnoreStart
	final class Astra_Polylang_Compatibility {
		// @codingStandardsIgnoreEnd

		/**
		 * Instance of Astra_Polylang_Compatibility.
		 *
		 * @since  4.12.0
		 * @var null
		 */
		private static $instance = null;

		/**
		 * Get instance of Astra_Polylang_Compatibility
		 *
		 * @since  4.12.0
		 * @return Astra_Polylang_Compatibility
		 */
		public static function instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Setup actions and filters.
		 *
		 * @since  4.12.0
		 */
		private function __construct() {
			add_filter( 'astra_addon_get_display_posts_by_conditions', array( $this, 'get_advanced_hook_polylang_object' ), 10, 2 );
			add_filter( 'pll_copy_post_metas', array( $this, 'block_display_condition_sync' ), 1, 5 );
			add_filter( 'update_post_metadata', array( $this, 'block_polylang_meta_updates' ), 1, 5 );
		}

		/**
		 * Pass the current page advanced hook display posts to Polylang's object filter to allow strings to be translated.
		 *
		 * @since  4.12.0
		 * @param  object $current_posts Posts.
		 * @param  string $post_type Post Type.
		 *
		 * @return object  Posts.
		 */
		public function get_advanced_hook_polylang_object( $current_posts, $post_type ) {

			if ( 'astra-advanced-hook' !== $post_type ) {
				return $current_posts;
			}

			$current_lang = pll_current_language();

			if ( empty( $current_lang ) ) {
				return $current_posts;
			}

			$filtered_posts = array();

			foreach ( $current_posts as $post_id => $post_data ) {
				$post_lang = pll_get_post_language( $post_id, 'slug' );

				// Keep post if it's in the current language or has no language assigned.
				if ( empty( $post_lang ) || $post_lang === $current_lang ) {
					$filtered_posts[ $post_id ] = $post_data;
				}
			}

			return $filtered_posts;
		}

		/**
		 * Block display condition metas from syncing between translated hooks.
		 *
		 * This prevents display conditions from syncing between translated hooks.
		 *
		 * @since  4.12.0
		 * @param  array  $metas Meta keys to sync.
		 * @param  bool   $sync  Whether to sync.
		 * @param  int    $from  Source post ID.
		 * @param  int    $to    Target post ID.
		 * @param  string $lang  Language code.
		 * @return array Modified meta keys list.
		 */
		public function block_display_condition_sync( $metas, $sync, $from, $to, $lang ) {
			if ( $sync && ( 'astra-advanced-hook' === get_post_type( $from ) || 'astra-advanced-hook' === get_post_type( $to ) ) ) {
				$blocked_metas = array(
					'ast-advanced-hook-location',
					'ast-advanced-hook-exclusion',
				);
				$metas         = array_diff( $metas, $blocked_metas );
			}
			return $metas;
		}

		/**
		 * Block Polylang from updating display condition metas during sync operations.
		 *
		 * This prevents Polylang's PLL_Sync class from updating display conditions
		 *
		 * @since  4.12.0
		 * @param  mixed  $check      Whether to allow the meta update.
		 * @param  int    $object_id  Post ID.
		 * @param  string $meta_key   Meta key.
		 * @param  mixed  $meta_value Meta value.
		 * @param  mixed  $prev_value Previous meta value.
		 * @return mixed True to block the update, null to allow.
		 */
		public function block_polylang_meta_updates( $check, $object_id, $meta_key, $meta_value, $prev_value ) {
			// Early return for non-Advanced Hooks.
			if ( 'astra-advanced-hook' !== get_post_type( $object_id ) ) {
				return $check;
			}

			if ( ! in_array( $meta_key, array( 'ast-advanced-hook-location', 'ast-advanced-hook-exclusion' ), true ) ) {
				return $check;
			}

			$backtrace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 8 );
			foreach ( $backtrace as $trace ) {
				if ( isset( $trace['class'] ) && false !== strpos( $trace['class'], 'PLL_Sync' ) ) {
					return true;
				}
			}

			return $check;
		}
	}
}

/**
 * Initiate the class only if Polylang is active.
 */
if ( class_exists( 'Polylang' ) ) {
	Astra_Polylang_Compatibility::instance();
}
