<?php
namespace AIOSEO\Plugin\Addon\LocalBusiness\Utils;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Traits;

/**
 * Class that holds all internal options for AIOSEO.
 *
 * @since 1.1.0.2
 */
class InternalOptions {
	use Traits\Options;

	/**
	 * All the default options.
	 *
	 * @since 1.1.0.2
	 *
	 * @var array
	 */
	protected $defaults = [
		// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		'internal' => [
			'lastActiveVersion' => [ 'type' => 'string', 'default' => '0.0' ]
		]
		// phpcs:enable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	];

	/**
	 * Holds a list of all the possible deprecated options.
	 *
	 * @since 1.2.12
	 *
	 * @var array
	 */
	protected $allDeprecatedOptions = [];

	/**
	 * The Construct method.
	 *
	 * @since 1.1.0.2
	 *
	 * @param string $optionsName An array of options.
	 */
	public function __construct( $optionsName = 'aioseo_local_seo_options_internal' ) {
		$this->optionsName = $optionsName;

		$this->init();

		add_action( 'shutdown', [ $this, 'save' ] );
	}

	/**
	 * Initializes the options.
	 *
	 * @since 1.1.0.2
	 *
	 * @return void
	 */
	protected function init() {
		// Options from the DB.
		$dbOptions = json_decode( get_option( $this->optionsName ), true );
		if ( empty( $dbOptions ) ) {
			$dbOptions = [];
		}

		// Refactor options.
		$this->defaultsMerged = array_replace_recursive( $this->defaults, $this->defaultsMerged );

		$options = array_replace_recursive(
			$this->defaultsMerged,
			$this->addValueToValuesArray( $this->defaultsMerged, $dbOptions )
		);

		aioseo()->core->optionsCache->setOptions( $this->optionsName, apply_filters( 'aioseo_get_local_seo_options_internal', $options ) );
	}
}