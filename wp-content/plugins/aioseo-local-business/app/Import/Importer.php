<?php
namespace AIOSEO\Plugin\Addon\LocalBusiness\Import;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports local business info from other plugins.
 *
 * @since 1.3.0
 */
class Importer extends Helpers {
	/**
	 * The list of plugins.
	 *
	 * @since 1.3.0
	 *
	 * @var array
	 */
	protected $plugins = [];

	/**
	 * The post action name.
	 *
	 * @since 1.3.0
	 *
	 * @param Import $importer The main importer class.
	 */
	public function __construct( $importer ) {
		parent::__construct();

		$plugins = $this->plugins;
		foreach ( $plugins as $key => $plugin ) {
			$plugins[ $key ]['class'] = $this;
		}
		$importer->addPlugins( $plugins );
	}
}