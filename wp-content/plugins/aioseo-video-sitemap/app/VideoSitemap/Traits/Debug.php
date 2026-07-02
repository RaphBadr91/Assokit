<?php
namespace AIOSEO\Plugin\Addon\VideoSitemap\VideoSitemap\Traits;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contains helper methods for the Debug panel.
 *
 * @since 1.1.5
 */
trait Debug {
	/**
	 * Executes a given administrative task.
	 *
	 * @since 1.1.5
	 *
	 * @param  string $action The action name.
	 * @return bool           Whether an action was found and executed.
	 */
	public function doTask( $action ) {
		$actionFound = true;
		switch ( $action ) {
			case 'clear-video-data':
				aioseoVideoSitemap()->query->resetVideos();
				break;
			default:
				$actionFound = false;
				break;
		}

		return $actionFound;
	}
}