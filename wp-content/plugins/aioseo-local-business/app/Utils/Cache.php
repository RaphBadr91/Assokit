<?php
namespace AIOSEO\Plugin\Addon\LocalBusiness\Utils;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main class for redirects cache.
 *
 * @since 1.3.0
 */
class Cache extends \AIOSEO\Plugin\Common\Utils\Cache {
	/**
	 * The redirect addon cache prefix.
	 *
	 * @since 1.3.0
	 *
	 * @var string
	 */
	protected $prefix = 'aioseo_local_business_';
}