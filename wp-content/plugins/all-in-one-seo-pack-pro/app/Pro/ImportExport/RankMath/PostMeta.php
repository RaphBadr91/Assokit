<?php
namespace AIOSEO\Plugin\Pro\ImportExport\RankMath;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\ImportExport\RankMath as CommonRankMath;

/**
 * Imports the post meta from RankMath SEO.
 *
 * @since 4.8.3
 */
class PostMeta extends CommonRankMath\PostMeta {
	/**
	 * Imports the post meta.
	 *
	 * @since 4.8.3
	 *
	 * @return array The posts that were imported.
	 */
	public function importPostMeta() {
		$posts = parent::importPostMeta();
		if ( ! function_exists( 'aioseoRedirects' ) || empty( $posts ) ) {
			return [];
		}

		foreach ( $posts as $post ) {
			aioseoRedirects()->importExport->rankMath->importPostRedirect( $post->ID );
		}

		return $posts;
	}
}