<?php
namespace AIOSEO\Plugin\Addon\Redirects\ImportExport;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Models;

/**
 * Handles the importing/exporting of settings and SEO data.
 *
 * @since 1.0.0
 */
class ImportExport {
	/**
	 * Set up an array of plugins for importing.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	private $plugins = [];

	/**
	 * The seoPress class instance.
	 *
	 * @since 1.2.6
	 *
	 * @var SeoPress
	 */
	public $seoPress = null;

	/**
	 * The rankMath class instance.
	 *
	 * @since 1.4.11
	 *
	 * @var RankMath
	 */
	public $rankMath = null;

	/**
	 * Class constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		new Redirection( $this );
		new YoastSeo( $this );
		new Simple301Redirects( $this );
		new SafeRedirectManager( $this );
		new Redirects301Pro( $this );
		new Redirects301( $this );
		new PageLinksTo( $this );
		$this->seoPress = new SeoPress( $this );
		$this->rankMath = new RankMath( $this );
	}

	/**
	 * Starts an import.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $plugin The slug of the plugin to import.
	 * @return void
	 */
	public function startImport( $plugin ) {
		foreach ( $this->plugins as $pluginData ) {
			if ( $pluginData['slug'] === $plugin ) {
				$pluginData['class']->doImport();

				return;
			}
		}
	}

	/**
	 * Adds plugins to the import/export.
	 *
	 * @since 1.0.0
	 *
	 * @param  array $plugins The plugins to add.
	 * @return void
	 */
	public function addPlugins( $plugins ) {
		$this->plugins = array_merge( $this->plugins, $plugins );
	}

	/**
	 * Get the plugins we allow importing from.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function plugins() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugins          = [];
		$installedPlugins = array_keys( get_plugins() );
		foreach ( $this->plugins as $importerPlugin ) {
			$data = [
				'slug'      => $importerPlugin['slug'],
				'name'      => $importerPlugin['name'],
				'version'   => null,
				'canImport' => false,
				'basename'  => $importerPlugin['basename'],
				'installed' => false
			];

			if ( in_array( $importerPlugin['basename'], $installedPlugins, true ) ) {
				$pluginData = get_file_data( trailingslashit( WP_PLUGIN_DIR ) . $importerPlugin['basename'], [
					'name'    => 'Plugin Name',
					'version' => 'Version',
				] );

				$canImport = false;
				if ( version_compare( $importerPlugin['version'], $pluginData['version'], '<=' ) ) {
					$canImport = true;
				}

				$data['name']      = $pluginData['name'];
				$data['version']   = $pluginData['version'];
				$data['canImport'] = $canImport;
				$data['installed'] = true;
			}

			$plugins[] = $data;
		}

		return $plugins;
	}
}