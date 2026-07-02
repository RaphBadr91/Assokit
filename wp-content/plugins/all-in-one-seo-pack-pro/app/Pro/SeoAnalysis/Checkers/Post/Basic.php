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

/**
 * Handles the SEO validation for Basic issues.
 *
 * @since 4.8.6
 */
class Basic extends CheckersAbstract {
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
	 * Analyze the page for Basic SEO issues.
	 *
	 * @since 4.8.6
	 *
	 * @return array List of checkers/issues.
	 */
	public function get() {
		$result = [
			'title'                        => $this->analyzeTitle(),
			'description'                  => $this->analyzeDescription(),
			'h1Tags'                       => $this->analyzeH1Tags(), // only for published posts
			'subheadingTags'               => $this->analyzeSubheadingTags(),
			'images'                       => $this->analyzeImgs(),
			'links'                        => $this->analyzeLinksRatio(),
			'hasThumbnail'                 => $this->analyzeThumbnail(),
			'contentLength'                => $this->analyzeContentLength(),
			'keywordCannibalization'       => $this->analyzeHasKeywordCannibalization(), // only if is not a woocommerce page, not the posts page and is TruSEO eligible
			'focusKeywordInFirstParagraph' => $this->analyzeFocusKeywordInFirstParagraph(), // only if is not a woocommerce page, not the posts page and is TruSEO eligible
			'focusKeywordInTitle'          => $this->analyzeFocusKeywordInTitle(), // only if is not a woocommerce page, not the posts page and is TruSEO eligible
			'focusKeywordInDescription'    => $this->analyzeFocusKeywordInDescription(), // only if is not a woocommerce page, not the posts page and is TruSEO eligible
			'focusKeywordInUrl'            => $this->analyzeFocusKeywordInUrl(), // only if is not a woocommerce page, not the posts page and is TruSEO eligible
			'urlLength'                    => $this->analyzeUrlLength(),
			'productSchema'                => $this->analyzeProductSchema(), //only if is a product (WooCommerce) or download (EDD)
		];

		return array_filter( $result, function( $check ) {
			return ! is_null( $check );
		} );
	}

	/**
	 * Analyze the title tag.
	 *
	 * @since 4.8.6
	 *
	 * @return CheckerResult
	 */
	private function analyzeTitle() {
		$titles = $this->pageParser->isScraping
			? $this->pageParser->getElementsByTagName( 'title' )
			: [ aioseo()->meta->title->getPostTitle( $this->object['id'] ) ];

		if ( empty( $titles ) ) {
			return new CheckerResult( 'title-missing' );
		} elseif ( 40 >= mb_strlen( $titles[0] ) ) {
			return new CheckerResult( 'title-too-short', [ 'length' => mb_strlen( $titles[0] ) ] );
		} elseif ( 60 < mb_strlen( $titles[0] ) ) {
			return new CheckerResult( 'title-too-long', [ 'length' => mb_strlen( $titles[0] ) ] );
		}

		return new CheckerResult( 'title-ok', [ 'length' => mb_strlen( $titles[0] ) ] );
	}

	/**
	 * Analyze the description tag.
	 *
	 * @since 4.8.6
	 *
	 * @return CheckerResult
	 */
	private function analyzeDescription() {
		$descriptions = $this->pageParser->isScraping
			? $this->pageParser->getMetaTag( 'description' )
			: [ aioseo()->meta->description->getPostDescription( $this->object['id'] ) ];

		if ( empty( $descriptions ) ) {
			return new CheckerResult( 'description-missing' );
		} elseif ( 120 >= mb_strlen( $descriptions[0] ) ) {
			return new CheckerResult( 'description-too-short', [ 'length' => mb_strlen( $descriptions[0] ) ] );
		} elseif ( 160 < mb_strlen( $descriptions[0] ) ) {
			return new CheckerResult( 'description-too-long', [ 'length' => mb_strlen( $descriptions[0] ) ] );
		}

		return new CheckerResult( 'description-ok', [ 'length' => mb_strlen( $descriptions[0] ) ] );
	}

	/**
	 * Analyze the H1 tags.
	 *
	 * @since 4.8.6
	 *
	 * @return CheckerResult|null
	 */
	private function analyzeH1Tags() {
		// If we're not scrapping means the post is not published yet.
		if ( ! $this->pageParser->isScraping ) {
			return null;
		}

		$tags = $this->pageParser->getElementsByTagName( 'h1' );

		if ( empty( $tags ) ) {
			return new CheckerResult( 'h1-missing' );
		} elseif ( 1 < count( $tags ) ) {
			return new CheckerResult( 'h1-too-many', [ 'length' => count( $tags ) ] );
		} else {
			if (
				! aioseo()->helpers->isWooCommercePage( $this->object['id'] ) &&
				! aioseo()->helpers->isStaticPostsPage( $this->object['id'] ) &&
				aioseo()->helpers->isTruSeoEligible( $this->object['id'] )
			) {
				$post       = Post::getPost( $this->object['id'] );
				$keyphrases = Post::getKeyphrasesDefaults( $post->keyphrases );

				if ( empty( $keyphrases->focus->keyphrase ) ) {
					return null;
				}

				$text      = strtolower( (string) $tags[0] );
				$keyphrase = strtolower( (string) $keyphrases->focus->keyphrase );

				if ( strpos( $text, $keyphrase ) === false ) {
					return new CheckerResult( 'h1-missing-focus-keyword' );
				}
			}
		}

		return new CheckerResult( 'h1-ok' );
	}

	/**
	 * Analyze the subheading tags.
	 *
	 * @since 4.8.6
	 *
	 * @return CheckerResult
	 */
	private function analyzeSubheadingTags() {
		$h2   = $this->pageParser->getElementsByTagName( 'h2' );
		$h3   = $this->pageParser->getElementsByTagName( 'h3' );
		$h4   = $this->pageParser->getElementsByTagName( 'h4' );
		$h5   = $this->pageParser->getElementsByTagName( 'h5' );
		$h6   = $this->pageParser->getElementsByTagName( 'h6' );
		$tags = array_merge( $h2, $h3, $h4, $h5, $h6 );
		if ( empty( $tags ) ) {
			return new CheckerResult( 'subheading-missing' );
		}

		if (
			! aioseo()->helpers->isWooCommercePage( $this->object['id'] ) &&
			! aioseo()->helpers->isStaticPostsPage( $this->object['id'] ) &&
			aioseo()->helpers->isTruSeoEligible( $this->object['id'] )
		) {
			$post       = Post::getPost( $this->object['id'] );
			$keyphrases = Post::getKeyphrasesDefaults( $post->keyphrases );

			if ( empty( $keyphrases->focus->keyphrase ) ) {
				return null;
			}

			$hasFocusKeyword = false;

			$keyphrase = strtolower( (string) $keyphrases->focus->keyphrase );
			foreach ( $tags as $tag ) {
				$text = strtolower( (string) $tag );

				if ( strpos( $text, $keyphrase ) !== false ) {
					$hasFocusKeyword = true;
					break;
				}
			}

			if ( ! $hasFocusKeyword ) {
				return new CheckerResult( 'subheading-missing-focus-keyword', [ 'length' => count( $tags ) ] );
			}
		}

		return new CheckerResult( 'subheading-ok', [ 'length' => count( $tags ) ] );
	}

	/**
	 * Analyze the images considering amount and the ones without alt attributes.
	 *
	 * @since 4.8.6
	 *
	 * @return CheckerResult
	 */
	private function analyzeImgs() {
		$imgs = $this->pageParser->getBodyImages();

		if ( ! isset( $imgs['imageUrls'] ) || empty( $imgs['imageUrls'] ) ) {
			return new CheckerResult( 'image-missing' );
		} elseif ( isset( $imgs['imagesWithoutAltText'] ) && ! empty( $imgs['imagesWithoutAltText'] ) ) {
			return new CheckerResult( 'image-missing-alt', [
				'length' => count( $imgs['imagesWithoutAltText'] ),
				'items'  => $imgs['imagesWithoutAltText']
			] );
		}

		return new CheckerResult( 'image-ok' );
	}

	/**
	 * Analyze the links ratio.
	 *
	 * @since 4.8.6
	 *
	 * @return CheckerResult
	 */
	private function analyzeLinksRatio() {
		$links = $this->pageParser->getLinks();

		if ( empty( $links['internalLinks'] ) ) {
			return new CheckerResult( 'internal-links-missing' );
		}

		$content   = $this->pageParser->getMain()->nodeValue ?? '';
		$wordCount = str_word_count( $content );
		$ratio     = $wordCount > 0 ? $wordCount / 500 : 0; // 1 internal link per 500 words

		$internalLinksCount = count( $links['internalLinks'] );
		if ( intval( $ratio ) > $internalLinksCount ) {
			return new CheckerResult( 'internal-links-too-few', [ 'length' => $internalLinksCount ] );
		}

		return new CheckerResult( 'links-ratio-ok' );
	}

	/**
	 * Analyze the thumbnail.
	 *
	 * @since 4.8.6
	 *
	 * @return CheckerResult
	 */
	private function analyzeThumbnail() {
		$object = $this->object;

		if ( post_type_supports( $object['subtype'], 'thumbnail' ) && ! has_post_thumbnail( $object['id'] ) ) {
			return new CheckerResult( 'thumbnail-missing' );
		}

		return new CheckerResult( 'thumbnail-ok' );
	}

	/**
	 * Analyze the content length.
	 *
	 * @since 4.8.6
	 *
	 * @return CheckerResult
	 */
	private function analyzeContentLength() {
		$content = $this->pageParser->getMain()->nodeValue ?? '';
		if ( empty( $content ) ) {
			return new CheckerResult( 'content-length-too-short', [ 'length' => 0 ] );
		}

		$wordCount = str_word_count( $content );
		if ( 300 >= $wordCount ) {
			return new CheckerResult( 'content-length-too-short', [ 'length' => $wordCount ] );
		}

		return new CheckerResult( 'content-length-ok' );
	}

	/**
	 * Analyze the page for Keyword Cannibalization.
	 *
	 * @since 4.8.6
	 *
	 * @return CheckerResult|null
	 */
	private function analyzeHasKeywordCannibalization() {
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
			return null;
		}

		$posts = aioseo()->core->db->start( 'aioseo_posts as ap' )
			->select( 'p.ID, p.post_title' )
			->join( 'posts as p', 'p.ID = ap.post_id' )
			->where( 'p.ID !=', $object['id'] )
			->whereRaw( 'ap.keyphrases LIKE "%keyphrase\":\"' . $keyphrases->focus->keyphrase . '\"%"' )
			->run()
			->result();

		if ( ! empty( $posts ) ) {
			return new CheckerResult( 'keyword-cannibalization', [
				'posts' => array_map( function( $p ) {
					return [
						'title'    => $p->post_title,
						'editLink' => admin_url( 'post.php?post=' . $p->ID . '&action=edit' )
					];
				}, $posts )
			] );
		}

		return new CheckerResult( 'keyword-cannibalization-ok' );
	}

	/**
	 * Analyze the first paragraph.
	 *
	 * @since 4.8.6
	 *
	 * @return CheckerResult|null
	 */
	private function analyzeFocusKeywordInFirstParagraph() {
		if (
			aioseo()->helpers->isWooCommercePage( $this->object['id'] ) ||
			aioseo()->helpers->isStaticPostsPage( $this->object['id'] ) ||
			! aioseo()->helpers->isTruSeoEligible( $this->object['id'] )
		) {
			return null;
		}

		$tags = $this->pageParser->getSectionElements( 'main', [ 'p' ] );
		if ( empty( $tags ) ) {
			return null;
		}

		$post       = Post::getPost( $this->object['id'] );
		$keyphrases = Post::getKeyphrasesDefaults( $post->keyphrases );

		if ( empty( $keyphrases->focus->keyphrase ) ) {
			return null;
		}

		$text      = strtolower( (string) $tags[0]->nodeValue );
		$keyphrase = strtolower( (string) $keyphrases->focus->keyphrase );

		if ( strpos( $text, $keyphrase ) === false ) {
			return new CheckerResult( 'first-paragraph-missing-focus-keyword' );
		}

		return new CheckerResult( 'first-paragraph-ok' );
	}

	/**
	 * Analyze the page for Focus Keyword issues inside the Title.
	 *
	 * @since 4.8.6
	 *
	 * @return CheckerResult|null
	 */
	private function analyzeFocusKeywordInTitle() {
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

		$titles = $this->pageParser->isScraping
			? $this->pageParser->getElementsByTagName( 'title' )
			: [ aioseo()->meta->title->getPostTitle( $this->object['id'] ) ];

		if ( empty( $keyphrases->focus->keyphrase ) || empty( $titles ) ) {
			return null;
		}

		$text      = strtolower( (string) $titles[0] );
		$keyphrase = strtolower( (string) $keyphrases->focus->keyphrase );

		if ( strpos( $text, $keyphrase ) === false ) {
			return new CheckerResult( 'title-missing-focus-keyword' );
		}

		return new CheckerResult( 'title-focus-keyword-ok' );
	}

	/**
	 * Analyze the page for Focus Keyword issues inside the Description.
	 *
	 * @since 4.8.6
	 *
	 * @return CheckerResult|null
	 */
	private function analyzeFocusKeywordInDescription() {
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

		$descriptions = $this->pageParser->isScraping
			? $this->pageParser->getMetaTag( 'description' )
			: [ aioseo()->meta->description->getPostDescription( $this->object['id'] ) ];

		if ( empty( $keyphrases->focus->keyphrase ) || empty( $descriptions ) ) {
			return null;
		}

		$text      = strtolower( (string) $descriptions[0] );
		$keyphrase = strtolower( (string) $keyphrases->focus->keyphrase );

		if ( strpos( $text, $keyphrase ) === false ) {
			return new CheckerResult( 'description-missing-focus-keyword' );
		}

		return new CheckerResult( 'description-focus-keyword-ok' );
	}

	/**
	 * Analyze the page for Focus Keyword issues inside the URL slug.
	 *
	 * @since 4.8.6
	 *
	 * @return CheckerResult|null
	 */
	private function analyzeFocusKeywordInUrl() {
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
			return null;
		}

		$permalink = strtolower( (string) get_permalink( $object['id'] ) );
		$keyphrase = strtolower( (string) $keyphrases->focus->keyphrase );
		$keyphrase = sanitize_title( $keyphrase );

		if ( strpos( $permalink, $keyphrase ) === false ) {
			return new CheckerResult( 'url-missing-focus-keyword' );
		}

		return new CheckerResult( 'url-focus-keyword-ok' );
	}

	/**
	 * Analyze the page for URL length issues.
	 *
	 * @since 4.8.6
	 *
	 * @return CheckerResult
	 */
	private function analyzeUrlLength() {
		$permalink = trim( (string) get_permalink( $this->object['id'] ), '/' );
		$url       = wp_parse_url( $permalink );
		$length    = strlen( $url['path'] );

		if ( 50 < $length ) {
			return new CheckerResult( 'url-length-too-long', [ 'length' => $length ] );
		}

		return new CheckerResult( 'url-length-ok' );
	}

	/**
	 * Analyze the page for Product Schema issues.
	 *
	 * @since 4.8.6
	 *
	 * @return CheckerResult|null
	 */
	private function analyzeProductSchema() {
		if ( ! $this->pageParser->isScraping || ( ! aioseo()->helpers->isWooCommerceActive() && ! aioseo()->helpers->isEddActive() ) ) {
			return null;
		}

		$post = get_post( $this->object['id'] );
		if ( aioseo()->helpers->isWooCommerceActive() && 'product' !== $post->post_type ) {
			return null;
		} elseif ( aioseo()->helpers->isEddActive() && 'download' !== $post->post_type ) {
			return null;
		}

		$schemas = $this->pageParser->getSchemas();
		$decoded = (array) json_decode( $schemas );
		if ( empty( $schemas ) || empty( $decoded ) ) {
			return new CheckerResult( 'product-schema-missing' );
		}

		foreach ( $decoded['@graph'] as $graph ) {
			$current = (array) $graph;
			if ( 'Product' === $current['@type'] || 'ProductGroup' === $current['@type'] ) {
				return new CheckerResult( 'product-schema-ok' );
			}
		}

		return new CheckerResult( 'product-schema-missing' );
	}
}