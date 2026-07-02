<?php
namespace AIOSEO\Plugin\Addon\NewsSitemap\NewsSitemap;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Determines which content should be included in the sitemap.
 *
 * @since 1.0.0
 */
class Content {
	/**
	 * Returns the entries for the requested sitemap.
	 *
	 * @since 1.0.0
	 *
	 * @return array The sitemap entries.
	 */
	public function get() {
		if ( ! aioseo()->sitemap->content->isEnabled() ) {
			return [];
		}

		// Check if requested sitemap has a dedicated method.
		if ( ! method_exists( $this, aioseo()->sitemap->type ) ) {
			return [];
		}

		return $this->{aioseo()->sitemap->type}();
	}

	/**
	 * Returns the total entries number for the requested sitemap.
	 *
	 * @since 1.0.5
	 *
	 * @return int The total entries number.
	 */
	public function getTotal() {
		if ( ! aioseo()->sitemap->content->isEnabled() ) {
			return 0;
		}

		// Check if requested sitemap has a dedicated method.
		if ( method_exists( $this, aioseo()->sitemap->type ) ) {
			return $this->{aioseo()->sitemap->type}( true );
		}

		// Fallback if requested sitemap does not have a dedicated method.
		return 0;
	}

	/**
	 * Returns the Google News sitemap entries.
	 *
	 * @since 1.0.0
	 *
	 * @param  boolean $countOnly Whether should retrieve all results or just the total count.
	 * @return array              The sitemap entries or the post count.
	 */
	private function news( $countOnly = false ) {
		$posts = aioseoNewsSitemap()->query->posts( aioseo()->sitemap->helpers->includedPostTypes(), [
			'count' => $countOnly
		] );

		if ( $countOnly ) {
			return $posts;
		}

		if ( ! $posts ) {
			return [];
		}

		$publicationName = aioseo()->options->sitemap->news->publicationName;
		if ( ! $publicationName ) {
			$publicationName = aioseo()->helpers->decodeHtmlEntities( get_option( 'blogname' ) );
		}
		$locale = $this->publicationLanguage();
		// $defaultGenre    = aioseo()->options->sitemap->news->defaultGenre;

		$entries = [];
		foreach ( $posts as $post ) {
			// @TODO: [V4+] Add support for genre and stock ticker.
			// $genre       = get_post_meta( $post->ID, 'aioseo_news_genre', true );
			// $stockTicker = get_post_meta( $post->ID, 'aioseo_news_stock_ticker', true );

			$entries[] = [
				'loc'             => get_permalink( $post->ID ),
				'publication'     => [
					'name'     => $publicationName,
					'language' => $locale,
				],
				'publicationDate' => aioseo()->helpers->dateTimeToIso8601( $post->post_date_gmt ),
				'title'           => $post->post_title,
				//'genres'          => $genre ? $genre : $defaultGenre,
				//'stockTicker'     => $stockTicker ? $stockTicker : ''
			];
		}

		return $entries;
	}

	/**
	 * Returns the language code for the site in ISO 639-1 format.
	 *
	 * @since 1.0.0
	 *
	 * @return string The language code in ISO 639-1 format.
	 */
	private function publicationLanguage() {
		$locale = get_locale();

		if ( strlen( $locale ) < 2 ) {
			$locale = 'en';

			return $locale;
		}

		// These are two exceptions as stated on https://support.google.com/news/publisher-center/answer/9606710.
		if ( 'zh_CN' === $locale ) {
			return 'zh-cn';
		}

		if ( 'zh_TW' === $locale ) {
			return 'zh-tw';
		}

		return substr( $locale, 0, 2 );
	}
}