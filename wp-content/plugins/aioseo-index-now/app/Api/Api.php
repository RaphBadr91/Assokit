<?php
namespace AIOSEO\Plugin\Addon\IndexNow\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Route class for the API.
 *
 * @since 1.0.0
 */
class Api {
	/**
	 * The REST routes.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $routes = [
		'GET' => [
			'index-now/generate-api-key' => [
				'callback' => [ 'Settings', 'generateApiKey', 'AIOSEO\\Plugin\\Addon\\IndexNow\\Api' ],
				'access'   => [ 'aioseo_general_settings' ]
			],
			'index-now/api-key'          => [
				'callback' => [ 'Settings', 'getApiKey', 'AIOSEO\\Plugin\\Addon\\IndexNow\\Api' ],
				'access'   => [ 'aioseo_general_settings' ]
			]
		]
	];

	/**
	 * Returns all routes that need to be registered.
	 *
	 * @since 1.0.0
	 *
	 * @return array The routes.
	 */
	public function getRoutes() {
		return $this->routes;
	}
}