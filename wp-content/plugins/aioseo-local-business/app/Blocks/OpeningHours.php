<?php
namespace AIOSEO\Plugin\Addon\LocalBusiness\Blocks;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Opening Hours block class.
 *
 * @since 1.1.0
 */
class OpeningHours {
	/**
	 * Class constructor.
	 *
	 * @since 1.1.0
	 */
	public function __construct() {
		add_action( 'init', [ $this, 'register' ] );
	}

	/**
	 * Register block type.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function register() {
		aioseo()->blocks->registerBlock(
			'openinghours', [
				'attributes'      => [
					'locationId'    => [
						'type'    => 'number',
						'default' => null,
					],
					'layout'        => [
						'type'    => 'string',
						'default' => 'classic',
					],
					'showTitle'     => [
						'type'    => 'boolean',
						'default' => true,
					],
					'showIcons'     => [
						'type'    => 'boolean',
						'default' => true,
					],
					'showMonday'    => [
						'type'    => 'boolean',
						'default' => true,
					],
					'showTuesday'   => [
						'type'    => 'boolean',
						'default' => true,
					],
					'showWednesday' => [
						'type'    => 'boolean',
						'default' => true,
					],
					'showThursday'  => [
						'type'    => 'boolean',
						'default' => true,
					],
					'showFriday'    => [
						'type'    => 'boolean',
						'default' => true,
					],
					'showSaturday'  => [
						'type'    => 'boolean',
						'default' => true,
					],
					'showSunday'    => [
						'type'    => 'boolean',
						'default' => true,
					],
					'label'         => [
						'type'    => 'string',
						'default' => __( 'Our Opening Hours:', 'aioseo-local-business' ),
					],
					'dataObject'    => [
						'type'    => 'string',
						'default' => null
					],
					'updated'       => [
						'type'    => 'string',
						'default' => time()
					]
				],
				'render_callback' => [ $this, 'render' ],
				'style'           => aioseoLocalBusiness()->assets->cssHandle( 'src/assets/scss/opening-hours.scss' )
			]
		);
	}

	/**
	 * Render block callback.
	 *
	 * @since 1.1.0
	 *
	 * @param  array       $blockAttributes The block attributes.
	 * @return string|void                  The output from the output buffering.
	 */
	public function render( $blockAttributes ) {
		$locationId = ! empty( $blockAttributes['locationId'] ) ? $blockAttributes['locationId'] : '';

		if ( $locationId ) {
			$location = aioseoLocalBusiness()->locations->getLocation( $locationId );
			if ( ! $location ) {
				return sprintf(
					// Translators: 1 - Local Business Post Type Label.
					__( 'Please fill in your Opening Hours for this %1$s.', 'aioseo-local-business' ),
					aioseoLocalBusiness()->postType->getSingleLabel()
				);
			}
		}

		ob_start();

		aioseoLocalBusiness()->locations->outputOpeningHours( $locationId, $blockAttributes );

		return ob_get_clean();
	}
}