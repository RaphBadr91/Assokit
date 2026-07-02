<?php
namespace AIOSEO\Plugin\Pro\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Admin as CommonAdmin;

/**
 * Checks for conflicting plugins.
 *
 * @since 4.0.0
 */
class ConflictingPlugins extends CommonAdmin\ConflictingPlugins {
	/**
	 * Class constructor.
	 *
	 * @since 4.0.0
	 */
	public function __construct() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'init', [ $this, 'init' ] );
	}

	/**
	 * Initialize the conflicting plugins check.
	 *
	 * @since 4.5.5
	 *
	 * @return void
	 */
	public function init() {
		// Check if redirects is active.
		$redirects = aioseo()->addons->getAddon( 'aioseo-redirects' );
		if ( ! empty( $redirects ) && $redirects->isActive ) {
			$this->conflictingPluginSlugs = array_merge( $this->conflictingPluginSlugs, [
				'redirection',
				'eps-301-redirects',
				'simple-301-redirects',
				'301-redirects',
				'404-to-homepage',
				'quick-301-redirects',
				'all-404-redirect-to-homepage',
				'redirect-redirection',
				'safe-redirect-manager'
			] );
		}

		parent::init();
	}

	/**
	 * Get a list of all conflicting plugins.
	 *
	 * @since 4.0.0
	 *
	 * @return array An array of conflicting plugins.
	 */
	public function getAllConflictingPlugins() {
		$conflictingPlugins        = parent::getAllConflictingPlugins();
		$conflictingSitemapPlugins = [];

		$canCheck     = false;
		$videoSitemap = aioseo()->addons->getAddon( 'aioseo-video-sitemap' );
		$newsSitemap  = aioseo()->addons->getAddon( 'aioseo-news-sitemap' );

		if ( ! empty( $videoSitemap ) && $videoSitemap->isActive && aioseo()->options->sitemap->video->enable ) {
			$canCheck = true;
		}

		if ( ! empty( $newsSitemap ) && $newsSitemap->isActive && aioseo()->options->sitemap->news->enable ) {
			$canCheck = true;
		}

		if ( $canCheck ) {
			$conflictingSitemapPlugins = $this->getConflictingPlugins( 'sitemap' );
		}

		return array_merge( $conflictingPlugins, $conflictingSitemapPlugins );
	}
}