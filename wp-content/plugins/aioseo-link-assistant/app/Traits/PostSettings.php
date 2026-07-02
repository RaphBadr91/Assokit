<?php
namespace AIOSEO\Plugin\Addon\LinkAssistant\Traits;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Addon\LinkAssistant\Models;

/**
 * Handles all data Vue needs for the Link Assistant metabox.
 *
 * @since 1.0.0
 */
trait PostSettings {
	/**
	 * Returns the data for the metabox.
	 *
	 * @since 1.0.0
	 *
	 * @param  array $data   The data.
	 * @paramm int   $postId The post ID.
	 * @return array         The modified data.
	 */
	public function getPostData( $data = [], $postId = 0 ) {
		$postId = $postId ? $postId : get_the_ID();
		if ( ! $postId ) {
			return $data;
		}

		// We don't need to perform the queries on Page Builders since Link Assistant is disabled there.
		if ( ! empty( $data['integration'] ) ) {
			return $data;
		}

		$data['linkAssistant'] = [
			'options'         => aioseoLinkAssistant()->options->all(),
			'internalOptions' => aioseoLinkAssistant()->internalOptions->all()
		];

		$data['currentPost']['linkAssistant'] = [
			'isPostEditor'   => true,
			'isExcludedPost' => $this->isExcludedPost( $postId ),
			'links'          => $this->getPostLinks( $postId, null )
		];

		return $data;
	}
}