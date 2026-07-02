<?php
namespace AIOSEO\Plugin\Pro\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Api as CommonApi;

/**
 * Api class for the admin.
 *
 * @since 4.0.0
 */
class Api extends CommonApi\Api {
	/**
	 * The routes we use in the rest API.
	 *
	 * @since 4.0.0
	 *
	 * @var array
	 */
	protected $proRoutes = [
		// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound, Generic.Files.LineLength.MaxExceeded
		'GET'    => [
			'network-robots/(?P<siteId>[\d]+|network)'                           => [ 'callback' => [ 'Network', 'fetchSiteRobots' ], 'access' => 'manage_network' ],
			'schema/templates'                                                   => [ 'callback' => [ 'Schema', 'getTemplates' ], 'access' => 'aioseo_page_schema_settings' ],
			'search-statistics/stats/seo-statistics'                             => [ 'callback' => [ 'SearchStatistics\SearchStatistics', 'getSeoStatistics' ], 'access' => 'aioseo_search_statistics_settings' ],
			'search-statistics/stats/keywords'                                   => [ 'callback' => [ 'SearchStatistics\SearchStatistics', 'getKeywords' ], 'access' => 'aioseo_search_statistics_settings' ],
			'search-statistics/stats/content-rankings'                           => [ 'callback' => [ 'SearchStatistics\SearchStatistics', 'getContentRankings' ], 'access' => 'aioseo_search_statistics_settings' ],
			'search-statistics/post-detail'                                      => [ 'callback' => [ 'SearchStatistics\SearchStatistics', 'getPostDetail' ], 'access' => 'aioseo_search_statistics_settings' ],
			'search-statistics/post-detail/seo-statistics'                       => [ 'callback' => [ 'SearchStatistics\SearchStatistics', 'getPostDetailSeoStatistics' ], 'access' => 'aioseo_search_statistics_settings' ],
			'search-statistics/post-detail/keywords'                             => [ 'callback' => [ 'SearchStatistics\SearchStatistics', 'getPostDetailKeywords' ], 'access' => 'aioseo_search_statistics_settings' ],
			'search-statistics/post-detail/focus-keyword'                        => [ 'callback' => [ 'SearchStatistics\SearchStatistics', 'getPostDetailFocusKeywordTrend' ], 'access' => 'aioseo_search_statistics_settings' ],
			'search-statistics/pagespeed'                                        => [ 'callback' => [ 'SearchStatistics\SearchStatistics', 'getPageSpeed' ], 'access' => 'aioseo_search_statistics_settings' ],
			'search-statistics/seo-analysis'                                     => [ 'callback' => [ 'SearchStatistics\SearchStatistics', 'getSeoAnalysis' ], 'access' => 'aioseo_search_statistics_settings' ],
			'search-statistics/inspection-result'                                => [ 'callback' => [ 'SearchStatistics\SearchStatistics', 'getInspectionResult' ], 'access' => 'aioseo_search_statistics_settings' ],
			'search-statistics/index-status/objects'                             => [ 'callback' => [ 'SearchStatistics\IndexStatus', 'fetchObjects' ], 'access' => 'aioseo_search_statistics_settings' ],
			'search-statistics/index-status/overview'                            => [ 'callback' => [ 'SearchStatistics\IndexStatus', 'fetchOverview' ], 'access' => 'aioseo_search_statistics_settings' ],
			'search-statistics/keyword-rank-tracker/groups'                      => [ 'callback' => [ 'SearchStatistics\KeywordRankTracker', 'fetchGroups' ], 'access' => 'aioseo_search_statistics_settings' ],
			'search-statistics/keyword-rank-tracker/keywords'                    => [ 'callback' => [ 'SearchStatistics\KeywordRankTracker', 'fetchKeywords' ], 'access' => 'aioseo_search_statistics_settings' ],
			'search-statistics/keyword-rank-tracker/related-keywords'            => [ 'callback' => [ 'SearchStatistics\KeywordRankTracker', 'fetchRelatedKeywords' ], 'access' => 'aioseo_search_statistics_settings' ],
			'seo-revisions/(?P<context>post|term)/(?P<id>[\d]+)'                 => [ 'callback' => [ 'SeoRevisions', 'getRevisions' ], 'access' => 'aioseo_page_seo_revisions_settings' ],
			'seo-revisions/diff'                                                 => [ 'callback' => [ 'SeoRevisions', 'getRevisionsDiff' ], 'access' => 'aioseo_page_seo_revisions_settings' ],
			'seo-analysis/issues'                                                => [ 'callback' => [ 'SeoAnalysis', 'getIssues' ], 'access' => 'aioseo_seo_analysis_settings' ],
			'seo-analysis/issues/(?P<objectType>[\a-z]+)/(?P<objectId>[\0-9z]+)' => [ 'callback' => [ 'SeoAnalysis', 'getIssuesByObject' ], 'access' => 'aioseo_seo_analysis_settings' ],
			'seo-analysis/objects/(?P<issueCode>[\a-z]+)'                        => [ 'callback' => [ 'SeoAnalysis', 'getObjectsByIssueCode' ], 'access' => 'aioseo_seo_analysis_settings' ],
			'seo-analysis/objects'                                               => [ 'callback' => [ 'SeoAnalysis', 'getObjects' ], 'access' => 'aioseo_seo_analysis_settings' ],
			'seo-analysis/objects-scan-percent'                                  => [ 'callback' => [ 'SeoAnalysis', 'getObjectsScanPercent' ], 'access' => 'aioseo_seo_analysis_settings' ]
		],
		'POST'   => [
			'activate'                                                  => [ 'callback' => [ 'License', 'activateLicense' ], 'access' => 'aioseo_general_settings' ],
			'deactivate'                                                => [ 'callback' => [ 'License', 'deactivateLicense' ], 'access' => 'aioseo_general_settings' ],
			'multisite'                                                 => [ 'callback' => [ 'License', 'multisite' ], 'access' => 'aioseo_general_settings' ],
			'activated'                                                 => [ 'callback' => [ 'License', 'activated' ], 'access' => 'aioseo_general_settings' ],
			'network-sites/(?P<filter>all|activated|deactivated)'       => [ 'callback' => [ 'Network', 'fetchSites' ], 'access' => 'manage_network' ],
			'network-robots/(?P<siteId>[\d]+|network)'                  => [ 'callback' => [ 'Network', 'saveNetworkRobots' ], 'access' => 'manage_network' ],
			'notification/local-business-organization-reminder'         => [ 'callback' => [ 'Notifications', 'localBusinessOrganizationReminder' ], 'access' => 'any' ],
			'notification/news-publication-name-reminder'               => [ 'callback' => [ 'Notifications', 'newsPublicationNameReminder' ], 'access' => 'any' ],
			'notification/v3-migration-local-business-number-reminder'  => [ 'callback' => [ 'Notifications', 'migrationLocalBusinessNumberReminder' ], 'access' => 'any' ],
			'notification/v3-migration-local-business-country-reminder' => [ 'callback' => [ 'Notifications', 'migrationLocalBusinessCountryReminder' ], 'access' => 'any' ],
			'notification/import-local-business-country-reminder'       => [ 'callback' => [ 'Notifications', 'importLocalBusinessCountryReminder' ], 'access' => 'any' ],
			'notification/import-local-business-type-reminder'          => [ 'callback' => [ 'Notifications', 'importLocalBusinessTypeReminder' ], 'access' => 'any' ],
			'notification/import-local-business-number-reminder'        => [ 'callback' => [ 'Notifications', 'importLocalBusinessNumberReminder' ], 'access' => 'any' ],
			'notification/import-local-business-fax-reminder'           => [ 'callback' => [ 'Notifications', 'importLocalBusinessFaxReminder' ], 'access' => 'any' ],
			'notification/import-local-business-currencies-reminder'    => [ 'callback' => [ 'Notifications', 'importLocalBusinessCurrenciesReminder' ], 'access' => 'any' ],
			'schema/templates'                                          => [ 'callback' => [ 'Schema', 'addTemplate' ], 'access' => 'aioseo_page_schema_settings' ],
			'schema/validator/output'                                   => [ 'callback' => [ 'Schema', 'getValidatorOutput' ], 'access' => 'aioseo_page_schema_settings' ],
			'search-statistics/stats/keywords/posts'                    => [ 'callback' => [ 'SearchStatistics\SearchStatistics', 'getPagesByKeywords' ], 'access' => 'aioseo_search_statistics_settings' ],
			'search-statistics/keyword-rank-tracker/keywords'           => [ 'callback' => [ 'SearchStatistics\KeywordRankTracker', 'insertKeywords' ], 'access' => 'aioseo_search_statistics_settings' ],
			'search-statistics/keyword-rank-tracker/groups'             => [ 'callback' => [ 'SearchStatistics\KeywordRankTracker', 'insertGroups' ], 'access' => 'aioseo_search_statistics_settings' ],
			'search-statistics/keyword-rank-tracker/relationships'      => [ 'callback' => [ 'SearchStatistics\KeywordRankTracker', 'updateRelationships' ], 'access' => 'aioseo_search_statistics_settings' ],
			'search-statistics/keyword-rank-tracker/statistics'         => [ 'callback' => [ 'SearchStatistics\KeywordRankTracker', 'fetchStatistics' ], 'access' => 'aioseo_search_statistics_settings' ],
			'seo-revisions/(?P<id>[\d]+)'                               => [ 'callback' => [ 'SeoRevisions', 'updateRevision' ], 'access' => 'aioseo_page_seo_revisions_settings' ],
			'seo-revisions/restore/(?P<id>[\d]+)'                       => [ 'callback' => [ 'SeoRevisions', 'restoreRevision' ], 'access' => 'aioseo_page_seo_revisions_settings' ],
			'seo-analysis/issues/(?P<id>[\d]+)/ignore'                  => [ 'callback' => [ 'SeoAnalysis', 'ignoreIssue' ], 'access' => 'aioseo_seo_analysis_settings' ],
			'seo-analysis/objects/(?P<id>[\d]+)/keywords'               => [ 'callback' => [ 'SeoAnalysis', 'addFocusKeyword' ], 'access' => 'aioseo_seo_analysis_settings' ]
		],
		'PUT'    => [
			'schema/templates'                                              => [ 'callback' => [ 'Schema', 'updateTemplate' ], 'access' => 'aioseo_page_schema_settings' ],
			'search-statistics/keyword-rank-tracker/groups/(?P<id>[\d]+)'   => [ 'callback' => [ 'SearchStatistics\KeywordRankTracker', 'updateGroup' ], 'access' => 'aioseo_search_statistics_settings' ],
			'search-statistics/keyword-rank-tracker/keywords/(?P<id>[\d]+)' => [ 'callback' => [ 'SearchStatistics\KeywordRankTracker', 'updateKeyword' ], 'access' => 'aioseo_search_statistics_settings' ],
		],
		'DELETE' => [
			'schema/templates'                                => [ 'callback' => [ 'Schema', 'deleteTemplate' ], 'access' => 'aioseo_page_schema_settings' ],
			'seo-revisions/(?P<id>[\d]+)'                     => [ 'callback' => [ 'SeoRevisions', 'deleteRevision' ], 'access' => 'aioseo_page_seo_revisions_settings' ],
			'search-statistics/keyword-rank-tracker/groups'   => [ 'callback' => [ 'SearchStatistics\KeywordRankTracker', 'deleteGroups' ], 'access' => 'aioseo_search_statistics_settings' ],
			'search-statistics/keyword-rank-tracker/keywords' => [ 'callback' => [ 'SearchStatistics\KeywordRankTracker', 'deleteKeywords' ], 'access' => 'aioseo_search_statistics_settings' ],
		]
		// phpcs:enable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound, Generic.Files.LineLength.MaxExceeded
	];

	/**
	 * Get all the routes to register.
	 *
	 * @since 4.0.0
	 *
	 * @return array An array of routes.
	 */
	protected function getRoutes() {
		$routes       = array_merge_recursive( parent::getRoutes(), $this->proRoutes );
		$addonsRoutes = array_filter( aioseo()->addons->doAddonFunction( 'api', 'getRoutes' ) );

		foreach ( $addonsRoutes as $addonRoute ) {
			$routes = array_replace_recursive(
				$addonRoute,
				$routes
			);
		}

		return $routes;
	}

	/**
	 * Pass any API class back to an addon to run a similar method.
	 *
	 * @since 4.1.0
	 *
	 * @param  \WP_REST_Request  $request   The original request.
	 * @param  \WP_REST_Response $response  An optional response.
	 * @param  string            $apiClass  The class to call.
	 * @param  string            $apiMethod The method to call on the class.
	 * @return mixed                        Anything the addon needs to return.
	 */
	public static function addonsApi( $request, $response, $apiClass, $apiMethod ) {
		$loadedAddons = aioseo()->addons->getLoadedAddons();
		if ( ! empty( $loadedAddons ) ) {
			foreach ( $loadedAddons as $addon ) {
				$class        = new \ReflectionClass( $addon );
				$addonClass   = $class->getNamespaceName() . $apiClass;
				$classExists  = class_exists( $addonClass );
				$methodExists = method_exists( $addonClass, $apiMethod );
				if ( $classExists && $methodExists ) {
					$response = call_user_func_array( [ $addonClass, $apiMethod ], [ $request, $response ] );
				}
			}
		}

		return $response;
	}

	/**
	 * Validates access from the routes array.
	 *
	 * @since 4.1.6
	 *
	 * @param  \WP_REST_Request $request The REST Request.
	 * @return bool                      True if validated, false if not.
	 */
	public function validateAccess( $request ) {
		$routeData = $this->getRouteData( $request );
		if ( empty( $routeData ) || empty( $routeData['access'] ) ) {
			return false;
		}

		// Admins always have access.
		if ( aioseo()->access->isAdmin() ) {
			return true;
		}

		switch ( $routeData['access'] ) {
			case 'any':
				// The user has access if he has any of our capabilities.
				$user       = wp_get_current_user();
				$aioseoCaps = aioseo()->access->getCapabilityList();
				foreach ( $user->get_role_caps() as $capability => $enabled ) {
					if ( $enabled && in_array( $capability, $aioseoCaps, true ) ) {
						return true;
					}
				}

				return false;
			case 'options':
				// Check that user has access to any of the options pages.
				$user       = wp_get_current_user();
				$aioseoCaps = aioseo()->access->getCapabilityList();

				// Remove all caps that start with aioseo_page_
				$aioseoCaps = array_filter( $aioseoCaps, function( $capability ) {
					return strpos( $capability, 'aioseo_page_' ) !== 0;
				} );

				foreach ( $user->get_role_caps() as $capability => $enabled ) {
					if ( $enabled && in_array( $capability, $aioseoCaps, true ) ) {
						return true;
					}
				}

				return false;
			default:
				// The user has access if he has any of the required capabilities.
				if ( ! is_array( $routeData['access'] ) ) {
					$routeData['access'] = [ $routeData['access'] ];
				}

				foreach ( $routeData['access'] as $access ) {
					if ( current_user_can( $access ) ) {
						return true;
					}
				}

				return false;
		}
	}
}