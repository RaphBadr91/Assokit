<?php

namespace AIOSEO\Plugin\Pro\SearchStatistics;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\SearchStatistics as CommonSearchStatistics;

class RelatedKeywords {
	/**
	 * The prefix to use for cache keys.
	 *
	 * @since 4.7.8
	 *
	 * @var string
	 */
	private $cachePrefix = 'aioseo_related_keywords_';

	/**
	 * Get related keywords for a given keyword.
	 *
	 * @since 4.7.8
	 *
	 * @param  string $keyword The keyword to find related terms for.
	 * @param  int    $limit   The maximum number of related keywords to return.
	 * @return array           An array of related keywords.
	 */
	public function getRelatedKeywords( $keyword, $limit = 10 ) {
		$cacheKey      = $this->cachePrefix . aioseo()->helpers->createHash( $keyword );
		$cachedResults = aioseo()->core->cache->get( $cacheKey );
		if ( null !== $cachedResults ) {
			return array_filter( array_slice( (array) $cachedResults, 0, $limit ) );
		}

		$requestArgs = [
			'keywords' => [ $keyword ],
		];
		$api         = new CommonSearchStatistics\Api\Request( 'google-search-console/related-keywords/', $requestArgs, 'GET' );
		$response    = $api->request();

		$results    = false;
		$expiration = MONTH_IN_SECONDS; // Set the default expiration to 1 month.
		if ( is_wp_error( $response ) || ! empty( $response['error'] ) ) {
			$expiration = MINUTE_IN_SECONDS; // Decrease the expiration in case the request was unsuccessful.
		} elseif ( ! empty( $response['data'][ $keyword ] ) ) {
			$results = $response['data'][ $keyword ];
			$results = array_filter( $results, function ( $result ) use ( $keyword ) {
				return $keyword !== $result;
			} );
		}

		aioseo()->core->cache->update( $cacheKey, $results, $expiration );

		return array_filter( array_slice( (array) $results, 0, $limit ) );
	}
}