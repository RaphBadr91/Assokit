<?php
namespace AIOSEO\Plugin\Pro\SeoRevisions;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * This class serves to overwrite some features from WordPress native Text Difference Renderer.
 *
 * @since 4.4.0
 */
class TextDiffRendererTable extends \WP_Text_Diff_Renderer_Table {
	/**
	 * Threshold for when a diff should be saved or omitted.
	 *
	 * @since 4.4.0
	 *
	 * @var int
	 */
	protected $_diff_threshold = 2; // phpcs:disable Squiz.NamingConventions.ValidVariableName

	/**
	 * Class constructor.
	 *
	 * @since 4.4.0
	 *
	 * @return void
	 */
	public function __construct( $params = [] ) {
		parent::__construct( $params );
	}
}