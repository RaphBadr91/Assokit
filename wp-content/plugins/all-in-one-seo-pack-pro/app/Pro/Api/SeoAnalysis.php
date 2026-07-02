<?php
namespace AIOSEO\Plugin\Pro\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Pro\Models\Issue;
use AIOSEO\Plugin\Common\Models\Post;

/**
 * Route class for the API.
 *
 * @since 4.8.6
 */
class SeoAnalysis {
	/**
	 * Get list of issues code.
	 *
	 * @since 4.8.6
	 *
	 * @param  \WP_REST_Request  $request The REST Request
	 * @return \WP_REST_Response          The response.
	 */
	public static function getIssues( $request ) {
		$queryParams = $request->get_query_params();
		$status      = isset( $queryParams['status'] ) ? sanitize_text_field( $queryParams['status'] ) : null;

		$codes   = empty( $status ) ? aioseo()->seoAnalysis->getAllCodes() : aioseo()->seoAnalysis->getCodesByStatus( $status );
		$results = [
			'basic'       => [],
			'advanced'    => [],
			'performance' => []
		];
		$counts  = [
			'passed'  => 0,
			'warning' => 0,
			'error'   => 0
		];

		if ( empty( $codes ) ) {
			return new \WP_REST_Response( [
				'success' => true,
				'result'  => [
					'counts'  => $counts,
					'results' => $results
				],
			], 200 );
		}

		$items = Issue::getIssuesByCodes( $codes );
		if ( empty( $items ) ) {
			return new \WP_REST_Response( [
				'success' => true,
				'result'  => [
					'counts'  => $counts,
					'results' => $results
				],
			], 200 );
		}

		$passedCodes  = aioseo()->seoAnalysis->getCodesByStatus( 'passed' );
		$warningCodes = aioseo()->seoAnalysis->getCodesByStatus( 'warning' );
		$errorCodes   = aioseo()->seoAnalysis->getCodesByStatus( 'error' );
		$sortedCodes  = aioseo()->seoAnalysis->getAllCodesSortedByGroupAndStatus( $status );
		foreach ( $items as $item ) {
			if ( in_array( $item->code, $warningCodes, true ) ) {
				$status = 'warning';
			} elseif ( in_array( $item->code, $errorCodes, true ) ) {
				$status = 'error';
			} elseif ( in_array( $item->code, $passedCodes, true ) ) {
				$status = 'passed';
			}

			$group = aioseo()->seoAnalysis->getGroupByCodeAndStatus( $item->code, $status );
			if ( ! empty( $status ) && ! empty( $group ) ) {
				$results[ $group ][] = [
					'code'   => $item->code,
					'status' => $status,
					'count'  => $item->count,
					'order'  => empty( $sortedCodes ) ? null : $sortedCodes[ $group ][ $status ][ $item->code ]
				];

				$counts[ $status ] = $counts[ $status ] + $item->count;
			}
		}

		if ( ! empty( $sortedCodes ) ) {
			foreach ( $results as $group => $items ) {
				usort( $results[ $group ], function( $a, $b ) {
					return $a['order'] - $b['order'];
				});
			}
		}

		return new \WP_REST_Response( [
			'success' => true,
			'result'  => [
				'results' => $results,
				'counts'  => $counts
			]
		], 200 );
	}

	/**
	 * Get objects by issue code.
	 *
	 * @since 4.8.6
	 *
	 * @param  \WP_REST_Request  $request The REST Request
	 * @return \WP_REST_Response          The response.
	 */
	public static function getObjectsByIssueCode( $request ) {
		$params      = $request->get_params();
		$queryParams = $request->get_query_params();

		$code   = isset( $params['issueCode'] ) ? sanitize_text_field( $params['issueCode'] ) : '';
		$search = isset( $queryParams['search'] ) ? sanitize_text_field( $queryParams['search'] ) : '';
		$limit  = isset( $queryParams['limit'] ) ? (int) sanitize_text_field( $queryParams['limit'] ) : aioseo()->settings->tablePagination['seoAnalysis'];
		$offset = isset( $queryParams['offset'] ) ? (int) sanitize_text_field( $queryParams['offset'] ) : 0;

		$results      = Issue::getObjectsByIssueCode( $code, $limit, $offset, $search );
		$totalResults = Issue::getTotalObjectsByIssueCode( $code, $search );
		$page       = 0 === $offset ? 1 : ( $offset / $limit ) + 1;

		return new \WP_REST_Response( [
			'success' => true,
			'result'  => [
				'rows'            => $results,
				'hasIssueDetails' => self::hasIssueDetails( $results ),
				'totals'          => [
					'page'  => $page,
					'pages' => ceil( $totalResults / $limit ),
					'total' => $totalResults
				]
			],
		], 200 );
	}

	/**
	 * Get issues by object id.
	 *
	 * @since 4.8.6
	 *
	 * @param  \WP_REST_Request  $request The REST Request
	 * @return \WP_REST_Response          The response.
	 */
	public static function getIssuesByObject( $request ) {
		$params      = $request->get_params();
		$queryParams = $request->get_query_params();

		$objectId   = isset( $params['objectId'] ) ? sanitize_text_field( $params['objectId'] ) : '';
		$objectType = isset( $params['objectType'] ) ? sanitize_text_field( $params['objectType'] ) : '';
		$search     = isset( $queryParams['search'] ) ? sanitize_text_field( $queryParams['search'] ) : '';

		$results      = Issue::getIssuesByObject( $objectId, $objectType, $search );
		$totalResults = Issue::getTotalIssuesByObject( $objectId, $objectType, $search );

		$passedCodes  = aioseo()->seoAnalysis->getCodesByStatus( 'passed' );
		$warningCodes = aioseo()->seoAnalysis->getCodesByStatus( 'warning' );
		$errorCodes   = aioseo()->seoAnalysis->getCodesByStatus( 'error' );

		$results = array_map(function( $post ) use ( $passedCodes, $warningCodes, $errorCodes ) {
			if ( in_array( $post->code, $warningCodes, true ) ) {
				$post->status = 'warning';
			} elseif ( in_array( $post->code, $errorCodes, true ) ) {
				$post->status = 'error';
			} elseif ( in_array( $post->code, $passedCodes, true ) ) {
				$post->status = 'passed';
			}

			return $post;
		}, $results);

		return new \WP_REST_Response( [
			'success' => true,
			'result'  => [
				'rows'   => $results,
				'totals' => [
					'total' => $totalResults
				]
			],
		], 200 );
	}

	/**
	 * Get objects.
	 *
	 * @since 4.8.6
	 * @param  \WP_REST_Request  $request The REST Request
	 * @return \WP_REST_Response          The response.
	 */
	public static function getObjects( $request ) {
		$queryParams = $request->get_query_params();

		$search            = isset( $queryParams['search'] ) ? sanitize_text_field( $queryParams['search'] ) : '';
		$limit             = isset( $queryParams['limit'] ) ? (int) sanitize_text_field( $queryParams['limit'] ) : aioseo()->settings->tablePagination['seoAnalysis'];
		$offset            = isset( $queryParams['offset'] ) ? (int) sanitize_text_field( $queryParams['offset'] ) : 0;
		$additionalFilters = isset( $queryParams['additionalFilters'] ) ? $queryParams['additionalFilters'] : [];

		$results      = Issue::getResults( $limit, $offset, $search, $additionalFilters );
		$totalResults = Issue::getTotalResults( $search, $additionalFilters );

		$page = 0 === $offset ? 1 : ( $offset / $limit ) + 1;

		return new \WP_REST_Response( [
			'success' => true,
			'result'  => [
				'rows'   => $results,
				'totals' => [
					'page'  => $page,
					'pages' => ceil( $totalResults / $limit ),
					'total' => $totalResults
				]
			],
		], 200 );
	}


	/**
	 * Ignore issue by id.
	 *
	 * @since 4.8.6
	 *
	 * @param  \WP_REST_Request  $request The REST Request
	 * @return \WP_REST_Response          The response.
	 */
	public static function ignoreIssue( $request ) {
		$params = $request->get_params();
		$id     = isset( $params['id'] ) ? (int) sanitize_text_field( $params['id'] ) : 0;

		$issue = Issue::getById( $id );

		if ( ! $issue ) {
			return new \WP_REST_Response( [
				'success' => true,
			], 200 );
		}

		$issue->is_ignored = true; // @phpstan-ignore-line
		$issue->save();

		return new \WP_REST_Response( [
			'success' => true,
		], 200 );
	}

	/**
	 * Check if the results have issue details (metadata).
	 *
	 * @since 4.8.6
	 *
	 * @param  array $results The results.
	 * @return bool           True if the results have issue details, false otherwise.
	 */
	private static function hasIssueDetails( $results ) {
		foreach ( $results as $result ) {
			if ( ! empty( $result->metadata ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Add focus keyword.
	 *
	 * @since 4.8.6
	 *
	 * @param  \WP_REST_Request  $request The REST Request
	 * @return \WP_REST_Response          The response.
	 */
	public static function addFocusKeyword( $request ) {
		$params = $request->get_params();

		if ( ! isset( $params['id'] ) || ! isset( $params['keyphrase'] ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => 'Missing required parameters',
			], 400 );
		}

		$id   = (int) sanitize_text_field( $params['id'] );
		$post = Post::getPost( $id );
		if ( ! $post ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => 'Post not found',
			], 404 );
		}

		$post->keyphrases = [
			'focus' => $params['keyphrase']
		];
		$post->save();

		// Trigger a save post action to update the post and its SEO data.
		aioseo()->seoAnalysis->enqueuePostToScan( $id );

		return new \WP_REST_Response( [
			'success' => true,
		], 200 );
	}

	/**
	 * Get objects scan percent.
	 *
	 * @since 4.8.6
	 * @param  \WP_REST_Request  $request The REST Request
	 * @return \WP_REST_Response          The response.
	 */
	public static function getObjectsScanPercent( $request ) { //phpcs:ignore
		$total = aioseo()->seoAnalysis->getObjectsScanPercent();

		return new \WP_REST_Response( [
			'success' => true,
			'percent' => $total
		], 200 );
	}
}