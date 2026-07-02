<?php
namespace AIOSEO\Plugin\Pro\SearchStatistics;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\SearchStatistics as CommonSearchStatistics;

/**
 * Class that holds our Search Statistics feature.
 *
 * @since 4.3.0
 */
class SearchStatistics extends CommonSearchStatistics\SearchStatistics {
	/**
	 * Holds the instance of the Stats class.
	 *
	 * @since 4.3.0
	 *
	 * @var Stats\Stats
	 */
	public $stats = null;

	/**
	 * Holds the instance of the Helpers class.
	 *
	 * @since 4.3.0
	 *
	 * @var Helpers
	 */
	public $helpers = null;

	/**
	 * Holds the instance of the Objects class.
	 *
	 * @since 4.3.3
	 *
	 * @var Objects
	 */
	public $objects = null;

	/**
	 * Holds the instance of the UrlInspection class.
	 *
	 * @since 4.5.0
	 *
	 * @var UrlInspection
	 */
	public $urlInspection = null;

	/**
	 * Holds the instance of the PageSpeed class.
	 *
	 * @since 4.3.0
	 *
	 * @var PageSpeed
	 */
	public $pageSpeed;

	/**
	 * Holds the instance of the Markers class.
	 *
	 * @since 4.3.0
	 *
	 * @var Markers
	 */
	public $markers;

	/**
	 * Holds the instance of the Keyword Rank Tracker class.
	 *
	 * @since 4.7.0
	 *
	 * @var KeywordRankTracker
	 */
	public $keywordRankTracker;

	/**
	 * Holds the instance of the Related Keywords class.
	 *
	 * @since 4.7.8
	 *
	 * @var RelatedKeywords
	 */
	public $relatedKeywords;

	/**
	 * Holds the instance of the Index Status class.
	 *
	 * @since 4.8.2
	 *
	 * @var IndexStatus
	 */
	public $indexStatus;

	/**
	 * Class constructor.
	 *
	 * @since 4.3.0
	 */
	public function __construct() {
		parent::__construct();

		$this->stats              = new Stats\Stats();
		$this->helpers            = new Helpers();
		$this->pageSpeed          = new PageSpeed();
		$this->objects            = new Objects();
		$this->urlInspection      = new UrlInspection();
		$this->markers            = new Markers();
		$this->keywordRankTracker = new KeywordRankTracker();
		$this->relatedKeywords    = new RelatedKeywords();
		$this->indexStatus        = new IndexStatus();
	}

	/**
	 * Returns the data for Vue.
	 *
	 * @since 4.3.0
	 *
	 * @return array The data for Vue.
	 */
	public function getVueData() {
		$dateRange = aioseo()->searchStatistics->stats->getDateRange();

		$data = [
			'latestAvailableDate' => aioseo()->searchStatistics->stats->latestAvailableDate,
			'unverifiedSite'      => aioseo()->searchStatistics->stats->unverifiedSite,
			'rolling'             => aioseo()->internalOptions->internal->searchStatistics->rolling,
			'authedSite'          => aioseo()->searchStatistics->api->auth->getAuthedSite(),
			'quotaExceeded'       => [
				'urlInspection' => ! empty( aioseo()->core->cache->get( 'search_statistics_url_inspection_quota_exceeded' ) )
			],
			'data'                => [
				'seoStatistics'   => $this->getSeoOverviewData( $dateRange ),
				'keywords'        => [],
				'contentRankings' => $this->getContentRankingsData( $dateRange )
			]
		];

		try {
			$data['data']['keywords'] = $this->getKeywordsData( [
				'startDate' => $dateRange['start'],
				'endDate'   => $dateRange['end']
			] );
		} catch ( \Exception $e ) {
			// Do nothing.
		}

		return $data;
	}

	/**
	 * Resets the Search Statistics.
	 *
	 * @since 4.6.2
	 *
	 * @return void
	 */
	public function reset() {
		parent::reset();

		// Resets the results for the URL Inspection.
		aioseo()->searchStatistics->urlInspection->reset();
	}

	/**
	 * Returns the SEO Overview data.
	 *
	 * @since 4.3.0
	 *
	 * @param  array $dateRange The date range.
	 * @return array            The SEO Overview data.
	 */
	protected function getSeoOverviewData( $dateRange = [] ) {
		if (
			! aioseo()->license->hasCoreFeature( 'search-statistics', 'seo-statistics' ) ||
			! aioseo()->searchStatistics->api->auth->isConnected()
		) {
			return parent::getSeoOverviewData( $dateRange );
		}

		$cacheArgs = [
			aioseo()->searchStatistics->api->auth->getAuthedSite(),
			$dateRange['start'],
			$dateRange['end'],
			aioseo()->settings->tablePagination['searchStatisticsSeoStatistics'],
			'0',
			'all',
			'',
			'DESC',
			'clicks',
			''
		];

		$cacheHash  = sha1( implode( ',', $cacheArgs ) );
		$cachedData = aioseo()->core->cache->get( "aioseo_search_statistics_seo_statistics_{$cacheHash}" );
		if ( $cachedData ) {
			if ( ! empty( $cachedData['pages']['paginated']['rows'] ) ) {
				$cachedData = aioseo()->searchStatistics->stats->posts->addPostData( $cachedData, 'statistics' );
				$cachedData = aioseo()->searchStatistics->markers->addTimelineMarkers( $cachedData );

				$cachedData['pages']['paginated']['filters']           = aioseo()->searchStatistics->stats->posts->getFilters( 'all', '' );
				$cachedData['pages']['paginated']['additionalFilters'] = aioseo()->searchStatistics->stats->posts->getAdditionalFilters();
			}

			return $cachedData;
		}

		return [];
	}

	/**
	 * Returns the Keywords data.
	 *
	 * @since   4.3.0
	 * @version 4.7.2 Added the $args parameter.
	 *
	 * @param  array      $args The arguments.
	 * @throws \Exception
	 * @return array            The Keywords data.
	 */
	public function getKeywordsData( $args = [] ) {
		if (
			! aioseo()->license->hasCoreFeature( 'search-statistics', 'keyword-rankings' ) ||
			! aioseo()->searchStatistics->api->auth->isConnected()
		) {
			return parent::getKeywordsData();
		}

		$startDate  = ! empty( $args['startDate'] ) ? $args['startDate'] : '';
		$endDate    = ! empty( $args['endDate'] ) ? $args['endDate'] : '';
		$rolling    = ! empty( $args['rolling'] ) ? $args['rolling'] : '';
		$limit      = ! empty( $args['limit'] ) ? $args['limit'] : aioseo()->settings->tablePagination['searchStatisticsKeywordRankings'];
		$offset     = ! empty( $args['offset'] ) ? $args['offset'] : 0;
		$filter     = ! empty( $args['filter'] ) ? $args['filter'] : 'all';
		$searchTerm = ! empty( $args['searchTerm'] ) ? sanitize_text_field( $args['searchTerm'] ) : '';
		$orderDir   = ! empty( $args['orderDir'] ) ? strtoupper( $args['orderDir'] ) : 'DESC';
		$orderBy    = ! empty( $args['orderBy'] ) ? aioseo()->helpers->toCamelCase( $args['orderBy'] ) : 'clicks';

		// If we're on the Top Losing/Top Winning pages, then we need to override the default ORDER BY/ORDER DIR.
		if ( 'all' !== $filter ) {
			if ( 'topLosing' === $filter ) {
				$orderBy  = 'decay';
				$orderDir = 'ASC';
			} elseif ( 'topWinning' === $filter ) {
				$orderBy  = 'decay';
				$orderDir = 'DESC';
			}
		}

		if ( empty( $startDate ) || empty( $endDate ) ) {
			throw new \Exception( 'Invalid date range.' );
		}

		// Set the date range and rolling value.
		aioseo()->searchStatistics->stats->setDateRange( $startDate, $endDate );
		if ( aioseo()->internalOptions->searchStatistics->rolling !== $rolling ) {
			aioseo()->internalOptions->searchStatistics->rolling = $rolling;
		}

		$cacheArgs = [
			aioseo()->searchStatistics->api->auth->getAuthedSite(),
			$startDate,
			$endDate,
			$limit,
			$offset,
			$filter,
			$searchTerm,
			$orderDir,
			$orderBy
		];

		$cacheHash  = sha1( implode( ',', $cacheArgs ) );
		$cachedData = aioseo()->core->cache->get( "aioseo_search_statistics_keywords_{$cacheHash}" );
		if ( null !== $cachedData ) {
			return [
				'data'  => $cachedData,
				'range' => aioseo()->searchStatistics->stats->getDateRange()
			];
		}

		$args = [
			'start'      => $startDate,
			'end'        => $endDate,
			'pagination' => [
				'limit'      => $limit,
				'offset'     => $offset,
				'filter'     => $filter,
				'searchTerm' => $searchTerm,
				'orderDir'   => $orderDir,
				'orderBy'    => $orderBy
			]
		];

		$api      = new CommonSearchStatistics\Api\Request( 'google-search-console/statistics/keywords/', $args, 'POST' );
		$response = $api->request();
		if ( is_wp_error( $response ) || ! empty( $response['error'] ) || empty( $response['data'] ) ) {
			aioseo()->core->cache->update( "aioseo_search_statistics_keywords_{$cacheHash}", false, 60 );

			return [
				'data'  => false,
				'range' => aioseo()->searchStatistics->stats->getDateRange()
			];
		}

		$data = $response['data'];

		// Add localized filters to paginated data.
		$data['paginated']['filters'] = aioseo()->searchStatistics->stats->keywords->getFilters( $filter, $searchTerm );

		aioseo()->core->cache->update( "aioseo_search_statistics_keywords_{$cacheHash}", $data, MONTH_IN_SECONDS );

		return [
			'data'  => $data,
			'range' => aioseo()->searchStatistics->stats->getDateRange()
		];
	}

	/**
	 * Returns the Content Rankings data.
	 *
	 * @since   4.3.6
	 * @version 4.7.2 Added the $args parameter.
	 *
	 * @param  array $args The arguments.
	 * @return array       The Content Rankings data.
	 */
	public function getContentRankingsData( $args = [] ) {
		if (
			! aioseo()->license->hasCoreFeature( 'search-statistics', 'content-rankings' ) ||
			! aioseo()->searchStatistics->api->auth->isConnected()
		) {
			return parent::getContentRankingsData();
		}

		$limit             = ! empty( $args['limit'] ) ? $args['limit'] : aioseo()->settings->tablePagination['searchStatisticsKeywordRankings'];
		$offset            = ! empty( $args['offset'] ) ? $args['offset'] : 0;
		$searchTerm        = ! empty( $args['searchTerm'] ) ? sanitize_text_field( $args['searchTerm'] ) : '';
		$orderDir          = ! empty( $args['orderDir'] ) ? strtoupper( $args['orderDir'] ) : 'ASC';
		$orderBy           = ! empty( $args['orderBy'] ) ? aioseo()->helpers->toCamelCase( $args['orderBy'] ) : 'decay';
		$additionalFilters = ! empty( $args['additionalFilters'] ) ? $args['additionalFilters'] : [];

		$endDate    = ! empty( $args['endDate'] ) ? $args['endDate'] : aioseo()->searchStatistics->stats->latestAvailableDate; // We do last available date for the end date.
		$startDate  = date( 'Y-m-d', strtotime( $endDate . ' - 1 year' ) );

		$postType = ! empty( $additionalFilters['postType'] ) ? $additionalFilters['postType'] : '';

		$postData = [];
		if ( $searchTerm || in_array( $orderBy, [ 'postTitle', 'lastUpdated' ], true ) ) {
			$postData = aioseo()->searchStatistics->stats->posts->getPostData( [ 'searchTerm' => $searchTerm ] );
		}

		$cacheArgs = [
			aioseo()->searchStatistics->api->auth->getAuthedSite(),
			$startDate,
			$endDate,
			$limit,
			$offset,
			$searchTerm,
			$postType,
			$orderDir,
			$orderBy
		];

		$cacheHash  = sha1( implode( ',', $cacheArgs ) );
		$cachedData = aioseo()->core->cache->get( "aioseo_search_statistics_cont_rankings_{$cacheHash}" );
		if ( null !== $cachedData ) {
			if ( false !== $cachedData && ! isset( $cachedData['error'] ) ) {
				// Add post objects to rows.
				$cachedData = aioseo()->searchStatistics->stats->posts->addPostData( $cachedData, 'contentRankings' );

				$cachedData['paginated']['additionalFilters'] = aioseo()->searchStatistics->stats->posts->getAdditionalFilters();
			}

			return [
				'data'  => $cachedData,
				'range' => aioseo()->searchStatistics->stats->getDateRange()
			];
		}

		$args = [
			'start'      => $startDate,
			'end'        => $endDate,
			'pagination' => [
				'limit'      => $limit,
				'offset'     => $offset,
				'searchTerm' => $searchTerm,
				'orderDir'   => $orderDir,
				'orderBy'    => $orderBy,
				'postData'   => $postData,
				'objects'    => aioseo()->searchStatistics->stats->posts->getPostObjectPaths( $postType )
			]
		];

		$api      = new CommonSearchStatistics\Api\Request( 'google-search-console/statistics/content-rankings/', $args, 'POST' );
		$response = $api->request();

		if ( is_wp_error( $response ) || ! empty( $response['error'] ) || empty( $response['data'] ) ) {
			aioseo()->core->cache->update( "aioseo_search_statistics_cont_rankings_{$cacheHash}", false, 60 );

			return [
				'data'  => false,
				'range' => aioseo()->searchStatistics->stats->getDateRange()
			];
		}

		$data = $response['data'];

		aioseo()->core->cache->update( "aioseo_search_statistics_cont_rankings_{$cacheHash}", $data, MONTH_IN_SECONDS );

		// Add post objects to rows.
		$data = aioseo()->searchStatistics->stats->posts->addPostData( $data, 'contentRankings' );

		$data['paginated']['additionalFilters'] = aioseo()->searchStatistics->stats->posts->getAdditionalFilters();

		return [
			'data'  => $data,
			'range' => aioseo()->searchStatistics->stats->getDateRange()
		];
	}

	/**
	 * Returns the content performance data.
	 *
	 * @since 4.7.2
	 *
	 * @param  array      $args The arguments.
	 * @throws \Exception
	 * @return array            The content performance data.
	 */
	public function getSeoStatisticsData( $args = [] ) {
		// Check if Search Statistics is connected
		if ( ! aioseo()->searchStatistics->api->auth->isConnected() ) {
			return [
				'data'  => false,
				'error' => 'Search Statistics is not connected. Please connect your Google Search Console account.',
				'range' => aioseo()->searchStatistics->stats->getDateRange()
			];
		}

		$startDate         = ! empty( $args['startDate'] ) ? $args['startDate'] : '';
		$endDate           = ! empty( $args['endDate'] ) ? $args['endDate'] : '';
		$rolling           = ! empty( $args['rolling'] ) ? $args['rolling'] : '';
		$limit             = ! empty( $args['limit'] ) ? $args['limit'] : aioseo()->settings->tablePagination['searchStatisticsSeoStatistics'];
		$offset            = ! empty( $args['offset'] ) ? $args['offset'] : 0;
		$filter            = ! empty( $args['filter'] ) ? $args['filter'] : 'all';
		$searchTerm        = ! empty( $args['searchTerm'] ) ? sanitize_text_field( $args['searchTerm'] ) : '';
		$orderDir          = ! empty( $args['orderDir'] ) ? strtoupper( $args['orderDir'] ) : 'DESC';
		$orderBy           = ! empty( $args['orderBy'] ) ? aioseo()->helpers->toCamelCase( $args['orderBy'] ) : 'clicks';
		$additionalFilters = ! empty( $args['additionalFilters'] ) ? $args['additionalFilters'] : [];

		$postType = ! empty( $additionalFilters['postType'] ) ? $additionalFilters['postType'] : '';

		// If we're on the Top Losing/Top Winning pages, then we need to override the default ORDER BY/ORDER DIR.
		if ( 'all' !== $filter ) {
			if ( 'topLosing' === $filter ) {
				$orderBy  = 'decay';
				$orderDir = 'ASC';
			} elseif ( 'topWinning' === $filter ) {
				$orderBy  = 'decay';
				$orderDir = 'DESC';
			}
		}

		if ( empty( $startDate ) || empty( $endDate ) ) {
			throw new \Exception( 'Invalid date range.' );
		}

		$postData = [];
		if ( $searchTerm || in_array( $orderBy, [ 'postTitle', 'lastUpdated' ], true ) ) {
			$postData = aioseo()->searchStatistics->stats->posts->getPostData( [ 'searchTerm' => $searchTerm ] );
		}

		// Set the date range and rolling value.
		aioseo()->searchStatistics->stats->setDateRange( $startDate, $endDate );
		if ( aioseo()->internalOptions->searchStatistics->rolling !== $rolling ) {
			aioseo()->internalOptions->searchStatistics->rolling = $rolling;
		}

		$cacheArgs = [
			aioseo()->searchStatistics->api->auth->getAuthedSite(),
			$startDate,
			$endDate,
			$limit,
			$offset,
			$filter,
			$searchTerm,
			$orderDir,
			$orderBy,
			$postType
		];

		$cacheHash  = sha1( implode( ',', $cacheArgs ) );
		$cachedData = aioseo()->core->cache->get( "aioseo_search_statistics_seo_statistics_{$cacheHash}" );
		if ( null !== $cachedData ) {
			if ( false !== $cachedData ) {
				// Add post objects to rows.
				$cachedData = aioseo()->searchStatistics->stats->posts->addPostData( $cachedData, 'statistics' );

				// Add graph markers.
				$cachedData = aioseo()->searchStatistics->markers->addTimelineMarkers( $cachedData );

				// Add localized filters to paginated data.
				$cachedData['pages']['paginated']['filters']           = aioseo()->searchStatistics->stats->posts->getFilters( $filter, $searchTerm );
				$cachedData['pages']['paginated']['additionalFilters'] = aioseo()->searchStatistics->stats->posts->getAdditionalFilters();
			}

			return [
				'data'  => $cachedData,
				'range' => aioseo()->searchStatistics->stats->getDateRange()
			];
		}

		$args = [
			'start'      => $startDate,
			'end'        => $endDate,
			'pagination' => [
				'limit'      => $limit,
				'offset'     => $offset,
				'filter'     => $filter,
				'searchTerm' => $searchTerm,
				'orderDir'   => $orderDir,
				'orderBy'    => $orderBy,
				'postData'   => $postData,
				'objects'    => ! empty( $postType ) ? aioseo()->searchStatistics->stats->posts->getPostObjectPaths( $postType ) : false
			]
		];

		// Log request details for debugging
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf(
				"[AIOSEO Search Statistics] Making API request\nEndpoint: google-search-console/statistics/\nRequest Args: %s\nIs Connected: %s\nAuthed Site: %s",
				wp_json_encode( $args, JSON_PRETTY_PRINT ),
				aioseo()->searchStatistics->api->auth->isConnected() ? 'Yes' : 'No',
				aioseo()->searchStatistics->api->auth->getAuthedSite() ?: 'None'
			) );
		}

		$api      = new CommonSearchStatistics\Api\Request( 'google-search-console/statistics/', $args, 'POST' );
		$response = $api->request();
		if ( is_wp_error( $response ) || ! empty( $response['error'] ) || empty( $response['data'] ) ) {
			aioseo()->core->cache->update( "aioseo_search_statistics_seo_statistics_{$cacheHash}", false, 60 );

			// Log detailed error information for debugging
			$errorMessage = '';
			$errorCode    = '';
			$errorDetails = [];

			if ( is_wp_error( $response ) ) {
				$errorMessage = $response->get_error_message();
				$errorCode    = $response->get_error_code();
				$errorData    = $response->get_error_data();
				if ( $errorData ) {
					$errorDetails['error_data'] = $errorData;
				}
			} elseif ( ! empty( $response ) && is_array( $response ) ) {
				// Check for error field first
				if ( ! empty( $response['error'] ) ) {
					if ( is_string( $response['error'] ) ) {
						$errorMessage = $response['error'];
					} elseif ( is_array( $response['error'] ) ) {
						$errorDetails['error_array'] = $response['error'];
						// Try to extract message from error array
						if ( isset( $response['error']['message'] ) ) {
							$errorMessage = $response['error']['message'];
						} elseif ( isset( $response['error'][0] ) && is_string( $response['error'][0] ) ) {
							$errorMessage = $response['error'][0];
						} else {
							$errorMessage = 'API returned error array: ' . wp_json_encode( $response['error'] );
						}
					}
				}
				// Check for message field
				if ( empty( $errorMessage ) && ! empty( $response['message'] ) ) {
					$errorMessage = $response['message'];
				}
				// Check for status field
				if ( ! empty( $response['status'] ) ) {
					$errorCode = $response['status'];
				}
				// If still no error message but data is empty
				if ( empty( $errorMessage ) && empty( $response['data'] ) ) {
					$errorMessage = 'API response contains no data';
				}
			} else {
				$errorMessage = 'Invalid API response format';
			}

			// Build comprehensive error details
			$errorDetails['request_args'] = [
				'startDate' => $startDate,
				'endDate'   => $endDate,
				'filter'    => $filter,
				'limit'     => $limit,
				'offset'    => $offset
			];
			$errorDetails['is_connected']  = aioseo()->searchStatistics->api->auth->isConnected();
			$errorDetails['authed_site']  = aioseo()->searchStatistics->api->auth->getAuthedSite();
			if ( ! empty( $response ) && is_array( $response ) ) {
				$errorDetails['response_keys'] = array_keys( $response );
				// Capture all response fields for debugging
				if ( isset( $response['error'] ) ) {
					$errorDetails['response_error'] = $response['error'];
				}
				if ( isset( $response['status'] ) ) {
					$errorDetails['response_status'] = $response['status'];
				}
				if ( isset( $response['message'] ) ) {
					$errorDetails['response_message'] = $response['message'];
				}
				if ( isset( $response['data'] ) ) {
					$errorDetails['response_data_type'] = gettype( $response['data'] );
					$errorDetails['response_data_empty'] = empty( $response['data'] );
				}
				if ( isset( $response['response'] ) ) {
					$errorDetails['http_response'] = $response['response'];
				}
				// Log full response for debugging (but limit size)
				$errorDetails['full_response'] = $response;
			}

			// Log to WordPress debug log
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$logMessage = sprintf(
					"[AIOSEO Search Statistics] API Error: %s\nError Code: %s\nRequest Args: %s\nIs Connected: %s\nAuthed Site: %s\nFull Error Details: %s",
					$errorMessage ?: 'Unknown error',
					$errorCode ?: 'N/A',
					wp_json_encode( $errorDetails['request_args'] ),
					$errorDetails['is_connected'] ? 'Yes' : 'No',
					$errorDetails['authed_site'] ?: 'None',
					wp_json_encode( $errorDetails, JSON_PRETTY_PRINT )
				);
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( $logMessage );
			}

			return [
				'data'  => false,
				'error' => $errorMessage,
				'range' => aioseo()->searchStatistics->stats->getDateRange()
			];
		}

		$data = $response['data'];
		aioseo()->core->cache->update( "aioseo_search_statistics_seo_statistics_{$cacheHash}", $data, MONTH_IN_SECONDS );

		// Add post objects to rows.
		$data = aioseo()->searchStatistics->stats->posts->addPostData( $data, 'statistics' );

		// Add graph markers.
		$data = aioseo()->searchStatistics->markers->addTimelineMarkers( $data );

		// Add localized filters to paginated data.
		$data['pages']['paginated']['filters']           = aioseo()->searchStatistics->stats->posts->getFilters( $filter, $searchTerm );
		$data['pages']['paginated']['additionalFilters'] = aioseo()->searchStatistics->stats->posts->getAdditionalFilters();

		return [
			'data'  => $data,
			'range' => aioseo()->searchStatistics->stats->getDateRange()
		];
	}

	/**
	 * Returns the post detail SEO statistics data.
	 *
	 * @since 4.7.2
	 *
	 * @param  array      $args The arguments.
	 * @throws \Exception
	 * @return array            The post detail SEO statistics data.
	 */
	public function getPostDetailSeoStatisticsData( $args = [], $markers = true ) {
		if ( ! aioseo()->license->hasCoreFeature( 'search-statistics', 'post-detail-seo-statistics' ) ) {
			return parent::getContentRankingsData();
		}

		$startDate = ! empty( $args['startDate'] ) ? $args['startDate'] : '';
		$endDate   = ! empty( $args['endDate'] ) ? $args['endDate'] : '';
		$postId    = ! empty( $args['postId'] ) ? $args['postId'] : '';

		if ( empty( $startDate ) || empty( $endDate ) ) {
			throw new \Exception( 'Invalid date range.' );
		}

		if ( empty( $postId ) || ! is_numeric( $postId ) ) {
			throw new \Exception( 'Invalid post id.' );
		}

		aioseo()->searchStatistics->stats->setDateRange( $startDate, $endDate );

		$permalink = get_permalink( $postId );
		$page      = aioseo()->searchStatistics->helpers->getPageSlug( $permalink );

		$baseUrl = untrailingslashit( aioseo()->searchStatistics->api->auth->getAuthedSite() );
		$pageUrl = $baseUrl . $page;
		$args    = [
			'start' => $startDate,
			'end'   => $endDate,
			'page'  => $pageUrl
		];

		$cacheArgs = [
			$startDate,
			$endDate,
			$pageUrl
		];

		$cacheHash  = sha1( implode( ',', $cacheArgs ) );
		$cachedData = aioseo()->core->cache->get( "aioseo_search_statistics_page_stats_{$cacheHash}" );
		if ( null !== $cachedData ) {
			if ( false !== $cachedData && $markers ) {
				// Add graph markers.
				$cachedData = aioseo()->searchStatistics->markers->addTimelineMarkers( $cachedData, $postId );
			}

			return [
				'data'  => $cachedData,
				'range' => aioseo()->searchStatistics->stats->getDateRange()
			];
		}

		$api      = new CommonSearchStatistics\Api\Request( 'google-search-console/statistics/page/', $args, 'POST' );
		$response = $api->request();
		if ( is_wp_error( $response ) || ! empty( $response['error'] ) || empty( $response['data'] ) ) {
			aioseo()->core->cache->update( "aioseo_search_statistics_page_stats_{$cacheHash}", false, 60 );

			return [
				'data'  => false,
				'range' => aioseo()->searchStatistics->stats->getDateRange()
			];
		}

		$data = $response['data'];
		aioseo()->core->cache->update( "aioseo_search_statistics_page_stats_{$cacheHash}", $response['data'], MONTH_IN_SECONDS );

		if ( $markers ) {
			// Add graph markers.
			$data = aioseo()->searchStatistics->markers->addTimelineMarkers( $data, $postId );
		}

		return [
			'data' => $data,
		];
	}

	/**
	 * Returns all scheduled Search Statistics related actions.
	 *
	 * @since 4.6.2
	 *
	 * @return array The Search Statistics actions.
	 */
	protected function getActionSchedulerActions() {
		return array_merge(
			parent::getActionSchedulerActions(),
			[
				$this->objects->action
			]
		);
	}
}