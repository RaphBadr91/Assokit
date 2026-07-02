<?php

namespace AIOSEO\Plugin\Pro\SeoAnalysis\Checkers\Post;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Pro\SeoAnalysis\PageParser;
use AIOSEO\Plugin\Common\Models\Post;
use AIOSEO\Plugin\Pro\SeoAnalysis\Checkers\CheckersAbstract;
use AIOSEO\Plugin\Pro\SeoAnalysis\Checkers\CheckerResult;
use AIOSEO\Plugin\Pro\Social\Facebook;
use AIOSEO\Plugin\Pro\Social\Twitter;

/**
 * Handles the SEO validation for Advanced issues.
 *
 * @since 4.8.6
 */
class Advanced extends CheckersAbstract {
	/**
	 * Class constructor
	 *
	 * @since 4.8.6
	 *
	 * @param int        $objectId The object ID.
	 * @param PageParser $parser   The page parser instance.
	 */
	public function __construct( $objectId, $parser ) {
		parent::__construct([
			'id'      => $objectId,
			'type'    => 'post',
			'subtype' => get_post_type( $objectId ),
		], $parser);
	}

	/**
	 * Analyze the page for Advanced SEO issues.
	 *
	 * @since 4.8.6
	 *
	 * @return array List of checkers/issues.
	 */
	public function get() {
		$result = [
			'noindex'      => $this->analyzeNoIndex(),
			'openGraph'    => $this->analyzeOpenGraph(),
			'schema'       => $this->analyzeSchema(),
			'canonicalTag' => $this->analyzeCanonicalTag(),
			'authorBio'    => $this->analyzeAuthorHasBio(), // only if EEAT is active and page supports author
			'mainKeyword'  => $this->analyzeHasMainKeyword(), // only if is not a woocommerce page, not the posts page and is TruSEO eligible
			'staleContent' => $this->analyzeStaleContent(),
		];

		return array_filter( $result, function( $check ) {
			return ! is_null( $check );
		} );
	}

	/**
	 * Get the robots rules from the page.
	 *
	 * @since 4.8.6
	 *
	 * @return array The robots rules eg('noindex', 'nofollow')
	 */
	private function getRobotsRules() {
		$rules = $this->pageParser->getMetaTag( 'robots' );

		if ( empty( $rules ) ) {
			return [];
		}

		$result = explode( ',', $rules[0] );
		$result = array_map( function( $item ) {
			return trim( $item );
		}, $result );

		return $result;
	}

	/**
	 * Analyze the page for noindex meta tag.
	 *
	 * @since 4.8.6
	 *
	 * @return CheckerResult
	 */
	private function analyzeNoIndex() {
		if ( in_array( 'noindex', $this->getRobotsRules(), true ) ) {
			return new CheckerResult( 'noindex' );
		}

		return new CheckerResult( 'noindex-ok' );
	}

	/**
	 * Analyze the page for Open Graph issues.
	 *
	 * @since 4.8.6
	 *
	 * @return CheckerResult
	 */
	private function analyzeOpenGraph() {
		$openGraphTags = $this->pageParser->getOpenGraphTags();
		$required      = [
			'og:title'       => 0,
			'og:type'        => 0,
			'og:image'       => 0,
			'og:url'         => 0,
			'og:description' => 0
		];

		if ( ! $this->pageParser->isScraping ) {
			$post = Post::getPost( $this->object['id'] );

			$facebook = [
				'title'       => aioseo()->tags->replaceTags(
					aioseo()->helpers->encodeOutputHtml( aioseo()->social->facebook->getTitle( $this->object['id'] ) ),
					$this->object['id']
				),
				'description' => aioseo()->tags->replaceTags(
					aioseo()->helpers->encodeOutputHtml( aioseo()->social->facebook->getDescription( $this->object['id'] ) ),
					$this->object['id']
				),
				'image'       => aioseo()->social->facebook->getImage( $this->object['id'] ),
			];

			$twitter = [
				'title'       => aioseo()->tags->replaceTags(
					aioseo()->helpers->encodeOutputHtml( aioseo()->social->twitter->getTitle( $this->object['id'] ) ),
					$this->object['id']
				),
				'description' => aioseo()->tags->replaceTags(
					aioseo()->helpers->encodeOutputHtml( aioseo()->social->twitter->getDescription( $this->object['id'] ) ),
					$this->object['id']
				),
				'image'       => aioseo()->social->twitter->getImage( $this->object['id'] ),
			];

			$required      = [
				'og:title'       => 0,
				'og:description' => 0,
				'og:image'       => 0,
			];
			$openGraphTags = [
				[
					'key'   => 'og:title',
					'value' => $facebook['title'] ?? $twitter['title'] ?? $post->og_title
				],
				[
					'key'   => 'og:description',
					'value' => $facebook['description'] ?? $twitter['description'] ?? $post->og_description
				],
				[
					'key'   => 'og:image',
					'value' => $facebook['image'] ?? $twitter['image'] ?? $post->og_image_url
				]
			];
		}

		foreach ( $openGraphTags as $tag ) {
			if ( isset( $required[ $tag['key'] ] ) && ! empty( $tag['value'] ) ) {
				$required[ $tag['key'] ] += 1;
			}
		}

		$missingTags = array_filter( $required, function( $item ) {
			return 0 === $item;
		} );

		if ( ! empty( $missingTags ) ) {
			return new CheckerResult( 'ogp-missing', [ 'items' => array_keys( $missingTags ) ] );
		}

		$duplicatedTags = array_filter( $required, function( $item ) {
			return 1 < $item;
		} );

		if ( ! empty( $duplicatedTags ) ) {
			return new CheckerResult( 'ogp-duplicates', [ 'items' => array_keys( $duplicatedTags ) ] );
		}

		return new CheckerResult( 'ogp-ok' );
	}

	/**
	 * Analyze the page for Schema issues.
	 *
	 * @since 4.8.6
	 *
	 * @return CheckerResult
	 */
	private function analyzeSchema() {
		if ( $this->pageParser->isScraping && empty( $this->pageParser->getSchemas() ) ) {
			return new CheckerResult( 'schema-missing' );
		}

		return new CheckerResult( 'schema-ok' );
	}

	/**
	 * Analyze the page for Canonical Tag issues.
	 *
	 * @since 4.8.6
	 *
	 * @return CheckerResult
	 */
	private function analyzeCanonicalTag() {
		$canonicalUrl = $this->pageParser->getCanonicalUrl();

		if ( ! $this->pageParser->isScraping ) {
			$post         = Post::getPost( $this->object['id'] );
			$canonicalUrl = $post->canonical_url;
		}

		if ( empty( $canonicalUrl ) ) {
			return new CheckerResult( 'canonical-missing' );
		}

		return new CheckerResult( 'canonical-ok' );
	}

	/**
	 * Analyze the page for Author Bio.
	 *
	 * @since 4.8.6
	 *
	 * @return CheckerResult|null
	 */
	private function analyzeAuthorHasBio() {
		$object         = $this->object;
		$authorId       = get_post_field( 'post_author', $object['id'] );
		$authorMetaData = function_exists( 'aioseoEeat' ) ? aioseoEeat()->helpers->getAuthorMetaData( $authorId ) : [];

		$isSingular    = 'page' !== $object['subtype'];
		$supportsAuthor = post_type_supports( $object['subtype'], 'author' );

		if (
			! $isSingular ||
			! $supportsAuthor ||
			empty( $authorMetaData ) ||
			( isset( $authorMetaData['enabled'] ) && ! $authorMetaData['enabled'] )
		) {
			return null;
		}

		if ( empty( $authorMetaData['authorBio'] ) ) {
			return new CheckerResult( 'author-bio-missing' );
		}

		return new CheckerResult( 'author-bio-ok' );
	}

	/**
	 * Analyze the page for Main Keyword.
	 *
	 * @since 4.8.6
	 *
	 * @return CheckerResult|null
	 */
	private function analyzeHasMainKeyword() {
		if (
			aioseo()->helpers->isWooCommercePage( $this->object['id'] ) ||
			aioseo()->helpers->isStaticPostsPage( $this->object['id'] ) ||
			! aioseo()->helpers->isTruSeoEligible( $this->object['id'] )
		) {
			return null;
		}

		$object = $this->object;

		$post       = Post::getPost( $object['id'] );
		$keyphrases = Post::getKeyphrasesDefaults( $post->keyphrases );

		if ( empty( $keyphrases->focus->keyphrase ) ) {
			return new CheckerResult( 'main-keyword-missing' );
		}

		return new CheckerResult( 'main-keyword-ok' );
	}

	/**
	 * Analyze the page for Stale Content.
	 *
	 * @since 4.8.6
	 *
	 * @return CheckerResult
	 */
	private function analyzeStaleContent() {
		$post         = get_post( $this->object['id'] );
		$currentTime = current_time( 'mysql' );

		$modifiedDate = new \DateTime( $post->post_modified );
		$currentDate  = new \DateTime( $currentTime );

		// Calculate the difference
		$interval = $currentDate->diff( $modifiedDate );

		if ( 2 <= $interval->y ) {
			return new CheckerResult( 'stale-content-too-old' );
		}

		return new CheckerResult( 'stale-content-ok' );
	}
}