<?php

namespace AIOSEO\Plugin\Pro\SeoAnalysis\Checkers\Term;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Pro\SeoAnalysis\PageParser;
use AIOSEO\Plugin\Pro\SeoAnalysis\Checkers\CheckersAbstract;
use AIOSEO\Plugin\Pro\SeoAnalysis\Checkers\CheckerResult;

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
	 * @param string     $subtype  The subtype of the object.
	 */
	public function __construct( $objectId, $parser, $subtype ) {
		parent::__construct([
			'id'      => $objectId,
			'type'    => 'term',
			'subtype' => $subtype,
		], $parser);
	}

	/**
	 * Analyze the term for Advanced SEO issues.
	 *
	 * @since 4.8.6
	 *
	 * @return array List of checkers/issues.
	 */
	public function get() {
		return [
			'noindex'      => $this->analyzeNoIndex(),
			'openGraph'    => $this->analyzeOpenGraph(),
			'schema'       => $this->analyzeSchema(),
			'canonicalTag' => $this->analyzeCanonicalTag(),
		];
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
	 * Analyze the term for noindex meta tag.
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
	 * Analyze the term for Open Graph issues.
	 *
	 * @since 4.8.6
	 *
	 * @return CheckerResult
	 */
	private function analyzeOpenGraph() {
		$required = [
			'og:title'       => 0,
			'og:type'        => 0,
			'og:image'       => 0,
			'og:url'         => 0,
			'og:description' => 0
		];

		foreach ( $this->pageParser->getOpenGraphTags() as $tag ) {
			if ( isset( $required[ $tag['key'] ] ) ) {
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
		if ( empty( $this->pageParser->getSchemas() ) ) {
			return new CheckerResult( 'schema-missing' );
		}

		return new CheckerResult( 'schema-ok' );
	}

	/**
	 * Analyze the term for Canonical URL.
	 *
	 * @since 4.8.6
	 *
	 * @return CheckerResult
	 */
	private function analyzeCanonicalTag() {
		if ( empty( $this->pageParser->getCanonicalUrl() ) ) {
			return new CheckerResult( 'canonical-missing' );
		}

		return new CheckerResult( 'canonical-ok' );
	}
}