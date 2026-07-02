<?php
namespace AIOSEO\Plugin\Pro\SearchStatistics;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Pro\SeoRevisions\ObjectRevisions;
use AIOSEO\Plugin\Common\SearchStatistics\Api;

/**
 * The Markers class.
 *
 * @since 4.4.0
 */
class Markers {
	/**
	 * The constructor.
	 *
	 * @since 4.4.0
	 */
	public function __construct() {
		add_action( 'save_post', [ $this, 'clearCache' ] );
	}

	/**
	 * Adds the timeline markers (Google updates, SEO revisions and post revisions) to the data.
	 *
	 * @since 4.4.0
	 *
	 * @param  array $data   The data to add the timeline markers to.
	 * @param  int   $postId The post ID.
	 * @return array         The data with the timeline markers added.
	 */
	public function addTimelineMarkers( $data, $postId = null ) {
		$items = [];

		foreach ( $this->getGoogleUpdates() as $update ) {
			$date             = date( 'Y-m-d', $update['date'] );
			$update['type']   = 'googleUpdate';
			$items[ $date ][] = $update;
		}

		if ( ! empty( $postId ) ) {
			$wordPressRevisions = $this->getWordPressRevisions( $postId );
			$aioseoRevisions    = $this->getAioseoRevisions( $postId );

			if ( ! empty( $wordPressRevisions ) ) {
				foreach ( $wordPressRevisions as $date => $revision ) {
					$revision['type'] = 'wpRevision';
					$items[ $date ][] = $revision;
				}
			}

			if ( ! empty( $aioseoRevisions ) ) {
				foreach ( $aioseoRevisions as $date => $revision ) {
					$revision['type'] = 'aioseoRevision';
					$items[ $date ][] = $revision;
				}
			}
		}

		$data['timelineMarkers'] = (object) $this->filter( $items, [
			'startDate' => ! empty( $data['intervals'] ) ? reset( $data['intervals'] )['date'] : '',
			'endDate'   => ! empty( $data['intervals'] ) ? end( $data['intervals'] )['date'] : ''
		] );

		return $data;
	}

	/**
	 * Filter the timeline marker items.
	 *
	 * @since 4.4.0
	 *
	 * @param  array $items The timeline marker items.
	 * @param  array $args  The arguments.
	 * @return array        The filtered timeline marker items.
	 */
	private function filter( $items, $args ) {
		if ( ! empty( $args['startDate'] ) ) {
			$items = array_filter( $items, function( $date ) use ( $args ) {
				return $date >= $args['startDate'];
			}, ARRAY_FILTER_USE_KEY );
		}

		if ( ! empty( $args['endDate'] ) ) {
			$items = array_filter( $items, function( $date ) use ( $args ) {
				return $date <= $args['endDate'];
			}, ARRAY_FILTER_USE_KEY );
		}

		return $items;
	}

	/**
	 * Get the Google updates items.
	 *
	 * @since 4.4.0
	 *
	 * @return array The Google updates list.
	 */
	private function getGoogleUpdates() {
		$updates = aioseo()->core->cache->get( 'aioseo_search_statistics_google_updates' );
		if ( null === $updates ) {
			$api      = new Api\Request( 'google-search-console/google-updates/', [], 'GET' );
			$response = $api->request();

			if ( is_wp_error( $response ) || ! empty( $response['error'] ) || empty( $response['data'] ) ) {
				aioseo()->core->cache->update( 'aioseo_search_statistics_google_updates', [], DAY_IN_SECONDS );

				return [];
			}

			$updates = $response['data'];

			aioseo()->core->cache->update( 'aioseo_search_statistics_google_updates', $updates, WEEK_IN_SECONDS );
		}

		return $updates;
	}

	/**
	 * Get the WordPress revisions items.
	 *
	 * @since 4.4.0
	 *
	 * @param  int   $postId The post ID.
	 * @return array         The WordPress revisions list.
	 */
	private function getWordPressRevisions( $postId ) {
		$post = get_post( $postId );
		if ( empty( $post ) ) {
			return [];
		}

		require_once ABSPATH . 'wp-admin/includes/revision.php';
		if ( ! wp_revisions_enabled( $post ) ) {
			return [];
		}

		$revisions = aioseo()->core->cache->get( 'aioseo_search_statistics_wp_revisions_' . $postId );
		if ( null === $revisions ) {
			$wpRevisions = wp_get_post_revisions( $postId, [
				'order'   => 'ASC',
				'orderby' => 'date ID'
			] );

			if ( empty( $wpRevisions ) ) {
				aioseo()->core->cache->update( 'aioseo_search_statistics_wp_revisions_' . $postId, $revisions, DAY_IN_SECONDS * 7 );

				return [];
			}

			// First split revisions by date.
			$revisionsPerDate = [];
			foreach ( $wpRevisions as $revision ) {
				if ( wp_is_post_autosave( $revision ) ) {
					continue;
				}

				$revisionsPerDate[ date( 'Y-m-d', strtotime( $revision->post_date ) ) ][] = $revision;
			}

			// Now we will build the array with the comparisons between revisions.
			$revisions        = [];
			$previousRevision = null;
			foreach ( $revisionsPerDate as $date => $dateRevisions ) {
				// Compare from the first revision of the date.
				$compareFrom = $dateRevisions[0];

				// If we have revision from a previous date, compare from that revision.
				if ( 1 === count( $dateRevisions ) && ! empty( $previousRevision ) ) {
					$compareFrom = $previousRevision;
				}

				// Compare to the last revision of the date.
				$compareTo = $dateRevisions[ count( $dateRevisions ) - 1 ];

				$revisionDiff   = wp_get_revision_ui_diff( $postId, $compareFrom, $compareTo );
				$revisionFields = wp_list_pluck( $revisionDiff, 'name' );

				$revisions[ $date ] = [
					'totals' => count( $dateRevisions ),
					'diff'   => $revisionDiff,
					'fields' => array_values( $revisionFields ),
					'link'   => get_edit_post_link( $compareTo )
				];

				// Store the last revision of the date to compare later.
				$previousRevision = $compareTo;
			}

			aioseo()->core->cache->update( 'aioseo_search_statistics_wp_revisions_' . $postId, $revisions, DAY_IN_SECONDS * 7 );
		}

		return $revisions;
	}

	/**
	 * Get the AIOSEO revisions items.
	 *
	 * @since 4.4.0
	 *
	 * @param  int   $postId The post ID.
	 * @return array         The AIOSEO revisions list.
	 */
	private function getAioseoRevisions( $postId ) {
		$revisions = aioseo()->core->cache->get( 'aioseo_search_statistics_aioseo_revisions_' . $postId );

		if ( null === $revisions ) {
			$objectRevisions = new ObjectRevisions( $postId, 'post' );
			$aioseoRevisions = $objectRevisions->getRevisions( [ 'orderBy' => 'created ASC' ] );

			if ( empty( $aioseoRevisions ) ) {
				aioseo()->core->cache->update( 'aioseo_search_statistics_aioseo_revisions_' . $postId, [], DAY_IN_SECONDS * 7 );

				return [];
			}

			$aioseoRevisions = array_values( $aioseoRevisions );
			$currentRevision = $aioseoRevisions[ count( $aioseoRevisions ) - 1 ]->formatRevision();

			// First split revisions by date.
			$revisionsPerDate = [];
			foreach ( $aioseoRevisions as $revision ) {
				$revisionsPerDate[ date( 'Y-m-d', strtotime( $revision->created ) ) ][] = $revision->formatRevision();
			}

			// Now we will build the array with the comparisons between first and last revision of the date.
			$revisions        = [];
			$previousRevisionId = null;
			foreach ( $revisionsPerDate as $date => $dateRevisions ) {
				$compareFromId = 0;
				$compareToId   = $dateRevisions[ count( $dateRevisions ) - 1 ]['id'];

				if ( ! empty( $previousRevisionId ) ) {
					$compareFromId = $previousRevisionId;
				}

				$revisionDiff   = $objectRevisions->getFieldsDiff( $compareFromId, $compareToId );
				$revisionDiff   = wp_list_filter( $revisionDiff, [ 'diff' => '' ], 'NOT' );
				$revisionFields = wp_list_pluck( $revisionDiff, 'label' );

				$revisions[ $date ] = [
					'totals'    => count( $dateRevisions ),
					'revisions' => array_values( $dateRevisions ),
					'fields'    => array_values( $revisionFields ),
					'compareTo' => $currentRevision,
				];

				// Store the last revision of the date to compare later.
				$previousRevisionId = $compareToId;
			}

			aioseo()->core->cache->update( 'aioseo_search_statistics_aioseo_revisions_' . $postId, $revisions, DAY_IN_SECONDS * 7 );
		}

		return $revisions;
	}

	/**
	 * Clear the cache for the given post ID.
	 *
	 * @since 4.4.0
	 *
	 * @param  int  $postId The post ID.
	 * @return void
	 */
	public function clearCache( $postId ) {
		aioseo()->core->cache->delete( 'aioseo_search_statistics_wp_revisions_' . $postId );
		aioseo()->core->cache->delete( 'aioseo_search_statistics_aioseo_revisions_' . $postId );
	}
}