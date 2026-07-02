<?php
namespace AIOSEO\Plugin\Addon\LocalBusiness\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Api class for the admin.
 *
 * @since 1.1.3
 */
class Api {
	/**
	 * The routes we use in the rest API.
	 *
	 * @since 1.1.3
	 *
	 * @var array
	 */
	protected $routes = [
		// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		// phpcs:disable Generic.Files.LineLength.MaxExceeded
		'POST' => [
			'local-business/maps/check-api-enabled' => [
				'callback' => [ 'Maps', 'checkApiAccess', 'AIOSEO\\Plugin\\Addon\\LocalBusiness\\Api' ],
				'access'   => 'aioseo_local_seo_settings'
			],
			'local-business/import-plugins'         => [
				'callback' => [ 'Import', 'importPlugins', 'AIOSEO\\Plugin\\Addon\\LocalBusiness\\Api' ],
				'access'   => 'aioseo_local_seo_settings'
			]
		]
		// phpcs:enable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		// phpcs:enable Generic.Files.LineLength.MaxExceeded
	];

	/**
	 * Get all the routes to register.
	 *
	 * @since 1.1.3
	 *
	 * @return array An array of routes.
	 */
	public function getRoutes() {
		return $this->routes;
	}
}