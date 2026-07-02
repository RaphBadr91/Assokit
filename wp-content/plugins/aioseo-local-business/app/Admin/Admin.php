<?php
namespace AIOSEO\Plugin\Addon\LocalBusiness\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Admin class.
 *
 * @since 1.1.0
 */
class Admin {
	/**
	 * Class constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		if ( is_admin() ) {
			add_action( 'add_meta_boxes', [ $this, 'addMetabox' ] );
		}
	}

	/**
	 * Adds a meta box to the page/posts screens.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function addMetabox() {
		if ( ! aioseo()->access->hasCapability( 'aioseo_page_local_seo_settings' ) ) {
			return;
		}

		// Translators: 1 - The plugin short name ("AIOSEO").
		$aioseoMetaboxTitle = sprintf( esc_html__( '%1$s Local Business', 'aioseo-local-business' ), AIOSEO_PLUGIN_SHORT_NAME );

		add_meta_box(
			'aioseo-local-settings',
			$aioseoMetaboxTitle,
			[ $this, 'renderMetabox' ],
			[ aioseoLocalBusiness()->postType->getName() ],
			'normal',
			'high'
		);
	}

	/**
	 * Render the on-page settings metabox with the Vue App wrapper.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function renderMetabox() {
		aioseo()->postSettings->postSettingsHiddenField();
		?>
		<div id="aioseo-location-settings-metabox">
			<?php aioseo()->templates->getTemplate( 'parts/loader.php' ); ?>
		</div>
		<?php
	}
}