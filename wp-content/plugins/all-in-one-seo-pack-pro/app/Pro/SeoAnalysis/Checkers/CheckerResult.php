<?php
namespace AIOSEO\Plugin\Pro\SeoAnalysis\Checkers;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CheckerResult object used for scan validations.
 *
 * @since 4.8.6
 */
class CheckerResult {
	/**
	 * The issue code.
	 *
	 * @since 4.8.6
	 *
	 * @var string
	 */
	public $code;

	/**
	 * The issue metadata.
	 *
	 * @since 4.8.6
	 *
	 * @var array|null
	 */
	public $metadata;

	/**
	 * Class constructor.
	 *
	 * @since 4.8.6
	 *
	 * @param string $code     The issue code.
	 * @param array  $metadata The issue metadata.
	 */
	public function __construct( $code, $metadata = null ) {
		$this->code     = $code;
		$this->metadata = $metadata;
	}
}