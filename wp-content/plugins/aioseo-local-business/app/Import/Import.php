<?php
namespace AIOSEO\Plugin\Addon\LocalBusiness\Import;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the importing/exporting of settings and SEO data.
 *
 * @since 1.3.0
 */
class Import {
	/**
	 * Set up an array of plugins for importing.
	 *
	 * @since 1.3.0
	 *
	 * @var array
	 */
	private $plugins = [];

	/**
	 * Class constructor.
	 *
	 * @since 1.3.0
	 */
	public function __construct() {
		new Plugins\YoastSeo( $this );
		new Plugins\SeoPress( $this );
		new Plugins\RankMath( $this );
	}

	/**
	 * Starts an import.
	 *
	 * @since 1.3.0
	 *
	 * @param  string $plugin The slug of the plugin to import.
	 * @return void
	 */
	public function startImport( $plugin ) {
		foreach ( $this->plugins as $pluginData ) {
			if ( $pluginData['slug'] === $plugin ) {
				$pluginData['class']->doImport();
				do_action( 'aioseo_local_seo_imported', $plugin );

				return;
			}
		}
	}

	/**
	 * Adds plugins to the import.
	 *
	 * @since 1.3.0
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
	 * @since 1.3.0
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
				'installed' => false,
				'activated' => false
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
				$data['activated'] = is_plugin_active( $importerPlugin['basename'] );
			}

			$plugins[] = $data;
		}

		return $plugins;
	}
}