<?php
namespace AIOSEO\Plugin\Addon\LocalBusiness\Blocks;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Map block class.
 *
 * @since 1.1.3
 */
class Map {
	/**
	 * Class constructor.
	 *
	 * @since 1.1.3
	 */
	public function __construct() {
		add_action( 'init', [ $this, 'register' ] );
	}

	/**
	 * Registers the block.
	 *
	 * @since 1.3.8
	 *
	 * @return void
	 */
	public function register() {
		aioseo()->blocks->registerBlock(
			'locationmap', [
				'attributes'      => [
					'locationId'   => [
						'type'    => 'number',
						'default' => null
					],
					'showLabel'    => [
						'type'    => 'boolean',
						'default' => true
					],
					'showIcon'     => [
						'type'    => 'boolean',
						'default' => true
					],
					'customMarker' => [
						'type'    => 'string',
						'default' => null
					],
					'width'        => [
						'type'    => 'string',
						'default' => '100%'
					],
					'height'       => [
						'type'    => 'string',
						'default' => '450px'
					],
					'label'        => [
						'type'    => 'string',
						'default' => __( 'Our location:', 'aioseo-local-business' ),
					],
					'dataObject'   => [
						'type'    => 'string',
						'default' => null
					],
					'updated'      => [
						'type'    => 'string',
						'default' => time()
					]
				],
				'render_callback' => [ $this, 'render' ],
				'style'           => aioseoLocalBusiness()->assets->cssHandle( 'src/assets/scss/location-map.scss' )
			]
		);
	}

	/**
	 * Renders the block.
	 *
	 * @since 1.1.3
	 *
	 * @param  array  $blockAttributes The block attributes.
	 * @return string                  The output from the output buffering.
	 */
	public function render( $blockAttributes ) {
		$locationId = ! empty( $blockAttributes['locationId'] ) ? $blockAttributes['locationId'] : '';

		if ( $locationId ) {
			$location = aioseoLocalBusiness()->locations->getLocation( $locationId );
			if ( ! $location ) {
				return sprintf(
					// Translators: 1 - Local Business Post Type Label.
					__( 'Please fill in your Business Info for this %1$s.', 'aioseo-local-business' ),
					aioseoLocalBusiness()->postType->getSingleLabel()
				);
			}
		}

		ob_start();

		aioseoLocalBusiness()->locations->outputLocationMap( $locationId, $blockAttributes );

		return ob_get_clean();
	}
}