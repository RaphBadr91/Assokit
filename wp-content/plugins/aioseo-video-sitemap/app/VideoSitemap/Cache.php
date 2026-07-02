<?php
namespace AIOSEO\Plugin\Addon\VideoSitemap\VideoSitemap;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main class for Video Sitemap cache.
 *
 * @since 1.1.3
 */
class Cache extends \AIOSEO\Plugin\Common\Utils\Cache {
	/**
	 * The Video Sitemap addon cache prefix.
	 *
	 * @since 1.1.3
	 *
	 * @var string
	 */
	protected $prefix = 'aioseo_video_sitemap_';
}