<?php
namespace AIOSEO\Plugin\Addon\IndexNow\Utils;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main class for IndexNow cache.
 *
 * @since 1.0.4
 */
class Cache extends \AIOSEO\Plugin\Common\Utils\Cache {
	/**
	 * The IndexNow addon cache prefix.
	 *
	 * @since 1.0.4
	 *
	 * @var string
	 */
	protected $prefix = 'index_now_';
}