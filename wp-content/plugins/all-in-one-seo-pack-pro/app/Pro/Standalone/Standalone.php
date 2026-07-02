<?php
namespace AIOSEO\Plugin\Pro\Standalone;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Standalone as CommonStandalone;

/**
 * Registers the standalone components.
 *
 * @since 4.9.0
 */
class Standalone extends CommonStandalone\Standalone {
	/**
	 * Class constructor.
	 *
	 * @since 4.9.0
	 */
	public function __construct() {
		parent::__construct();

		$this->standaloneBlocks['recipeBlock']  = new Blocks\Recipe();
		$this->standaloneBlocks['productBlock'] = new Blocks\Product();
	}
}