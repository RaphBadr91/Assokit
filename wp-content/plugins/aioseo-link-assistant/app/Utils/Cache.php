<?php
namespace AIOSEO\Plugin\Addon\LinkAssistant\Utils;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main class for Link Assistant cache.
 *
 * @since 1.0.6
 */
class Cache extends \AIOSEO\Plugin\Common\Utils\Cache {
	/**
	 * The Link Assistant addon cache prefix.
	 *
	 * @since 1.0.6
	 *
	 * @var string
	 */
	protected $prefix = 'link_assistant_';
}