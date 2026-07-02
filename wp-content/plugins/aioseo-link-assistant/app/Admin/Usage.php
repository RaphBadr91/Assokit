<?php
namespace AIOSEO\Plugin\Addon\LinkAssistant\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Description
 *
 * @since 1.1.0
 */
class Usage {
	/**
	 * Retrieves the data to send in the usage tracking.
	 *
	 * @since 1.1.0
	 *
	 * @return array An array of data to send.
	 */
	public function getData() {
		return [
			'options'         => aioseoLinkAssistant()->options->all(),
			'internalOptions' => aioseoLinkAssistant()->internalOptions->all()
		];
	}
}