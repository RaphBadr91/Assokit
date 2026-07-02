<?php
namespace AIOSEO\Plugin\Pro\SearchStatistics;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\SearchStatistics as CommonSearchStatistics;
use AIOSEO\Plugin\Pro\Models\SearchStatistics as SearchStatisticsModels;

/**
 * Handles the Inspection Result scan.
 *
 * @since 4.5.0
 */
class UrlInspection {
	/**
	 * Fetches the inspection results for the given paths.
	 *
	 * @since 4.8.2
	 *
	 * @param  array   $paths The paths to fetch the inspection results for.
	 * @param  boolean $force Whether to force fetching from Google API.
	 * @return array          An array containing the inspection results for the given paths.
	 */
	public function fetchInspectionResults( $paths = [], $force = false ) {
		$pathsWithResult       = array_map( '__return_null', array_flip( $paths ) );
		$quotaExceededCacheKey = 'search_statistics_url_inspection_quota_exceeded';
		$quotaExceeded         = ! empty( aioseo()->core->cache->get( $quotaExceededCacheKey ) );
		$output                = [
			'quotaExceeded'      => $quotaExceeded,
			'pathsWithResult'    => $pathsWithResult,
			'pathsWithoutResult' => [],
			'error'              => null
		];

		$objects = aioseo()->core->db->start( 'aioseo_search_statistics_objects' )
			->whereIn( 'object_path_hash', array_map( 'sha1', array_unique( $paths ) ) )
			->orderBy( 'inspection_result_date ASC' )
			->limit( 50 )
			->run()
			->models( 'AIOSEO\\Plugin\\Pro\\Models\\SearchStatistics\\WpObject' );

		$objects = aioseo()->searchStatistics->helpers->setRowKey( $objects, 'object_path' );

		// First, collect all paths that need inspection.
		foreach ( $objects as $object ) {
			if ( ! SearchStatisticsModels\WpObject::isInspectionResultValid( $object ) || true === $force ) {
				$output['pathsWithoutResult'][] = $object->object_path;
			} else {
				$output['pathsWithResult'][ $object->object_path ] = $object->inspection_result;
			}
		}

		// If there are no paths that need inspection, or we don't have enough quota, return early.
		if ( empty( $output['pathsWithoutResult'] ) || $output['quotaExceeded'] ) {
			return $output;
		}

		$api      = new CommonSearchStatistics\Api\Request( 'google-search-console/url-inspection/', [ 'paths' => $output['pathsWithoutResult'] ], 'GET' );
		$response = $api->request();
		if (
			is_wp_error( $response ) ||
			empty( $response['data'] ) ||
			! empty( $response['error'] )
		) {
			$output['error'] = $response;

			return $output;
		}

		if ( 'RESOURCE_EXHAUSTED' === ( $response['data']['data']['error']['status'] ?? '' ) ) {
			$output['quotaExceeded'] = true;

			aioseo()->core->cache->update( $quotaExceededCacheKey, true, aioseo()->searchStatistics->helpers->getNext8Am() - time() );
		}

		foreach ( $response['data'] as $path => $result ) {
			if (
				empty( $result ) ||
				! in_array( $path, $output['pathsWithoutResult'], true )
			) {
				continue;
			}

			$output['pathsWithResult'][ $path ] = $result;

			SearchStatisticsModels\WpObject::update( [
				'id'                     => $objects[ $path ]->id ?? null,
				'inspection_result'      => $result,
				'inspection_result_date' => current_time( 'mysql' ),
			] );
		}

		return $output;
	}

	/**
	 * Gets the inspection result for the given path.
	 * Returning null will force it to be fetched again on the front-end.
	 *
	 * @since 4.5.5
	 *
	 * @param  string      $path The path to get the inspection result for.
	 * @return object|null       The inspection result object or null if the object needs to be fetched again.
	 */
	public function get( $path ) {
		$wpObject = SearchStatisticsModels\WpObject::getObject( $path );

		// Returning null for the scenarios below will force the object to be fetched again.
		if ( ! SearchStatisticsModels\WpObject::isInspectionResultValid( $wpObject ) ) {
			return null;
		}

		return $wpObject->inspection_result;
	}

	/**
	 * Resets all the inspection_results and force scanning again.
	 *
	 * @since 4.5.0
	 *
	 * @return void
	 */
	public function reset() {
		aioseo()->core->db->update( 'aioseo_search_statistics_objects as asso' )
			->set(
				[
					'inspection_result'      => null,
					'inspection_result_date' => null
				]
			)
			->run();
	}
}