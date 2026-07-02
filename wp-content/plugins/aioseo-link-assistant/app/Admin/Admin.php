<?php
namespace AIOSEO\Plugin\Addon\LinkAssistant\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Addon\LinkAssistant\Models;

/**
 * Handles all admin logic.
 *
 * @since 1.0.0
 */
class Admin {
	/**
	 * Renders data for a column in the admin.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $columnName  The column name.
	 * @param  int    $postId      The post ID.
	 * @param  array  $currentData The current column data.
	 * @return array               An array of associative data to be merged.
	 */
	public function renderColumnData( $columnName, $postId, $currentData = [] ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		if ( 'aioseo-details' !== $columnName || ! current_user_can( 'aioseo_page_link_assistant_settings' ) ) {
			return [];
		}

		$totalLinks = Models\Link::getLinkTotals( $postId );
		if ( empty( $totalLinks ) ) {
			return [
				'linkCounts' => [
					'inboundInternal'  => 0,
					'outboundInternal' => 0,
					'affiliate'        => 0,
					'external'         => 0
				]
			];
		}

		return [
			'linkCounts' => [
				'inboundInternal'  => (int) $totalLinks->inboundInternal,
				'outboundInternal' => (int) $totalLinks->outboundInternal,
				'affiliate'        => (int) $totalLinks->affiliate,
				'external'         => (int) $totalLinks->external
			]
		];
	}
}