<?php
namespace AIOSEO\Plugin\Addon\Redirects\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Description
 *
 * @since 1.2.3
 */
class Usage {
	/**
	 * Retrieves the data to send in the usage tracking.
	 *
	 * @since 1.2.3
	 *
	 * @return array An array of data to send.
	 */
	public function getData() {
		return [
			'options'         => aioseoRedirects()->options->all(),
			'internalOptions' => aioseoRedirects()->internalOptions->all()
		];
	}
}