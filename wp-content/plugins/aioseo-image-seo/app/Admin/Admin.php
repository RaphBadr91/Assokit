<?php
namespace AIOSEO\Plugin\Addon\ImageSeo\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds our Image SEO columns to the Media Library.
 *
 * @since 1.0.0
 */
class Admin {
	/**
	 * Renders data for a column in the admin.
	 *
	 * @since 1.0.5
	 *
	 * @param  string $columnName  The column name.
	 * @param  int    $postId      The current rows, post id.
	 * @param  array  $currentData The current column data.
	 * @return array               An array of associative data to be merged.
	 */
	public function renderColumnData( $columnName, $postId, $currentData = [] ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$mimeType = get_post_mime_type( $postId );

		// TODO: Fix the permissions here.
		if ( ( ! current_user_can( 'edit_post', $postId ) && ! current_user_can( 'aioseo_manage_seo' ) ) || 0 !== strpos( $mimeType, 'image/' ) || 'aioseo-details' !== $columnName ) {
			return [];
		}

		return [
			'imageTitle'  => get_the_title( $postId ),
			'imageAltTag' => get_post_meta( $postId, '_wp_attachment_image_alt', true ),
			'showMedia'   => true
		];
	}
}