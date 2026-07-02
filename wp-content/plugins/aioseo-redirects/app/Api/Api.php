<?php
namespace AIOSEO\Plugin\Addon\Redirects\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Api class for the admin.
 *
 * @since 1.0.0
 */
class Api {
	/**
	 * The routes we use in the rest API.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $routes = [
		// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		// phpcs:disable Generic.Files.LineLength.MaxExceeded
		'GET'    => [
			'redirects/options'                                 => [ 'callback' => [ 'Redirects', 'getOptions', 'AIOSEO\\Plugin\\Addon\\Redirects\\Api' ], 'access' => 'aioseo_redirects_settings' ],
			'redirects/export/(?P<server>apache|nginx)'         => [ 'callback' => [ 'Redirects', 'exportServer', 'AIOSEO\\Plugin\\Addon\\Redirects\\Api' ], 'access' => 'aioseo_redirects_settings' ],
			'redirects/export-logs/(?P<type>redirect|404)'      => [ 'callback' => [ 'Redirects', 'exportLogs', 'AIOSEO\\Plugin\\Addon\\Redirects\\Api' ], 'access' => 'aioseo_redirects_settings' ],
			'redirects/server/test'                             => [ 'callback' => [ 'Redirects', 'serverTest', 'AIOSEO\\Plugin\\Addon\\Redirects\\Api' ], 'access' => 'aioseo_redirects_manage' ],
			'redirects/(?P<context>post|term)/(?P<id>[\d]+)'    => [ 'callback' => [ 'Redirects', 'getRedirects', 'AIOSEO\\Plugin\\Addon\\Redirects\\Api' ], 'access' => [ 'aioseo_redirects_manage', 'aioseo_page_redirects_manage' ] ],
			'redirects/manual-redirects/(?P<hash>[a-zA-Z0-9]+)' => [ 'callback' => [ 'Redirects', 'getManualRedirects', 'AIOSEO\\Plugin\\Addon\\Redirects\\Api' ], 'access' => 'aioseo_redirects_manage' ]
		],
		'POST'   => [
			'redirects/(?P<filter>enabled|disabled|all)'           => [ 'callback' => [ 'Redirects', 'fetchRedirects', 'AIOSEO\\Plugin\\Addon\\Redirects\\Api' ], 'access' => [ 'aioseo_redirects_manage', 'aioseo_page_redirects_manage' ] ],
			'redirects/(?P<slug>logs|404)/(?P<filter>all)'         => [ 'callback' => [ 'Redirects', 'fetchLogs', 'AIOSEO\\Plugin\\Addon\\Redirects\\Api' ], 'access' => [ 'aioseo_redirects_manage', 'aioseo_page_redirects_manage' ] ],
			'redirects/bulk/(?P<action>enable|disable|reset-hits)' => [ 'callback' => [ 'Redirects', 'bulk', 'AIOSEO\\Plugin\\Addon\\Redirects\\Api' ], 'access' => [ 'aioseo_redirects_manage', 'aioseo_page_redirects_manage' ] ],
			'redirects'                                            => [ 'callback' => [ 'Redirects', 'create', 'AIOSEO\\Plugin\\Addon\\Redirects\\Api' ], 'access' => [ 'aioseo_redirects_manage', 'aioseo_page_redirects_manage' ] ],
			'redirects/(?P<id>[\d]+)'                              => [ 'callback' => [ 'Redirects', 'update', 'AIOSEO\\Plugin\\Addon\\Redirects\\Api' ], 'access' => [ 'aioseo_redirects_manage', 'aioseo_page_redirects_manage' ] ],
			'redirects/posts'                                      => [ 'callback' => [ 'Redirects', 'getPosts', 'AIOSEO\\Plugin\\Addon\\Redirects\\Api' ], 'access' => 'aioseo_redirects_manage' ],
			'redirects/export/(?P<type>htaccess|nginx|json)'       => [ 'callback' => [ 'Redirects', 'export', 'AIOSEO\\Plugin\\Addon\\Redirects\\Api' ], 'access' => 'aioseo_redirects_settings' ],
			'redirects/import'                                     => [ 'callback' => [ 'Redirects', 'import', 'AIOSEO\\Plugin\\Addon\\Redirects\\Api' ], 'access' => 'aioseo_redirects_settings' ],
			'redirects/import-plugins'                             => [ 'callback' => [ 'Settings', 'importPlugins', 'AIOSEO\\Plugin\\Addon\\Redirects\\Api' ], 'access' => 'aioseo_redirects_settings' ],
			'redirects/import-csv'                                 => [ 'callback' => [ 'Redirects', 'importCsv', 'AIOSEO\\Plugin\\Addon\\Redirects\\Api' ], 'access' => 'aioseo_redirects_settings' ],
			'redirects/(?P<id>[\d]+/test)'                         => [ 'callback' => [ 'Redirects', 'test', 'AIOSEO\\Plugin\\Addon\\Redirects\\Api' ], 'access' => [ 'aioseo_redirects_manage', 'aioseo_page_redirects_manage' ] ]
		],
		'DELETE' => [
			'redirects/logs/(?P<slug>logs|404)' => [ 'callback' => [ 'Redirects', 'deleteLog', 'AIOSEO\\Plugin\\Addon\\Redirects\\Api' ], 'access' => 'aioseo_redirects_manage' ],
			'redirects/(?P<id>[\d]+)'           => [ 'callback' => [ 'Redirects', 'delete', 'AIOSEO\\Plugin\\Addon\\Redirects\\Api' ], 'access' => [ 'aioseo_redirects_manage', 'aioseo_page_redirects_manage' ] ],
			'redirects/bulk'                    => [ 'callback' => [ 'Redirects', 'bulkDelete', 'AIOSEO\\Plugin\\Addon\\Redirects\\Api' ], 'access' => 'aioseo_redirects_manage' ]
		]
		// phpcs:enable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		// phpcs:enable Generic.Files.LineLength.MaxExceeded
	];

	/**
	 * Get all the routes to register.
	 *
	 * @since 1.0.0
	 *
	 * @return array An array of routes.
	 */
	public function getRoutes() {
		return $this->routes;
	}
}