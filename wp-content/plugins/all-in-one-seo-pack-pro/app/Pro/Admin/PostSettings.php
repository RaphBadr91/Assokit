<?php
namespace AIOSEO\Plugin\Pro\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Lite\Admin as LiteAdmin;

/**
 * Abstract class that Pro and Lite both extend.
 *
 * @since 4.0.0
 */
class PostSettings extends LiteAdmin\PostSettings {
	/**
	 * Class constructor.
	 *
	 * @since 4.7.8
	 */
	public function __construct() {
		parent::__construct();

		// Add metabox to terms.
		add_action( 'current_screen', [ $this, 'init' ] );
	}

	/**
	 * Add metabox to terms.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	public function init() {
		if ( ! aioseo()->license->isActive() ) {
			parent::init();

			return;
		}

		$generalSettingsCapability  = aioseo()->access->hasCapability( 'aioseo_page_general_settings' );
		$socialSettingsCapability   = aioseo()->access->hasCapability( 'aioseo_page_social_settings' );
		$advancedSettingsCapability = aioseo()->access->hasCapability( 'aioseo_page_advanced_settings' );
		if (
			empty( $generalSettingsCapability ) &&
			empty( $socialSettingsCapability ) &&
			empty( $advancedSettingsCapability )
		) {
			return;
		}

		$currentScreen = aioseo()->helpers->getCurrentScreen();
		if (
			empty( $currentScreen ) ||
			! in_array( $currentScreen->base, [ 'edit-tags', 'term' ], true )
		) {
			return;
		}

		$termId           = intval( filter_input( INPUT_GET, 'tag_ID', FILTER_SANITIZE_NUMBER_INT ) );
		$term             = aioseo()->helpers->getTerm( $termId );
		$publicTaxonomies = aioseo()->helpers->getPublicTaxonomies( true );
		if ( empty( $term->taxonomy ) || ! in_array( $term->taxonomy, $publicTaxonomies, true ) ) {
			return;
		}

		$dynamicOptions = aioseo()->dynamicOptions->noConflict();
		if (
			! $dynamicOptions->searchAppearance->taxonomies->has( $term->taxonomy ) ||
			! $dynamicOptions->searchAppearance->taxonomies->{$term->taxonomy}->advanced->showMetaBox
		) {
			return;
		}

		add_action( "{$currentScreen->taxonomy}_edit_form", [ $this, 'addTermsMetabox' ] );
	}

	/**
	 * Adds a meta box to edit terms screens.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	public function addTermsMetabox() {
		if ( ! aioseo()->license->isActive() ) {
			return;
		}

		wp_enqueue_media();
		?>
		<div id="poststuff">
			<div id="advanced-sortables" class="meta-box-sortables">
				<div id="aioseo-tabbed" class="postbox ">
					<h2 class="hndle">
						<span>
						<?php
							echo sprintf(
								// Translators: 1 - The plugin short name ("AIOSEO").
								esc_html__( '%1$s Settings', 'aioseo-pro' ),
								AIOSEO_PLUGIN_SHORT_NAME //phpcs:ignore
							)
						?>
						</span>
					</h2>
					<div id="aioseo-term-settings-field">
						<input type="hidden" name="aioseo-term-settings" id="aioseo-term-settings" value="" />
						<?php wp_nonce_field( 'aioseoTermSettingsNonce', 'TermSettingsNonce' ); ?>
					</div>
					<div id="aioseo-term-settings-metabox" class="inside">
						<?php aioseo()->templates->getTemplate( 'parts/loader.php' ); ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}