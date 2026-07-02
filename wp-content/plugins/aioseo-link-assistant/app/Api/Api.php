<?php
namespace AIOSEO\Plugin\Addon\LinkAssistant\Api;

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
		// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		// phpcs:disable Generic.Files.LineLength.MaxExceeded
		'GET'    => [
			'link-assistant/data/menu'                                              => [
				'callback' => [ 'Data', 'getMenuData', 'AIOSEO\\Plugin\\Addon\\LinkAssistant\\Api' ],
				'access'   => [ 'aioseo_link_assistant_settings' ]
			],
			'link-assistant/data/overview'                                          => [
				'callback' => [ 'Data', 'getOverviewData', 'AIOSEO\\Plugin\\Addon\\LinkAssistant\\Api' ],
				'access'   => [ 'aioseo_link_assistant_settings' ]
			],
			'link-assistant/data/post/(?P<postId>[\d]+)'                            => [
				'callback' => [ 'Data', 'getPostData', 'AIOSEO\\Plugin\\Addon\\LinkAssistant\\Api' ],
				'access'   => [ 'aioseo_page_link_assistant_settings' ]
			],
			'link-assistant/data/suggestions-scan-percent'                          => [
				'callback' => [ 'Data', 'getSuggestionsScanPercent', 'AIOSEO\\Plugin\\Addon\\LinkAssistant\\Api' ],
				'access'   => [ 'aioseo_page_link_assistant_settings', 'aioseo_link_assistant_settings' ]
			],
			'link-assistant/data/dismiss-alert'                                     => [
				'callback' => [ 'Data', 'dismissAlert', 'AIOSEO\\Plugin\\Addon\\LinkAssistant\\Api' ],
				'access'   => [ 'aioseo_page_link_assistant_settings', 'aioseo_link_assistant_settings' ]
			],
			'link-assistant/data/trigger-scan'                                      => [
				'callback' => [ 'Data', 'triggerScan', 'AIOSEO\\Plugin\\Addon\\LinkAssistant\\Api' ],
				'access'   => [ 'aioseo_link_assistant_settings' ]
			],
			'link-assistant/links-report/search/(?P<searchTerm>.*)/(?P<page>[\d]+)' => [
				'callback' => [ 'LinksReport', 'search', 'AIOSEO\\Plugin\\Addon\\LinkAssistant\\Api' ],
				'access'   => [ 'aioseo_link_assistant_settings' ]
			]
		],
		'POST'   => [
			'link-assistant/links-report/(?P<filter>all|linking-opportunities|orphaned-posts)' => [
				'callback' => [ 'LinksReport', 'fetchLinksReport', 'AIOSEO\\Plugin\\Addon\\LinkAssistant\\Api' ],
				'access'   => [ 'aioseo_link_assistant_settings' ]
			],
			'link-assistant/links-report-inner/(?P<filter>all)'                                => [
				'callback' => [ 'LinksReportInner', 'fetchLinksReportInner', 'AIOSEO\\Plugin\\Addon\\LinkAssistant\\Api' ],
				'access'   => [ 'aioseo_link_assistant_settings' ]
			],
			'link-assistant/post-report/(?P<filter>all)'                                       => [
				'callback' => [ 'PostReport', 'fetchPostsReport', 'AIOSEO\\Plugin\\Addon\\LinkAssistant\\Api' ],
				'access'   => [ 'aioseo_link_assistant_settings' ]
			],
			'link-assistant/links-report-inner/links/delete'                                   => [
				'callback' => [ 'LinksReportInner', 'linkDelete', 'AIOSEO\\Plugin\\Addon\\LinkAssistant\\Api' ],
				'access'   => [ 'aioseo_link_assistant_settings' ]
			],
			'link-assistant/links-report-inner/links/bulk'                                     => [
				'callback' => [ 'LinksReportInner', 'linksBulk', 'AIOSEO\\Plugin\\Addon\\LinkAssistant\\Api' ],
				'access'   => [ 'aioseo_link_assistant_settings' ]
			],
			'link-assistant/links-report-inner/suggestions/dismiss'                            => [
				'callback' => [ 'LinksReportInner', 'suggestionDismiss', 'AIOSEO\\Plugin\\Addon\\LinkAssistant\\Api' ],
				'access'   => [ 'aioseo_link_assistant_settings' ]
			],
			'link-assistant/links-report-inner/suggestions/bulk'                               => [
				'callback' => [ 'LinksReportInner', 'suggestionsBulk', 'AIOSEO\\Plugin\\Addon\\LinkAssistant\\Api' ],
				'access'   => [ 'aioseo_link_assistant_settings' ]
			],
			'link-assistant/links-report-inner/refresh'                                        => [
				'callback' => [ 'LinksReportInner', 'refresh', 'AIOSEO\\Plugin\\Addon\\LinkAssistant\\Api' ],
				'access'   => [ 'aioseo_link_assistant_settings' ]
			],
			'link-assistant/post-report/links/delete'                                          => [
				'callback' => [ 'PostReport', 'linkDelete', 'AIOSEO\\Plugin\\Addon\\LinkAssistant\\Api' ],
				'access'   => [ 'aioseo_link_assistant_settings' ]
			],
			'link-assistant/post-report/links/bulk'                                            => [
				'callback' => [ 'PostReport', 'linksBulk', 'AIOSEO\\Plugin\\Addon\\LinkAssistant\\Api' ],
				'access'   => [ 'aioseo_link_assistant_settings' ]
			],
			'link-assistant/post-report/suggestions/dismiss'                                   => [
				'callback' => [ 'PostReport', 'suggestionDismiss', 'AIOSEO\\Plugin\\Addon\\LinkAssistant\\Api' ],
				'access'   => [ 'aioseo_link_assistant_settings' ]
			],
			'link-assistant/post-report/refresh'                                               => [
				'callback' => [ 'LinksReport', 'refresh', 'AIOSEO\\Plugin\\Addon\\LinkAssistant\\Api' ],
				'access'   => [ 'aioseo_link_assistant_settings' ]
			],
			'link-assistant/post-report/suggestions/bulk'                                      => [
				'callback' => [ 'PostReport', 'suggestionsBulk', 'AIOSEO\\Plugin\\Addon\\LinkAssistant\\Api' ],
				'access'   => [ 'aioseo_link_assistant_settings' ]
			],
			'link-assistant/domains-report/(?P<filter>all)'                                    => [
				'callback' => [ 'DomainsReport', 'fetchDomainsReport', 'AIOSEO\\Plugin\\Addon\\LinkAssistant\\Api' ],
				'access'   => [ 'aioseo_link_assistant_settings' ]
			],
			'link-assistant/domains-report-inner/(?P<filter>all)'                              => [
				'callback' => [ 'DomainsReportInner', 'fetchDomainsReportInner', 'AIOSEO\\Plugin\\Addon\\LinkAssistant\\Api' ],
				'access'   => [ 'aioseo_link_assistant_settings' ]
			],
			'link-assistant/domains-report/bulk/(?P<action>delete)'                            => [
				'callback' => [ 'DomainsReport', 'bulk', 'AIOSEO\\Plugin\\Addon\\LinkAssistant\\Api' ],
				'access'   => [ 'aioseo_link_assistant_settings' ]
			],
			'link-assistant/domains-report-inner/bulk/(?P<action>delete)'                      => [
				'callback' => [ 'DomainsReportInner', 'bulk', 'AIOSEO\\Plugin\\Addon\\LinkAssistant\\Api' ],
				'access'   => [ 'aioseo_link_assistant_settings' ]
			],
			'link-assistant/post-settings/update'                                              => [
				'callback' => [ 'PostSettings', 'update', 'AIOSEO\\Plugin\\Addon\\LinkAssistant\\Api' ],
				'access'   => [ 'aioseo_page_link_assistant_settings' ]
			],
			'link-assistant/post-settings/links/delete'                                        => [
				'callback' => [ 'PostSettings', 'linkDelete', 'AIOSEO\\Plugin\\Addon\\LinkAssistant\\Api' ],
				'access'   => [ 'aioseo_page_link_assistant_settings' ]
			],
			'link-assistant/post-settings/links/bulk'                                          => [
				'callback' => [ 'PostSettings', 'linksBulk', 'AIOSEO\\Plugin\\Addon\\LinkAssistant\\Api' ],
				'access'   => [ 'aioseo_page_link_assistant_settings' ]
			],
			'link-assistant/post-settings/suggestions/dismiss'                                 => [
				'callback' => [ 'PostSettings', 'suggestionDismiss', 'AIOSEO\\Plugin\\Addon\\LinkAssistant\\Api' ],
				'access'   => [ 'aioseo_page_link_assistant_settings' ]
			],
			'link-assistant/post-settings/suggestions/bulk'                                    => [
				'callback' => [ 'PostSettings', 'suggestionsBulk', 'AIOSEO\\Plugin\\Addon\\LinkAssistant\\Api' ],
				'access'   => [ 'aioseo_page_link_assistant_settings' ]
			]
		],
		'PUT'    => [
			'link-assistant/domains-report-inner/link' => [
				'callback' => [ 'DomainsReportInner', 'updateLink', 'AIOSEO\\Plugin\\Addon\\LinkAssistant\\Api' ],
				'access'   => [ 'aioseo_link_assistant_settings' ]
			],
		],
		'DELETE' => [
			'link-assistant/links-report/post/(?P<postId>[\d]+)' => [
				'callback' => [ 'LinksReport', 'deletePostLinks', 'AIOSEO\\Plugin\\Addon\\LinkAssistant\\Api' ],
				'access'   => [ 'aioseo_link_assistant_settings' ]
			],
			'link-assistant/domains-report-inner/link'           => [
				'callback' => [ 'DomainsReportInner', 'deleteLink', 'AIOSEO\\Plugin\\Addon\\LinkAssistant\\Api' ],
				'access'   => [ 'aioseo_link_assistant_settings' ]
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