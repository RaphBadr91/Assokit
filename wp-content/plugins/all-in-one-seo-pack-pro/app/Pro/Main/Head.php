<?php
namespace AIOSEO\Plugin\Pro\Main;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Main as CommonMain;
use AIOSEO\Plugin\Pro\Meta;

/**
 * Handles our ouput in the document head.
 *
 * @since 4.0.0
 */
class Head extends CommonMain\Head {
	/**
	 * GoogleAnalytics class instance.
	 *
	 * @since 4.5.1
	 *
	 * @var GoogleAnalytics
	 */
	protected $analytics = null;

	/**
	 * Class constructor.
	 *
	 * @since 4.0.0
	 */
	public function __construct() {
		parent::__construct();

		$this->analytics = new GoogleAnalytics();
		$this->keywords  = new Meta\Keywords();

		new Meta\Included();

		$this->views['analytics'] = AIOSEO_DIR . '/app/Pro/Views/main/analytics.php';
	}
}