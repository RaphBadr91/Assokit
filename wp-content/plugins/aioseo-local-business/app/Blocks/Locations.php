<?php
namespace AIOSEO\Plugin\Addon\LocalBusiness\Blocks;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Locations block class.
 *
 * @since 1.1.0
 */
class Locations {
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
			'locations', [
				'attributes'      => [
					'categoryId' => [
						'type'    => 'number',
						'default' => 0,
					],
				],
				'render_callback' => [ $this, 'render' ],
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
		if ( empty( $blockAttributes['categoryId'] ) || ! is_numeric( $blockAttributes['categoryId'] ) ) {
			return;
		}

		ob_start();

		aioseoLocalBusiness()->locations->outputLocationCategory( absint( $blockAttributes['categoryId'] ), $blockAttributes );

		return ob_get_clean();
	}
}