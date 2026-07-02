<?php
namespace AIOSEO\Plugin\Addon\NewsSitemap\NewsSitemap;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main class for News Sitemap cache.
 *
 * @since 1.0.7
 */
class Cache extends \AIOSEO\Plugin\Common\Utils\Cache {
	/**
	 * The News Sitemap addon cache prefix.
	 *
	 * @since 1.0.7
	 *
	 * @var string
	 */
	protected $prefix = 'aioseo_news_sitemap_';
}