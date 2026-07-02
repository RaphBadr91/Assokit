<?php

namespace AIOSEO\Plugin\Pro\Api\SearchStatistics;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Pro\Models\SearchStatistics as SearchStatisticsModels;

/**
 * Keyword Rank Tracker related REST API endpoint callbacks.
 *
 * @since 4.7.0
 */
class KeywordRankTracker {
	/**
	 * Creates keywords.
	 *
	 * @since 4.7.0
	 *
	 * @param  \WP_REST_Request  $request The REST Request.
	 * @return \WP_REST_Response          The response.
	 */
	public static function insertKeywords( $request ) {
		if ( ! aioseo()->searchStatistics->api->auth->isConnected() ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => 'Not connected to the Search Statistics service.' // Not shown to the user.
			], 400 );
		}

		try {
			$keywords  = (array) ( $request->get_param( 'keywords' ) ?? [] );
			$groupRows = (array) ( $request->get_param( 'groups' ) ?? [] );

			$keywords = array_map( 'sanitize_text_field', $keywords );
			$keywords = array_filter( array_unique( $keywords ) );
			if ( empty( $keywords ) ) {
				return new \WP_REST_Response( [
					'success' => false
				], 400 );
			}

			$favoriteGroupRow = current( SearchStatisticsModels\KeywordGroup::getByNames( [ SearchStatisticsModels\KeywordGroup::getFavoriteGroup()['slug'] ] ) );
			$favorited        = $favoriteGroupRow
				? ( in_array( $favoriteGroupRow->id, array_column( $groupRows, 'id' ), true ) ? 1 : 0 )
				: 0;
			$keywords         = array_map( function ( $k ) use ( $favorited ) {
				return [
					'name'      => $k,
					'favorited' => $favorited,
				];
			}, $keywords );

			$keywordRows = SearchStatisticsModels\Keyword::bulkInsert( $keywords );

			if ( ! empty( $groupRows ) ) {
				SearchStatisticsModels\KeywordRelationship::bulkInsert( array_column( $keywordRows, 'id' ), array_column( $groupRows, 'id' ) );
			}

			return new \WP_REST_Response( [
				'success' => true,
			], 200 );
		} catch ( \Exception $e ) {
			return new \WP_REST_Response( [
				'success' => false,
				'error'   => $e->getMessage()
			], 400 );
		}
	}

	/**
	 * Retrieves keywords.
	 *
	 * @since 4.7.0
	 *
	 * @param  \WP_REST_Request  $request The REST Request.
	 * @return \WP_REST_Response          The response.
	 */
	public static function fetchKeywords( $request ) {
		if ( ! aioseo()->searchStatistics->api->auth->isConnected() ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => 'Not connected to the Search Statistics service.' // Not shown to the user.
			], 400 );
		}

		$params = $request->get_params();
		if ( empty( $params['startDate'] ) || empty( $params['endDate'] ) ) {
			aioseo()->searchStatistics->stats->setDefaultDateRange();

			$dateRange           = aioseo()->searchStatistics->stats->getDateRange();
			$params['startDate'] = $dateRange['start'];
			$params['endDate']   = $dateRange['end'];
		}

		$params['pageExpression'] = aioseo()->searchStatistics->helpers->buildPageExpression( $params['postId'] ?? 0 );

		$filteredFormattedKeywords = aioseo()->searchStatistics->keywordRankTracker->getFormattedKeywords( $params );
		$allFormattedKeywords      = aioseo()->searchStatistics->keywordRankTracker->getFormattedKeywords( array_merge( $params, [
			'limit'             => aioseo()->searchStatistics->keywordRankTracker->getLicenseKeywordsLimit(),
			'filter'            => 'all',
			'searchTerm'        => '',
			'additionalFilters' => [],
			'orderBy'           => 'name',
			'orderDir'          => 'ASC',
		] ) );

		return new \WP_REST_Response( [
			'success'   => true,
			'count'     => count( $allFormattedKeywords['all']['rows'] ),
			'all'       => $allFormattedKeywords['all'],
			'paginated' => $filteredFormattedKeywords['paginated'],
		], 200 );
	}

	/**
	 * Updates keywords.
	 *
	 * @since 4.7.0
	 *
	 * @param  \WP_REST_Request  $request The REST Request.
	 * @return \WP_REST_Response          The response.
	 */
	public static function updateKeyword( $request ) {
		$id = (int) ( $request['id'] ?? 0 );
		if ( empty( $id ) ) {
			return new \WP_REST_Response( [
				'success' => false
			], 400 );
		}

		$row = new SearchStatisticsModels\Keyword( $id );
		if ( ! $row->exists() ) {
			return new \WP_REST_Response( [
				'success' => false
			], 404 );
		}

		$row->updateFavorited( boolval( $request->get_param( 'favorited' ) ) );

		return new \WP_REST_Response( [
			'success' => true,
		], 200 );
	}

	/**
	 * Deletes keywords.
	 *
	 * @since 4.7.0
	 *
	 * @param  \WP_REST_Request  $request The REST Request.
	 * @return \WP_REST_Response          The response.
	 */
	public static function deleteKeywords( $request ) {
		if ( ! aioseo()->searchStatistics->api->auth->isConnected() ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => 'Not connected to the Search Statistics service.' // Not shown to the user.
			], 400 );
		}

		$params = $request->get_params();
		$ids    = (array) ( $params['ids'] ?? [] );

		if ( empty( $ids ) ) {
			return new \WP_REST_Response( [
				'success' => false
			], 400 );
		}

		$rowsAffected = SearchStatisticsModels\Keyword::bulkDelete( $ids );

		return new \WP_REST_Response( [
			'success'      => true,
			'rowsAffected' => $rowsAffected
		], 200 );
	}

	/**
	 * Creates groups.
	 *
	 * @since 4.7.0
	 *
	 * @param  \WP_REST_Request  $request The REST Request.
	 * @return \WP_REST_Response          The response.
	 */
	public static function insertGroups( $request ) {
		$groups      = (array) ( $request->get_param( 'groups' ) ?? [] );
		$keywordRows = (array) ( $request->get_param( 'keywords' ) ?? [] );

		if ( empty( $groups ) ) {
			return new \WP_REST_Response( [
				'success' => false
			], 400 );
		}

		$favoriteGroup = SearchStatisticsModels\KeywordGroup::getFavoriteGroup();

		// For now the UI accepts only one group at a time. Which means this loop runs once.
		foreach ( $groups as $name ) {
			if ( aioseo()->helpers->toLowercase( $name ) === $favoriteGroup['slug'] ) {
				return new \WP_REST_Response( [
					'success' => false,
					// Translators: 1 - Our reserved favorite group name.
					'error'   => sprintf( __( 'The group name "%s" is reserved.', 'aioseo-pro' ), $name )
				], 400 );
			}

			$existingGroup = current( SearchStatisticsModels\KeywordGroup::getByNames( [ $name ] ) );
			if ( empty( $existingGroup ) ) {
				$groupRow       = new SearchStatisticsModels\KeywordGroup();
				$groupRow->name = $name;
				$groupRow->save();

				if ( $keywordRows ) {
					SearchStatisticsModels\KeywordRelationship::bulkInsert( array_column( $keywordRows, 'id' ), [ $groupRow->id ] );
				}
			}
		}

		return new \WP_REST_Response( [
			'success' => true,
		], 200 );
	}

	/**
	 * Retrieves groups.
	 *
	 * @since 4.7.0
	 *
	 * @param  \WP_REST_Request  $request The REST Request.
	 * @return \WP_REST_Response          The response.
	 */
	public static function fetchGroups( $request ) {
		$params                  = $request->get_params();
		$filteredFormattedGroups = aioseo()->searchStatistics->keywordRankTracker->getFormattedGroups( $params );
		$allFormattedGroups      = aioseo()->searchStatistics->keywordRankTracker->getFormattedGroups( [
			'limit'     => 100,
			'orderBy'   => 'name',
			'orderDir'  => 'ASC',
			'startDate' => $params['startDate'] ?? '',
			'endDate'   => $params['endDate'] ?? '',
		] );

		return new \WP_REST_Response( [
			'success'   => true,
			'count'     => count( $allFormattedGroups['all']['rows'] ),
			'all'       => $allFormattedGroups['all'],
			'paginated' => $filteredFormattedGroups['paginated'],
		], 200 );
	}

	/**
	 * Updates a group.
	 *
	 * @since 4.7.0
	 *
	 * @param  \WP_REST_Request  $request The REST Request.
	 * @return \WP_REST_Response          The response.
	 */
	public static function updateGroup( $request ) {
		$id = (int) ( $request['id'] ?? 0 );
		if ( empty( $id ) ) {
			return new \WP_REST_Response( [
				'success' => false
			], 400 );
		}

		$row = new SearchStatisticsModels\KeywordGroup( $id );
		if ( ! $row->exists() ) {
			return new \WP_REST_Response( [
				'success' => false
			], 404 );
		}

		$name        = $row->sanitize( 'name', $request->get_param( 'name' ) );
		$existingRow = current( SearchStatisticsModels\KeywordGroup::getByNames( [ $name ] ) );
		if ( ! empty( $existingRow ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'error'   => __( 'The group name already exists.', 'aioseo-pro' )
			], 400 );
		}

		$row->name = $name;
		$row->save();

		return new \WP_REST_Response( [
			'success' => true,
		], 200 );
	}

	/**
	 * Deletes groups.
	 *
	 * @since 4.7.0
	 *
	 * @param  \WP_REST_Request  $request The REST Request.
	 * @return \WP_REST_Response          The response.
	 */
	public static function deleteGroups( $request ) {
		$params = $request->get_params();
		$ids    = (array) ( $params['ids'] ?? [] );

		if ( empty( $ids ) ) {
			return new \WP_REST_Response( [
				'success' => false
			], 400 );
		}

		$rowsAffected = SearchStatisticsModels\KeywordGroup::bulkDelete( $ids );

		return new \WP_REST_Response( [
			'success'      => true,
			'rowsAffected' => $rowsAffected
		], 200 );
	}

	/**
	 * Updates relationships.
	 *
	 * @since 4.7.0
	 *
	 * @param  \WP_REST_Request  $request The REST Request.
	 * @return \WP_REST_Response          The response.
	 */
	public static function updateRelationships( $request ) {
		$groupRows   = (array) ( $request->get_param( 'groups' ) ?? [] );
		$groupIds   = array_column( $groupRows, 'id' );
		$keywordRows = (array) ( $request->get_param( 'keywords' ) ?? [] );
		$keywordIds = array_column( $keywordRows, 'id' );

		// The keyword IDs are required, but the group IDs are optional.
		if ( empty( $keywordIds ) ) {
			return new \WP_REST_Response( [
				'success' => false
			], 400 );
		}

		// 1. Delete all existing relationships if only one keyword is provided, and it has no groups. This makes sure we preserve the keyword current groups.
		if ( 1 === count( $keywordRows ) && $groupIds ) {
			$keywordRow    = current( $keywordRows );
			$keywordGroups = array_column( $keywordRow['groups'], 'id' );
			if ( array_diff( $keywordGroups, $groupIds ) ) {
				SearchStatisticsModels\KeywordRelationship::bulkDeleteByKeyword( $keywordIds );
			}
		}

		// 2. Delete all existing relationships if no group IDs are provided.
		if ( ! $groupIds ) {
			SearchStatisticsModels\KeywordRelationship::bulkDeleteByKeyword( $keywordIds );
		}

		// 3. Insert the new relationships if group IDs are provided.
		if ( $groupIds ) {
			SearchStatisticsModels\KeywordRelationship::bulkInsert( $keywordIds, $groupIds );
		}

		// 4. Maybe individually set keywords as favorited in case they were also added to our reserved favorite group.
		$favoriteGroupRow = current( SearchStatisticsModels\KeywordGroup::getByNames( [ SearchStatisticsModels\KeywordGroup::getFavoriteGroup()['slug'] ] ) );
		if ( ! empty( $favoriteGroupRow ) ) {
			SearchStatisticsModels\Keyword::bulkUpdate( $keywordIds, [ 'favorited' => 0 ] );

			if ( in_array( $favoriteGroupRow->id, $groupIds, true ) ) {
				SearchStatisticsModels\Keyword::bulkUpdate( $keywordIds, [ 'favorited' => 1 ] );
			}
		}

		return new \WP_REST_Response( [
			'success' => true
		], 200 );
	}

	/**
	 * Retrieves the statistics for the keywords or groups.
	 *
	 * @since 4.7.0
	 *
	 * @param  \WP_REST_Request  $request The REST Request.
	 * @return \WP_REST_Response          The response.
	 */
	public static function fetchStatistics( $request ) {
		try {
			if ( ! aioseo()->searchStatistics->api->auth->isConnected() ) {
				throw new \Exception( 'Not connected to the Search Statistics service.' );
			}

			$context = (string) ( $request->get_param( 'context' ) ?? '' );
			if ( empty( $context ) ) {
				throw new \Exception( 'No context provided.' );
			}

			// Rows coming from the front-end which already have been formatted, and some might already have cached statistics appended.
			$all       = (array) ( $request->get_param( 'all' ) ?? [] );
			$paginated = (array) ( $request->get_param( 'paginated' ) ?? [] );
			$args      = [
				'startDate' => $request->get_param( 'startDate' ),
				'endDate'   => $request->get_param( 'endDate' ),
			];

			if ( empty( $args['startDate'] ) || empty( $args['endDate'] ) ) {
				aioseo()->searchStatistics->stats->setDefaultDateRange();

				$defaultDateRange  = aioseo()->searchStatistics->stats->getDateRange();
				$args['startDate'] = $defaultDateRange['start'];
				$args['endDate']   = $defaultDateRange['end'];
			}

			if ( 'keywords' === $context ) {
				$args['pageExpression'] = aioseo()->searchStatistics->helpers->buildPageExpression( $request->get_param( 'postId' ) ?? 0 );

				$statistics = aioseo()->searchStatistics->keywordRankTracker->fetchKeywordsStatistics( $all['rows'], $args );
			}

			if ( 'groups' === $context ) {
				$statistics = aioseo()->searchStatistics->keywordRankTracker->appendGroupStatistics( $all['rows'], $args );
			}

			if ( isset( $statistics ) ) {
				// Append the statistics to the paginated rows.
				$allStatistics = array_column( $all['rows'], 'statistics', 'id' );
				foreach ( ( $paginated['rows'] ?? [] ) as $k => $paginatedRow ) {
					$paginated['rows'][ $k ]['statistics'] = $allStatistics[ $paginatedRow['id'] ] ?? $paginatedRow['statistics'];
				}

				return new \WP_REST_Response( [
					'success'    => true,
					'all'        => $all,
					'paginated'  => $paginated,
					'statistics' => $statistics
				], 200 );
			}

			throw new \Exception( 'Wrong context provided.' );
		} catch ( \Exception $e ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => $e->getMessage()
			], 400 );
		}
	}

	/**
	 * Retrieves related keywords.
	 *
	 * @since 4.7.8
	 *
	 * @param  \WP_REST_Request  $request The REST Request.
	 * @return \WP_REST_Response          The response.
	 */
	public static function fetchRelatedKeywords( $request ) {
		$params          = $request->get_params();
		$keyword         = (string) ( $params['keyword'] ?? '' );
		$relatedKeywords = aioseo()->searchStatistics->keywordRankTracker->getFormattedRelatedKeywords( $keyword, [
			'startDate' => $params['startDate'],
			'endDate'   => $params['endDate'],
		] );

		return new \WP_REST_Response( [
			'success'   => true,
			'paginated' => $relatedKeywords['paginated'],
		], 200 );
	}
}