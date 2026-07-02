<?php

namespace AIOSEO\Plugin\Pro\SeoAnalysis\Checkers;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract class for SEO validations/checkers.
 *
 * @since 4.8.6
 */
abstract class CheckersAbstract {
	/**
	 * The page parser instance.
	 *
	 * @since 4.8.6
	 *
	 * @var \AIOSEO\Plugin\Pro\SeoAnalysis\PageParser
	 */
	protected $pageParser = null;

	/**
	 * The object.
	 *
	 * @since 4.8.6
	 *
	 * @var array
	 */
	protected $object = [];

	/**
	 * Class constructor.
	 *
	 * @since 4.8.6
	 *
	 * @param array                                     $object The object.
	 * @param \AIOSEO\Plugin\Pro\SeoAnalysis\PageParser $parser The page parser instance.
	 */
	public function __construct( $object, $parser = null ) {
		$this->object     = $object;
		$this->pageParser = $parser;
	}
}