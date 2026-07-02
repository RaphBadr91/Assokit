<?php
namespace AIOSEO\Plugin\Addon\Redirects\ImportExport;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Addon\Redirects\Utils;

/**
 * Imports the Redirection from Page Links To.
 *
 * @since 1.1.7
 */
class PageLinksTo extends Importer {
	/**
	 * A list of plugins to look for to import.
	 *
	 * @since 1.1.7
	 *
	 * @var array
	 */
	public $plugins = [
		[
			'name'     => 'Page Links To',
			'version'  => '3.3.6',
			'basename' => 'page-links-to/page-links-to.php',
			'slug'     => 'page-links-to'
		]
	];

	/**
	 * Import.
	 *
	 * @since 1.1.7
	 *
	 * @return void
	 */
	public function doImport() {
		$linksTo = $this->getLinksTo();

		if ( empty( $linksTo ) ) {
			return;
		}

		// Remove all filters running in the permalinks.
		if ( class_exists( 'CWS_PageLinksTo' ) ) {
			$instance = \CWS_PageLinksTo::get_instance();
			remove_action( 'post_link', [ $instance, 'link' ], 20 );
			remove_action( 'page_link', [ $instance, 'link' ], 20 );
			remove_action( 'post_type_link', [ $instance, 'link' ], 20 );
		}

		foreach ( $linksTo as $linkTo ) {
			// Url from as a relative path.
			$urlFrom = $this->leadingSlashIt( str_replace( get_home_url(), '', get_permalink( $linkTo->ID ) ) );
			$urlTo   = 0 === strpos( $linkTo->url, 'http' ) || '/' === $linkTo->url ? $linkTo->url : $this->leadingSlashIt( $linkTo->url );
			if ( empty( $urlTo ) ) {
				$urlTo = '/';
			}

			$this->importRule( [
				'source_url'   => $urlFrom,
				'target_url'   => $urlTo,
				'type'         => 301,
				'group'        => 'manual',
				'regex'        => false,
				'ignore_slash' => aioseoRedirects()->options->redirectDefaults->ignoreSlash,
				'ignore_case'  => aioseoRedirects()->options->redirectDefaults->ignoreCase,
				'enabled'      => true
			] );
		}
	}

	/**
	 * Get Links To.
	 *
	 * @since 1.1.7
	 *
	 * @return array Array of links.
	 */
	private function getLinksTo() {
		$linksTo = aioseo()->core->db->start( 'postmeta' )
							->select( 'post_id as ID, meta_value as url' )
							->where( 'meta_key', '_links_to' )
							->whereRaw( 'meta_value != ""' )
							->run()
							->result();

		// Filter valid posts.
		foreach ( $linksTo as $key => $p ) {
			if ( ! aioseo()->helpers->isValidPost( $p->ID ) ) {
				unset( $linksTo[ $key ] );
			}
		}

		return $linksTo;
	}
}