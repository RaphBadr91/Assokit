<?php
namespace AIOSEO\Plugin\Pro\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Pro\Models as ProModels;
use AIOSEO\Plugin\Pro\SeoRevisions\ObjectRevisions;

/**
 * Seo Revisions related REST API endpoint callbacks.
 *
 * @since 4.4.0
 */
class SeoRevisions {
	/**
	 * Delete a revision.
	 *
	 * @since 4.4.0
	 *
	 * @param  \WP_REST_Request  $request The REST Request.
	 * @return \WP_REST_Response          The response.
	 */
	public static function deleteRevision( $request ) {
		$revisionId = (int) $request['id'];
		if ( empty( $revisionId ) ) {
			return new \WP_REST_Response( [
				'success' => false
			], 400 );
		}

		$revision = new ProModels\SeoRevision( $revisionId );
		if ( ! $revision->exists() ) {
			return new \WP_REST_Response( [
				'success' => false
			], 404 );
		}

		$revision->delete();

		// Clear the cache for the Search Statistics markers.
		aioseo()->searchStatistics->markers->clearCache( $revision->object_id );

		$objectRevisions = new ObjectRevisions( $revision->object_id, $revision->object_type );

		return new \WP_REST_Response( [
			'success'         => true,
			'items'           => $objectRevisions->getFormattedRevisions( [ 'limit' => aioseo()->seoRevisions->options['revisions']['per_page'] ] ),
			'itemsTotalCount' => $objectRevisions->getCount(),
		], 200 );
	}

	/**
	 * Get all revisions belonging to a post/term.
	 *
	 * @since 4.4.0
	 *
	 * @param  \WP_REST_Request  $request The REST Request.
	 * @return \WP_REST_Response          The response.
	 */
	public static function getRevisions( $request ) {
		$objectId   = ! empty( $request['id'] ) ? absint( $request['id'] ) : null;
		$objectType = ! empty( $request['context'] ) ? trim( (string) $request['context'] ) : null;

		if (
			! $objectId ||
			! $objectType
		) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => 'The request "id" and/or "context" are missing or invalid.', // Not displayed to the user.
			], 400 );
		}

		$objectRevisions = new ObjectRevisions( $objectId, $objectType );

		$args   = $request->get_params();
		$limit  = ! empty( $args['limit'] ) ? absint( $args['limit'] ) : aioseo()->seoRevisions->options['revisions']['per_page'];
		$offset = ! empty( $args['offset'] ) ? absint( $args['offset'] ) : 0;

		return new \WP_REST_Response( [
			'success'         => true,
			'items'           => $objectRevisions->getFormattedRevisions( [
				'limit'  => $limit,
				'offset' => $offset
			] ),
			'itemsTotalCount' => $objectRevisions->getCount(),
		], 200 );
	}

	/**
	 * Update a revision.
	 *
	 * @since 4.4.0
	 *
	 * @param  \WP_REST_Request   $request The REST Request.
	 * @return \WP_REST_Response           The response.
	 */
	public static function updateRevision( $request ) {
		$revisionId = (int) $request['id'];
		if ( empty( $revisionId ) ) {
			return new \WP_REST_Response( [
				'success' => false
			], 400 );
		}

		$revision = new ProModels\SeoRevision( $revisionId );
		if ( ! $revision->exists() ) {
			return new \WP_REST_Response( [
				'success' => false
			], 404 );
		}

		$revision->note = wp_kses_post( $request->get_param( 'note' ) );
		$revision->save();

		// Clear the cache for the Search Statistics markers.
		aioseo()->searchStatistics->markers->clearCache( $revision->object_id );

		return new \WP_REST_Response( [
			'success' => true,
			'item'    => $revision->formatRevision()
		], 200 );
	}

	/**
	 * Restore a revision.
	 *
	 * @since 4.4.0
	 *
	 * @param  \WP_REST_Request  $request The REST Request.
	 * @return \WP_REST_Response          The response.
	 */
	public static function restoreRevision( $request ) {
		$revisionId = (int) $request['id'];
		if ( empty( $revisionId ) ) {
			return new \WP_REST_Response( [
				'success' => false
			], 400 );
		}

		$restoreRevision = new ProModels\SeoRevision( $revisionId );
		if ( ! $restoreRevision->exists() ) {
			return new \WP_REST_Response( [
				'success' => false
			], 404 );
		}

		$objectRevisions = new ObjectRevisions( $restoreRevision->object_id, $restoreRevision->object_type );
		if ( ! $objectRevisions->restoreRevision( $restoreRevision ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => 'Restore failed.' // Not displayed to the user.
			], 400 );
		}

		// Clear the cache for the Search Statistics markers.
		aioseo()->searchStatistics->markers->clearCache( $restoreRevision->object_id );

		$formattedRow = $restoreRevision->formatRevision();
		aioseo()->wpNotices->addNotice(
			sprintf(
				// Translators: 1 - Time since the revision has been created.
				esc_html__( 'SEO data restored to revision created %1$s.', 'aioseo-pro' ),
				$formattedRow['date']['created_formatted']
			),
			'success'
		);

		return new \WP_REST_Response( [
			'success' => true,
		], 200 );
	}

	/**
	 * Get the difference between two revisions.
	 *
	 * @since 4.4.0
	 *
	 * @param  \WP_REST_Request  $request The REST Request.
	 * @return \WP_REST_Response          The response.
	 */
	public static function getRevisionsDiff( $request ) {
		$fromId          = absint( $request->get_param( 'fromId' ) );
		$toId            = absint( $request->get_param( 'toId' ) );
		$objectRevisions = ObjectRevisions::getObjectRevisions( $toId );
		if ( ! $objectRevisions ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => "Missing or invalid parameter 'toId'.", // Not displayed to the user.
			], 400 );
		}

		return new \WP_REST_Response( [
			'success' => true,
			'diff'    => $objectRevisions->getFieldsDiff( $fromId, $toId )
		], 200 );
	}
}