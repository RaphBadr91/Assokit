<?php
namespace AIOSEO\Plugin\Addon\LocalBusiness\Utils;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Utils\Templates as CommonTemplates;

/**
 * Class Templates
 *
 * @since 1.1.0
 */
class Templates extends CommonTemplates {
	/**
	 * This plugin absolute path.
	 *
	 * @since 1.1.0
	 *
	 * @var string
	 */
	protected $pluginPath = AIOSEO_LOCAL_BUSINESS_PATH;

	/**
	 * Paths were our template files are located.
	 *
	 * @since 1.1.0
	 *
	 * @var string Array of paths.
	 */
	protected $paths = [
		'app/Views'
	];

	/**
	 * Subpath for theme usage.
	 *
	 * @since 1.1.0
	 *
	 * @var string
	 */
	protected $themeTemplateSubpath = 'localBusiness';
}