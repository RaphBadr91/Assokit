<?php
namespace AIOSEO\Plugin\Pro\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Api as CommonApi;
use AIOSEO\Plugin\Common\Models as CommonModels;
use AIOSEO\Plugin\Pro\Models as ProModels;

/**
 * Route class for the API.
 *
 * @since 4.0.0
 */
class PostsTerms extends CommonApi\PostsTerms {
	/**
	 * Update post settings.
	 *
	 * @since 4.0.0
	 *
	 * @param  \WP_REST_Request  $request The REST Request
	 * @return \WP_REST_Response          The response.
	 */
	public static function updatePosts( $request ) {
		$body    = $request->get_json_params();
		$postId  = ! empty( $body['id'] ) ? intval( $body['id'] ) : null;
		$context = ! empty( $body['context'] ) ? sanitize_text_field( $body['context'] ) : 'post';

		if ( ! $postId ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => 'Post ID is missing.'
			], 400 );
		}

		if ( ! aioseo()->access->hasCapability( 'aioseo_page_general_settings' ) || ! current_user_can( 'edit_post', $postId ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => 'You are not allowed to update the post settings.'
			], 403 );
		}

		$body['id']                  = $postId;
		$body['context']             = $context;
		$body['title']               = ! empty( $body['title'] ) ? sanitize_text_field( $body['title'] ) : null;
		$body['description']         = ! empty( $body['description'] ) ? sanitize_text_field( $body['description'] ) : null;
		$body['keywords']            = ! empty( $body['keywords'] ) ? aioseo()->helpers->sanitize( $body['keywords'] ) : null;
		$body['og_title']            = ! empty( $body['og_title'] ) ? sanitize_text_field( $body['og_title'] ) : null;
		$body['og_description']      = ! empty( $body['og_description'] ) ? sanitize_text_field( $body['og_description'] ) : null;
		$body['og_article_section']  = ! empty( $body['og_article_section'] ) ? sanitize_text_field( $body['og_article_section'] ) : null;
		$body['og_article_tags']     = ! empty( $body['og_article_tags'] ) ? aioseo()->helpers->sanitize( $body['og_article_tags'] ) : null;
		$body['twitter_title']       = ! empty( $body['twitter_title'] ) ? sanitize_text_field( $body['twitter_title'] ) : null;
		$body['twitter_description'] = ! empty( $body['twitter_description'] ) ? sanitize_text_field( $body['twitter_description'] ) : null;

		$saveStatus = ( 'post' === $context ) ? CommonModels\Post::savePost( $postId, $body ) : ProModels\Term::saveTerm( $postId, $body );

		if ( ! empty( $saveStatus ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => 'Failed update query: ' . $saveStatus
			], 401 );
		}

		$response = new \WP_REST_Response( [
			'success' => true,
			'posts'   => $postId
		], 200 );

		return Api::addonsApi( $request, $response, '\\Api\\PostsTerms', 'updatePosts' );
	}

	/**
	 * Load term settings from Term screen.
	 *
	 * @since 4.5.5
	 *
	 * @param  \WP_REST_Request  $request  The REST Request
	 * @return \WP_REST_Response $response The response.
	 */
	public static function loadTermDetailsColumn( $request ) {
		$body   = $request->get_json_params();
		$ids = ! empty( $body['ids'] ) ? (array) $body['ids'] : [];

		if ( ! $ids ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => 'Term IDs are missing.'
			], 400 );
		}

		// phpcs:disable Squiz.NamingConventions.ValidVariableName
		global $wp_query;
		$isTax            = $wp_query->is_tax;
		$wp_query->is_tax = true;
		// phpcs:disable Squiz.NamingConventions.ValidVariableName

		$terms = [];
		foreach ( $ids as $termId ) {
			$terms[] = [
				'id'                => $termId,
				'titleParsed'       => aioseo()->meta->title->getTermTitle( aioseo()->helpers->getTerm( $termId ) ),
				'descriptionParsed' => aioseo()->meta->description->getTermDescription( aioseo()->helpers->getTerm( $termId ) )
			];
		}

		$wp_query->is_tax = $isTax; // phpcs:ignore Squiz.NamingConventions.ValidVariableName

		$response = new \WP_REST_Response( [
			'success' => true,
			'terms'   => $terms
		], 200 );

		return $response;
	}

	/**
	 * Update term settings from Term screen.
	 *
	 * @since 4.0.0
	 *
	 * @param  \WP_REST_Request  $request  The REST Request
	 * @return \WP_REST_Response $response The response.
	 */
	public static function updateTermDetailsColumn( $request ) {
		$body   = $request->get_json_params();
		$termId = ! empty( $body['termId'] ) ? intval( $body['termId'] ) : null;

		if ( ! $termId ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => 'Term ID is missing.'
			], 400 );
		}

		if ( ! aioseo()->access->hasCapability( 'aioseo_page_general_settings' ) || ! current_user_can( 'edit_term', $termId ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => 'You are not allowed to update the term settings.'
			], 403 );
		}

		$theTerm = aioseo()->core->db
			->start( 'aioseo_terms' )
			->where( 'term_id', $termId )
			->run()
			->model( 'AIOSEO\\Plugin\\Pro\\Models\\Term' );

		if ( $theTerm->exists() ) {
			$theTerm->title       = ! empty( $body['title'] ) ? sanitize_text_field( $body['title'] ) : '';
			$theTerm->description = ! empty( $body['description'] ) ? sanitize_text_field( $body['description'] ) : '';
			$theTerm->updated     = gmdate( 'Y-m-d H:i:s' );
		} else {
			$theTerm->term_id     = $termId;
			$theTerm->title       = ! empty( $body['title'] ) ? sanitize_text_field( $body['title'] ) : '';
			$theTerm->description = ! empty( $body['description'] ) ? sanitize_text_field( $body['description'] ) : '';
			$theTerm->created     = gmdate( 'Y-m-d H:i:s' );
			$theTerm->updated     = gmdate( 'Y-m-d H:i:s' );
		}

		$theTerm->save();

		$lastError = aioseo()->core->db->lastError();
		if ( ! empty( $lastError ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => 'Failed update query: ' . $lastError
			], 401 );
		}

		global $wp_query;
		$isTax            = $wp_query->is_tax;
		$wp_query->is_tax = true;

		$response = new \WP_REST_Response( [
			'success'         => true,
			'terms'           => $termId,
			'title'           => aioseo()->meta->title->getTermTitle( aioseo()->helpers->getTerm( $termId ) ),
			'description'     => aioseo()->meta->description->getTermDescription( aioseo()->helpers->getTerm( $termId ) ),
			'showTitle'       => apply_filters( 'aioseo_details_column_term_show_title', true, $termId ),
			'showDescription' => apply_filters( 'aioseo_details_column_term_show_desc', true, $termId )
		], 200 );

		$wp_query->is_tax = $isTax;

		return $response;
	}
}