<?php
namespace AIOSEO\Plugin\Addon\IndexNow\Utils;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contains helper functions.
 *
 * @since 1.0.0
 */
class Helpers {
	/**
	 * Gets the data for Vue.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $page The current page.
	 * @return array        The data.
	 */
	public function getVueData( $data = [], $page = null ) {
		if ( 'settings' !== $page ) {
			$data['indexNow'] = [];

			return $data;
		}

		$data['indexNow'] = [
			'options' => aioseoIndexNow()->options->all()
		];

		return $data;
	}

	/**
	 * Generates an API key to use with IndexNow.
	 *
	 * @since 1.0.0
	 *
	 * @return string The API key.
	 */
	public function generateApiKey() {
		$newApiKey = wp_generate_uuid4();
		$newApiKey = preg_replace( '[-]', '', (string) $newApiKey );

		return $newApiKey;
	}
}