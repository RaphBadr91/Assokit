<?php
namespace AIOSEO\Plugin\Addon\LocalBusiness\Blocks;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The BusinessInfo block class.
 *
 * @since 1.1.0
 */
class BusinessInfo {
	/**
	 * Class constructor.
	 *
	 * @since 1.1.0
	 */
	public function __construct() {
		add_action( 'init', [ $this, 'register' ] );
	}

	/**
	 * Registers the block.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function register() {
		aioseo()->blocks->registerBlock(
			'businessinfo', [
				'attributes'      => [
					'locationId'      => [
						'type'    => 'number',
						'default' => null,
					],
					'showLabels'      => [
						'type'    => 'boolean',
						'default' => true,
					],
					'addressLabel'    => [
						'type'    => 'string',
						'default' => __( 'Address:', 'aioseo-local-business' ),
					],
					'vatIdLabel'      => [
						'type'    => 'string',
						'default' => __( 'VAT ID:', 'aioseo-local-business' ),
					],
					'taxIdLabel'      => [
						'type'    => 'string',
						'default' => __( 'Tax ID:', 'aioseo-local-business' ),
					],
					'phoneLabel'      => [
						'type'    => 'string',
						'default' => __( 'Phone:', 'aioseo-local-business' ),
					],
					'faxLabel'        => [
						'type'    => 'string',
						'default' => __( 'Fax:', 'aioseo-local-business' ),
					],
					'emailLabel'      => [
						'type'    => 'string',
						'default' => __( 'Email:', 'aioseo-local-business' ),
					],
					'showIcons'       => [
						'type'    => 'boolean',
						'default' => true,
					],
					'showName'        => [
						'type'    => 'boolean',
						'default' => true,
					],
					'showAddress'     => [
						'type'    => 'boolean',
						'default' => true,
					],
					'showPhone'       => [
						'type'    => 'boolean',
						'default' => true,
					],
					'showFax'         => [
						'type'    => 'boolean',
						'default' => true,
					],
					'showCountryCode' => [
						'type'    => 'boolean',
						'default' => true,
					],
					'showEmail'       => [
						'type'    => 'boolean',
						'default' => true,
					],
					'showVat'         => [
						'type'    => 'boolean',
						'default' => true,
					],
					'showTax'         => [
						'type'    => 'boolean',
						'default' => true,
					],
					'showChamberId'   => [
						'type'    => 'boolean',
						'default' => false,
					],
					'dataObject'      => [
						'type'    => 'string',
						'default' => null
					],
					'updated'         => [
						'type'    => 'string',
						'default' => time()
					]
				],
				'render_callback' => [ $this, 'render' ],
				'style'           => aioseoLocalBusiness()->assets->cssHandle( 'src/assets/scss/business-info.scss' )
			]
		);
	}

	/**
	 * Renders the block.
	 *
	 * @since 1.1.0
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

		aioseoLocalBusiness()->locations->outputBusinessInfo( $locationId, $blockAttributes );

		return ob_get_clean();
	}
}