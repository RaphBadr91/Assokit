<?php
namespace AIOSEO\Plugin\Addon\LocalBusiness\Locations;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Maps class.
 *
 * @since 1.1.3
 */
class Maps {
	/**
	 * Map load event.
	 *
	 * @since 1.1.3
	 *
	 * @var string
	 */
	public $mapLoadEvent = 'aioseo-local-map-load';

	/**
	 * Class constructor.
	 *
	 * @since 1.1.3
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', [ $this, 'initAdmin' ] );
	}

	/**
	 * Init the class by registering our scripts and enqueuing if we're on Gutenberg.
	 *
	 * @since 1.1.3
	 *
	 * @return void
	 */
	public function initAdmin() {
		// Enqueuing from the block rendering does not work in the admin.
		// So we enqueue our scripts here if we're in the block editor for a nice map preview.
		$screen = aioseo()->helpers->getCurrentScreen();
		if ( method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
			$this->enqueues();
		}
	}

	/**
	 * Enqueues needed scripts and styles.
	 *
	 * @since 1.1.3
	 *
	 * @return void
	 */
	public function enqueues() {
		aioseoLocalBusiness()->assets->enqueueCss( 'src/assets/scss/location-map.scss' );
		aioseoLocalBusiness()->assets->enqueueJs( 'src/js-api-loader.js' );
		aioseoLocalBusiness()->assets->enqueueJs( 'src/map.js', [], [
			'apiKey'       => aioseo()->options->localBusiness->maps->apiKey,
			'mapLoadEvent' => $this->mapLoadEvent
		], 'aioseoMapOptions' );
	}

	/**
	 * Adds inline script to start a map.
	 *
	 * @since 1.1.3
	 *
	 * @param  string $data Data to be encoded.
	 * @return void
	 */
	public function mapStartEvent( $data ) {
		$data = wp_json_encode( $data );

		wp_add_inline_script( aioseoLocalBusiness()->assets->jsHandle( 'src/map.js' ), "
			document.dispatchEvent(new CustomEvent('{$this->mapLoadEvent}', {
				detail : $data
			}))
			"
		);
	}

	/**
	 * Adds map information in the Rest API for a Location.
	 *
	 * @since 1.1.3
	 *
	 * @param  object      $object The rest object.
	 * @return object|null         Map information.
	 */
	public function restMapInfo( $object ) {
		$location = aioseoLocalBusiness()->locations->getLocation( $object['id'] );

		if ( empty( $location->maps ) ) {
			return null;
		}

		$location->maps->infoWindowContent = $this->getMarkerInfoWindow( $location );

		return $location->maps;
	}

	/**
	 * Returns the template for the marker's info window.
	 *
	 * @since 1.1.3
	 *
	 * @param  object $locationData The location data.
	 * @return string               Marker's info window template.
	 */
	public function getMarkerInfoWindow( $locationData ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$template = aioseoLocalBusiness()->templates->locateTemplate( 'MapMarkerInfoWindow.php' );

		ob_start();

		require $template;

		return ob_get_clean();
	}
}