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
	 * Analyze the term for Basic SEO issues.
	 *
	 * @since 4.8.6
	 *
	 * @return array List of checkers/issues.
	 */
	public function get() {
		return [
			'title'       => $this->analyzeTitle(),
			'description' => $this->analyzeDescription(),
		];
	}

	/**
	 * Analyze the title tag.
	 *
	 * @since 4.8.6
	 *
	 * @return CheckerResult
	 */
	private function analyzeTitle() {
		$titles = $this->pageParser->getElementsByTagName( 'title' );

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
	 * Analyze the description.
	 *
	 * @since 4.8.6
	 *
	 * @return CheckerResult
	 */
	private function analyzeDescription() {
		$descriptions = $this->pageParser->getMetaTag( 'description' );

		if ( empty( $descriptions ) ) {
			return new CheckerResult( 'description-missing' );
		} elseif ( 120 >= mb_strlen( $descriptions[0] ) ) {
			return new CheckerResult( 'description-too-short', [ 'length' => mb_strlen( $descriptions[0] ) ] );
		} elseif ( 160 < mb_strlen( $descriptions[0] ) ) {
			return new CheckerResult( 'description-too-long', [ 'length' => mb_strlen( $descriptions[0] ) ] );
		}

		return new CheckerResult( 'description-ok', [ 'length' => mb_strlen( $descriptions[0] ) ] );
	}
}