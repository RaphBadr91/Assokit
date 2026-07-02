<?php
namespace AIOSEO\Plugin\Addon\LinkAssistant\Traits;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Addon\LinkAssistant\Models;

/**
 * Contains helper methods for the Debug panel.
 *
 * @since 1.0.0
 */
trait Debug {
	/**
	 * Executes a given administrative task.
	 *
	 * @since 1.0.8
	 *
	 * @param  string $action The action name.
	 * @return bool           Whether an action was found and executed.
	 */
	public function doTask( $action ) {
		$actionFound = true;
		switch ( $action ) {
			case 'link-assistant-clear-data':
				$this->resetData();
				break;
			case 'link-assistant-clear-links':
				$this->resetLinks();
				break;
			case 'link-assistant-clear-suggestions':
				$this->resetSuggestions();
				break;
			case 'link-assistant-undismiss-suggestions':
				$this->undismissSuggestions();
				break;
			default:
				$actionFound = false;
				break;
		}

		return $actionFound;
	}

	/**
	 * Resets all Link Assistant data, except for the settings.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function resetData() {
		$this->resetLinks();
		$this->resetSuggestions();
	}

	/**
	 * Resets all link data and forces the site to be rescanned.
	 *
	 * @since 1.0.8
	 *
	 * @return void
	 */
	private function resetLinks() {
		aioseo()->core->db->update( 'aioseo_posts' )
			->set( [ 'link_scan_date' => null ] )
			->run();

		aioseo()->core->db->truncate( 'aioseo_links ' )->run();
	}

	/**
	 * Resets all suggestion data and forces the site to be rescanned.
	 *
	 * @since 1.0.8
	 *
	 * @return void
	 */
	private function resetSuggestions() {
		aioseo()->core->db->update( 'aioseo_posts' )
			->set( [ 'link_suggestions_scan_date' => null ] )
			->run();

		aioseo()->core->db->truncate( 'aioseo_links_suggestions' )->run();
	}

	/**
	 * Undismisses all suggestions.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function undismissSuggestions() {
		aioseo()->core->db->update( 'aioseo_links_suggestions' )
		->set( [ 'dismissed' => 0 ] )
		->run();
	}
}