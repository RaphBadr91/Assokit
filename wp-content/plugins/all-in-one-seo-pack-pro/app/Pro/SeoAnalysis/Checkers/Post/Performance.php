<?php

namespace AIOSEO\Plugin\Pro\SeoAnalysis\Checkers\Post;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Pro\SeoAnalysis\PageParser;
use AIOSEO\Plugin\Pro\SeoAnalysis\Checkers\CheckersAbstract;
use AIOSEO\Plugin\Pro\SeoAnalysis\Checkers\CheckerResult;

/**
 * Handles the SEO validation for Performance issues.
 *
 * @since 4.8.6
 */
class Performance extends CheckersAbstract {
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
	 * Analyze the page for Pefformance SEO issues.
	 *
	 * @since 4.8.6
	 *
	 * @return array List of checkers/issues
	 */
	public function get() {
		return [
			'requests' => $this->analyzeRequests(),
		];
	}

	/**
	 * Analyze the page requests.
	 *
	 * @since 4.8.6
	 *
	 * @return CheckerResult
	 */
	private function analyzeRequests() {
		$tags     = [ 'link', 'script', 'img', 'source' ];
		$elements = array_merge(
			$this->pageParser->getSectionElements( 'head', $tags ),
			$this->pageParser->getSectionElements( 'body', $tags )
		);
		$requests = [];

		foreach ( $elements as $element ) {
			if ( 'link' !== $element->tagName ) {
				if ( $element->hasAttribute( 'src' ) ) {
					array_push( $requests, $element->getAttribute( 'src' ) );
				}

				continue;
			}

			// If the link is a stylesheet or a preload, we should analyze it.
			$rel = $element->getAttribute( 'rel' );
			if ( 'stylesheet' === $rel || 'preload' === $rel ) {
				if ( $element->hasAttribute( 'href' ) ) {
					array_push( $requests, $element->getAttribute( 'href' ) );
				}
			}
		}

		return new CheckerResult( 'requests', [ 'length' => count( $requests ) ] );
	}
}