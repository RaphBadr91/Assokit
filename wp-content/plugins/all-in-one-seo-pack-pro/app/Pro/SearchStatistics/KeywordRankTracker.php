<?php

namespace AIOSEO\Plugin\Pro\SearchStatistics;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Pro\Models\SearchStatistics as SearchStatisticsModels;
use AIOSEO\Plugin\Common\SearchStatistics as CommonSearchStatistics;

/**
 * Keyword Rank Tracker class.
 *
 * @since 4.7.0
 */
class KeywordRankTracker extends CommonSearchStatistics\KeywordRankTracker {
	/**
	 * All Keyword Rank Tracker related UI options.
	 *
	 * @since 4.7.0
	 *
	 * @var array
	 */
	public $options = [];

	/**
	 * Class constructor.
	 *
	 * @since 4.7.0
	 *
	 * @return void
	 */
	public function __construct() {
		$this->setOptions();
	}

	/**
	 * Retrieves all the keywords, formatted.
	 *
	 * @since 4.7.0
	 *
	 * @param  array $args The arguments.
	 * @return array       The formatted keywords.
	 */
	public function getFormattedKeywords( $args = [] ) {
		static $staticOutput = [];

		$staticKey = aioseo()->helpers->createHash( $args );
		if ( isset( $staticOutput[ $staticKey ] ) ) {
			return $staticOutput[ $staticKey ];
		}

		$keywords = SearchStatisticsModels\Keyword::getKeywords( $args );
		foreach ( $keywords['rows'] as $row ) {
			SearchStatisticsModels\Keyword::parseKeyword( $row );
		}

		$total    = 0;
		$orderBy  = ! empty( $args['orderBy'] ) ? $args['orderBy'] : 'created';
		$orderDir = ! empty( $args['orderDir'] ) ? $args['orderDir'] : 'DESC';
		$limit    = ! empty( $args['limit'] ) ? intval( $args['limit'] ) : aioseo()->settings->tablePagination['searchStatisticsKrtKeywords'];
		$offset   = ! empty( $args['offset'] ) ? intval( $args['offset'] ) : 0;

		if ( ! empty( $keywords['rows'] ) ) {
			try {
				$this->appendKeywordStatistics( $keywords['rows'], $args );

				if ( in_array( $orderBy, [ 'clicks', 'ctr', 'impressions', 'position' ], true ) ) {
					usort( $keywords['rows'], function ( $a, $b ) use ( $orderBy ) {
						return ( $a->statistics[ $orderBy ] ?? '-' ) <=> ( $b->statistics[ $orderBy ] ?? '-' );
					} );
				}

				if ( in_array( $orderBy, [ 'name', 'created' ], true ) ) {
					usort( $keywords['rows'], function ( $a, $b ) use ( $orderBy ) {
						return $a->$orderBy <=> $b->$orderBy;
					} );
				}

				if ( stripos( $orderDir, 'desc' ) !== false ) {
					$keywords['rows'] = array_reverse( $keywords['rows'] );
				}

				$total = count( $keywords['rows'] );

				$allRows = $keywords['rows'];
				if ( 1 < $limit ) {
					$keywords['rows'] = array_slice( $keywords['rows'], $offset, $limit );
				}
			} catch ( \Exception $e ) {
				// Do nothing.
			}
		}

		$staticOutput[ $staticKey ] = [
			'all'       => [
				'rows' => $allRows ?? []
			],
			'paginated' => [
				'rows'     => $keywords['rows'],
				'totals'   => [
					'total' => $total,
					'pages' => $pages = ( 0 === $total ? 1 : ceil( $total / $limit ) ),
					'page'  => min( $pages, 0 === $offset ? 1 : ( $offset / $limit ) + 1 )
				],
				'orderBy'  => $orderBy,
				'orderDir' => $orderDir,
			]
		];

		return $staticOutput[ $staticKey ];
	}

	/**
	 * Retrieves all the related keywords, formatted.
	 *
	 * @since 4.7.8
	 *
	 * @param  string $keyword The keyword to find related terms for.
	 * @param  array $args     The arguments.
	 * @return array           The formatted related keywords.
	 */
	public function getFormattedRelatedKeywords( $keyword, $args = [] ) {
		$keywords = aioseo()->searchStatistics->relatedKeywords->getRelatedKeywords( $keyword );
		if ( ! empty( $keywords ) ) {
			$keywords = array_map( function ( $k ) {
				return [
					'name'       => $k,
					'statistics' => null,
				];
			}, $keywords );

			try {
				$this->appendKeywordStatistics( $keywords, $args );
			} catch ( \Exception $e ) {
				// Do nothing.
			}
		}

		return [
			'paginated' => [
				'rows' => $keywords,
			]
		];
	}

	/**
	 * Retrieves the keywords' statistics. Also append statistics to each keyword that don't have them and cache them.
	 *
	 * @since 4.7.0
	 *
	 * @param  array       $formattedKeywords The formatted keywords.
	 * @param  array       $args              The arguments.
	 * @return array|false                    The statistics for the keywords. False if the request was unsuccessful.
	 */
	public function fetchKeywordsStatistics( &$formattedKeywords = [], $args = [] ) {
		$args = wp_parse_args( $args, [
			'startDate'      => '',
			'endDate'        => '',
			'pageExpression' => ''
		] );

		if ( empty( $args['startDate'] ) || empty( $args['endDate'] ) ) {
			return [];
		}

		$formattedKeywords = json_decode( wp_json_encode( $formattedKeywords ) );
		$keywordNames      = array_column( $formattedKeywords, 'name' );

		sort( $keywordNames );

		$allRowsCacheKey   = 'aioseo_krt_keywords_statistics_' . aioseo()->helpers->createHash( $args['startDate'], $args['endDate'] );
		$allRowsStatistics = aioseo()->core->cache->get( $allRowsCacheKey );
		if ( empty( $args['pageExpression'] ) ) {
			if (
				( $allRowsStatistics['keywords'] ?? null ) === $keywordNames &&
				! array_filter( $formattedKeywords, function ( $keyword ) {
					return is_null( $keyword->statistics );
				} )
			) {
				// All single keyword already have statistics, the general statistics for all rows are cached and the keyword names are the same.
				return $allRowsStatistics;
			}
		}

		$requestArgs = [
			'start'          => $args['startDate'],
			'end'            => $args['endDate'],
			'keywords'       => $keywordNames,
			'pageExpression' => $args['pageExpression'],
		];
		$api         = new CommonSearchStatistics\Api\Request( 'google-search-console/keyword-rank-tracker/', $requestArgs, 'POST' );
		$response    = $api->request();

		foreach ( $formattedKeywords as $row ) {
			if ( ! is_null( $row->statistics ) ) {
				continue;
			}

			$cacheKey      = 'aioseo_krt_keyword_' . aioseo()->helpers->createHash( $row->name, $args['startDate'], $args['endDate'], $args['pageExpression'] );
			$rowStatistics = false;
			$expiration    = MONTH_IN_SECONDS; // Set the default expiration to 1 month.
			if ( is_wp_error( $response ) || ! empty( $response['error'] ) ) {
				$expiration = MINUTE_IN_SECONDS; // Decrease the expiration in case the request was unsuccessful.
			} elseif ( ! empty( $response['data']['paginated']['rows'][ $row->name ] ) ) {
				$rowStatistics = $response['data']['paginated']['rows'][ $row->name ];
			}

			if ( ! empty( $rowStatistics['history'] ) ) {
				aioseo()->helpers->usortByKey( $rowStatistics['history'], 'date' );
			}

			$row->statistics = $rowStatistics;

			aioseo()->core->cache->update( $cacheKey, $rowStatistics, $expiration );
		}

		if ( empty( $args['pageExpression'] ) ) {
			if (
				! is_null( $allRowsStatistics ) &&
				( $allRowsStatistics['keywords'] ?? null ) === $keywordNames
			) {
				return $allRowsStatistics;
			}

			$allRowsStatistics = false;
			$expiration        = MONTH_IN_SECONDS; // Set the default expiration to 1 month.
			if ( is_wp_error( $response ) || ! empty( $response['error'] ) ) {
				$expiration = MINUTE_IN_SECONDS; // Decrease the expiration in case the request was unsuccessful.
			} elseif ( ! empty( $response['data']['statistics'] ) ) {
				$response['data']['statistics']['keywords'] = $keywordNames;

				$allRowsStatistics = $response['data']['statistics'];
			}

			if ( ! empty( $allRowsStatistics['distributionIntervals'] ) ) {
				aioseo()->helpers->usortByKey( $allRowsStatistics['distributionIntervals'], 'date' );
			}

			aioseo()->core->cache->update( $allRowsCacheKey, $allRowsStatistics, $expiration );
		}

		return $allRowsStatistics ?? false;
	}

	/**
	 * Appends the statistics to each keyword row.
	 *
	 * @since 4.7.0
	 *
	 * @param  array|object $rows The keyword rows.
	 * @param  array        $args The arguments.
	 * @return void
	 */
	public function appendKeywordStatistics( &$rows, $args ) {
		$args = wp_parse_args( $args, [
			'startDate'      => '',
			'endDate'        => '',
			'pageExpression' => ''
		] );

		if ( empty( $args['startDate'] ) || empty( $args['endDate'] ) ) {
			return;
		}

		$rows = json_decode( wp_json_encode( $rows ) );
		foreach ( $rows as $row ) {
			if ( ! is_null( $row->statistics ) ) {
				continue;
			}

			$cacheKey    = 'aioseo_krt_keyword_' . aioseo()->helpers->createHash( $row->name, $args['startDate'], $args['endDate'], $args['pageExpression'] );
			$cachedValue = aioseo()->core->cache->get( $cacheKey );

			// Prevent the statistics from being set to an empty array.
			$row->statistics = is_array( $cachedValue ) && empty( $cachedValue ) ? null : $cachedValue;
		}
	}

	/**
	 * Retrieves all the keyword groups, formatted.
	 *
	 * @since 4.7.0
	 *
	 * @param  array $args The arguments.
	 * @return array       The formatted keyword groups.
	 */
	public function getFormattedGroups( $args = [] ) {
		static $staticOutput = [];

		$staticKey = aioseo()->helpers->createHash( $args );
		if ( isset( $staticOutput[ $staticKey ] ) ) {
			return $staticOutput[ $staticKey ];
		}

		$groups = SearchStatisticsModels\KeywordGroup::getGroups( $args );
		foreach ( $groups['rows'] as $row ) {
			SearchStatisticsModels\KeywordGroup::parseGroup( $row );
		}

		$total    = 0;
		$orderBy  = ! empty( $args['orderBy'] ) ? $args['orderBy'] : 'created';
		$orderDir = ! empty( $args['orderDir'] ) ? $args['orderDir'] : 'DESC';
		$limit    = ! empty( $args['limit'] ) ? intval( $args['limit'] ) : aioseo()->settings->tablePagination['searchStatisticsKrtGroups'];
		$offset   = ! empty( $args['offset'] ) ? intval( $args['offset'] ) : 0;

		if ( ! empty( $groups['rows'] ) ) {
			try {
				$this->appendGroupStatistics( $groups['rows'], [
					'startDate'  => $args['startDate'] ?? '',
					'endDate'    => $args['endDate'] ?? '',
					'cachedOnly' => true,
				] );

				if ( in_array( $orderBy, [ 'clicks', 'ctr', 'impressions', 'position' ], true ) ) {
					usort( $groups['rows'], function ( $a, $b ) use ( $orderBy ) {
						return ( $a->statistics[ $orderBy ] ?? '-' ) <=> ( $b->statistics[ $orderBy ] ?? '-' );
					} );
				}

				if ( in_array( $orderBy, [ 'name', 'created' ], true ) ) {
					usort( $groups['rows'], function ( $a, $b ) use ( $orderBy ) {
						return $a->$orderBy <=> $b->$orderBy;
					} );
				}

				if ( stripos( $orderDir, 'desc' ) !== false ) {
					$groups['rows'] = array_reverse( $groups['rows'] );
				}

				$total = count( $groups['rows'] );

				$allRows = $groups['rows'];
				if ( 1 < $limit ) {
					$groups['rows'] = array_slice( $groups['rows'], $offset, $limit );
				}
			} catch ( \Exception $e ) {
				// Do nothing.
			}
		}

		$staticOutput[ $staticKey ] = [
			'all'       => [
				'rows' => $allRows ?? []
			],
			'paginated' => [
				'rows'   => $groups['rows'],
				'totals' => [
					'total' => $total,
					'pages' => $pages = ( 0 === $total ? 1 : ceil( $total / $limit ) ),
					'page'  => min( $pages, 0 === $offset ? 1 : ( $offset / $limit ) + 1 )
				]
			]
		];

		return $staticOutput[ $staticKey ];
	}

	/**
	 * Appends the statistics to each group row.
	 *
	 * @since 4.7.0
	 *
	 * @param  array|object $rows The group rows.
	 * @param  array        $args The arguments.
	 * @throws \Exception
	 * @return array              The statistics for the groups.
	 */
	public function appendGroupStatistics( &$rows, $args ) {
		$args = wp_parse_args( $args, [
			'startDate'  => '',
			'endDate'    => '',
			'cachedOnly' => false,
		] );

		if ( empty( $args['startDate'] ) || empty( $args['endDate'] ) ) {
			return [];
		}

		$rows       = json_decode( wp_json_encode( $rows ) );
		$cachedOnly = $args['cachedOnly'] ?: false;
		if ( ! $cachedOnly ) {
			foreach ( $rows as $row ) {
				$keywordNames = array_column( $row->keywords, 'name' );
				if ( empty( $keywordNames ) || ! is_null( $row->statistics ) ) {
					continue;
				}

				sort( $keywordNames );

				$requestArgs = [
					'start'    => $args['startDate'],
					'end'      => $args['endDate'],
					'keywords' => $keywordNames,
				];
				$api         = new CommonSearchStatistics\Api\Request( 'google-search-console/keyword-rank-tracker/', $requestArgs, 'POST' );
				$response    = $api->request();

				$cacheKey      = 'aioseo_krt_group_' . aioseo()->helpers->createHash( $keywordNames, $args['startDate'], $args['endDate'] );
				$rowStatistics = false;
				$expiration    = MONTH_IN_SECONDS; // Set the default expiration to 1 month.
				if ( is_wp_error( $response ) || ! empty( $response['error'] ) ) {
					$expiration = MINUTE_IN_SECONDS; // Decrease the expiration in case the request was unsuccessful.
				} elseif ( ! empty( $response['data']['paginated']['rows'] ) ) {
					$clicks      = array_column( $response['data']['paginated']['rows'], 'clicks' );
					$clicks      = array_sum( $clicks );
					$impressions = array_column( $response['data']['paginated']['rows'], 'impressions' );
					$impressions = array_sum( $impressions );
					$ctr         = array_column( $response['data']['paginated']['rows'], 'ctr' );
					$ctr         = array_sum( $ctr ) / count( $ctr );
					$position    = array_column( $response['data']['paginated']['rows'], 'position' );
					$position    = array_sum( $position ) / count( $position );

					$difference = ! empty( $response['data']['statistics']['difference'] ) ? $response['data']['statistics']['difference'] : [];

					$rowStatistics = compact( 'clicks', 'impressions', 'ctr', 'position', 'difference' );
				}

				$row->statistics = $rowStatistics;

				aioseo()->core->cache->update( $cacheKey, $rowStatistics, $expiration );
			}
		}

		$difference = [
			'clicks'      => 0,
			'impressions' => 0,
			'ctr'         => 0,
			'position'    => 0,
		];

		foreach ( $rows as $row ) {
			$keywordNames = array_column( $row->keywords, 'name' );
			if ( empty( $keywordNames ) ) {
				continue;
			}

			if ( is_null( $row->statistics ) ) {
				sort( $keywordNames );

				$cacheKey    = 'aioseo_krt_group_' . aioseo()->helpers->createHash( $keywordNames, $args['startDate'], $args['endDate'] );
				$cachedValue = aioseo()->core->cache->get( $cacheKey );

				// Prevent the statistics from being set to an empty array.
				$row->statistics = is_array( $cachedValue ) && empty( $cachedValue ) ? null : $cachedValue;
			}

			$rowStatistics = json_decode( wp_json_encode( $row->statistics ?: [] ), true );
			if ( ! empty( $rowStatistics['difference'] ) ) {
				$difference['clicks']      += $rowStatistics['difference']['clicks'] ?? 0;
				$difference['impressions'] += $rowStatistics['difference']['impressions'] ?? 0;
				$difference['ctr']         += $rowStatistics['difference']['ctr'] ?? 0;
				$difference['position']    += $rowStatistics['difference']['position'] ?? 0;
			}
		}

		$difference['ctr']      = number_format( $difference['ctr'], 2 );
		$difference['position'] = number_format( $difference['position'], 2 );

		return compact( 'difference' );
	}

	/**
	 * Returns all the site focus keywords.
	 *
	 * @since 4.7.0
	 *
	 * @return array The site focus keywords.
	 */
	public function getSiteFocusKeywords() {
		$posts = aioseo()->core->db->start( 'posts as wp' )
			->select( 'wp.ID, wp.post_title, aio.seo_score, aio.keyphrases' )
			->join( 'aioseo_posts as aio', 'aio.post_id = wp.ID' )
			->where( 'wp.post_status', 'publish' )
			->whereRaw( 'aio.keyphrases LIKE \'%focus%\'' )
			->whereRaw( 'aio.keyphrases NOT LIKE \'%focus":{"keyphrase":""%\'' )
			->orderBy( 'aio.seo_score DESC' )
			->limit( 50 )
			->run()
			->result();

		$parsed = [];
		/** @var \stdClass $post */
		foreach ( $posts as $post ) {
			// Bail if the post is not eligible for page analysis.
			if ( ! aioseo()->helpers->isTruSeoEligible( $post->ID ) ) {
				continue;
			}

			$post->keyphrases = json_decode( $post->keyphrases, true );
			if ( empty( $post->keyphrases['focus']['keyphrase'] ) ) {
				continue;
			}

			$parsed[] = [
				'postId'       => $post->ID,
				'postTitle'    => $post->post_title,
				'postEditLink' => get_edit_post_link( $post->ID, 'url' ),
				'postScores'   => [
					'truSeo' => $this->parseSeoScore( $post->seo_score ),
				],
				'label'        => aioseo()->helpers->toLowercase( $post->keyphrases['focus']['keyphrase'] ),
			];
		}

		return $parsed;
	}

	/**
	 * Retrieves the limit for the amount of keywords (license level based).
	 *
	 * @since 4.7.0
	 *
	 * @return int The amount of keywords allowed for the activated license.
	 */
	public function getLicenseKeywordsLimit() {
		if ( ! aioseo()->license->hasCoreFeature( 'search-statistics', 'keyword-rank-tracker' ) ) {
			return 0;
		}

		$limit = (int) aioseo()->license->getCoreFeatureValue( 'search-statistics', 'keyword-rank-tracker' );
		if ( ! $limit ) {
			$limit = 0;
		}

		return $limit;
	}

	/**
	 * Returns the data for Vue.
	 *
	 * @since 4.7.0
	 *
	 * @return array The data for Vue.
	 */
	public function getVueData() {
		if (
			! aioseo()->license->hasCoreFeature( 'search-statistics', 'keyword-rank-tracker' ) ||
			! aioseo()->searchStatistics->api->auth->isConnected()
		) {
			$formattedKeywords = parent::getFormattedKeywords();
			$formattedGroups   = parent::getFormattedGroups();

			return [
				// Dummy data to show on the UI.
				'keywords' => [
					'all'        => $formattedKeywords,
					'paginated'  => $formattedKeywords,
					'count'      => count( $formattedKeywords['rows'] ),
					'statistics' => parent::fetchKeywordsStatistics( $formattedKeywords ),
				],
				'groups'   => [
					'all'       => $formattedGroups,
					'paginated' => $formattedGroups,
					'count'     => count( $formattedGroups['rows'] ),
				],
			];
		}

		return [
			'keywords'          => [
				'count' => aioseo()->core->db->start( 'aioseo_search_statistics_keywords' )->count( 'id' ),
			],
			'groups'            => [
				'count' => aioseo()->core->db->start( 'aioseo_search_statistics_keyword_groups' )->count( 'id' ),
			],
			'siteFocusKeywords' => $this->getSiteFocusKeywords(),
			'options'           => $this->options,
			'keywordsLimit'     => $this->getLicenseKeywordsLimit(),
			'favoriteGroup'     => SearchStatisticsModels\KeywordGroup::getFavoriteGroup()
		];
	}

	/**
	 * Returns the data for Vue.
	 *
	 * @since 4.7.0
	 *
	 * @return array The data.
	 */
	public function getVueDataEdit() {
		if (
			! aioseo()->license->hasCoreFeature( 'search-statistics', 'keyword-rank-tracker' ) ||
			! aioseo()->searchStatistics->api->auth->isConnected()
		) {
			$formattedKeywords = parent::getFormattedKeywords();

			return [
				// Dummy data to show on the UI.
				'keywords' => [
					'all'       => $formattedKeywords,
					'paginated' => $formattedKeywords,
					'count'     => count( $formattedKeywords['rows'] ),
				],
			];
		}

		return [
			'keywords'      => [
				'count' => aioseo()->core->db->start( 'aioseo_search_statistics_keywords' )->count( 'id' ),
			],
			'keywordsLimit' => $this->getLicenseKeywordsLimit(),
		];
	}

	/**
	 * Parses the SEO score.
	 *
	 * @since 4.7.0
	 *
	 * @param  int|string $score The SEO score.
	 * @return array             The parsed SEO score.
	 */
	private function parseSeoScore( $score ) {
		$score  = intval( $score );
		$parsed = [
			'value' => $score,
			'color' => 'gray',
			'text'  => $score ? "$score/100" : esc_html__( 'N/A', 'aioseo-pro' ),
		];

		if ( $parsed['value'] > 80 ) {
			$parsed['color'] = 'green';
		} elseif ( $parsed['value'] > 50 ) {
			$parsed['color'] = 'orange';
		} elseif ( $parsed['value'] > 0 ) {
			$parsed['color'] = 'red';
		}

		return $parsed;
	}

	/**
	 * Set {@see self::$options}.
	 * Ideally set options only for Vue usage on the front-end.
	 *
	 * @since 4.7.0
	 *
	 * @return void
	 */
	private function setOptions() {
		$this->options = [
			'input' => [
				'group' => [
					'maxlength' => 100
				]
			],
		];
	}
}