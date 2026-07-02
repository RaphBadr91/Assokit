<?php
namespace AIOSEO\Plugin\Addon\LocalBusiness\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Description
 *
 * @since 1.2.10
 */
class Usage {
	/**
	 * Retrieves the data to send in the usage tracking.
	 *
	 * @since 1.2.10
	 *
	 * @return array An array of data to send.
	 */
	public function getData() {
		return [
			'internalOptions' => aioseoLocalBusiness()->internalOptions->all()
		];
	}
}