<?php
namespace AIOSEO\Plugin\Addon\LinkAssistant\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Addon\LinkAssistant\Models;

/**
 * Handles all endpoints for the inner Domains Report.
 *
 * @since 1.0.0
 */
class DomainsReportInner {
	/**
	 * Returns paginated domain results for the inner links.
	 *
	 * @since 1.0.10
	 *
	 * @param  \WP_REST_Request  $request The request.
	 * @return \WP_REST_Response          The response.
	 */
	public static function fetchDomainsReportInner( $request ) {
		$body              = $request->get_json_params();
		$additionalFilters = ! empty( $body['additionalFilters'] ) ? $body['additionalFilters'] : [];
		$hostname          = ! empty( $additionalFilters['domain'] ) ? sanitize_text_field( $additionalFilters['domain'] ) : null;
		$offset            = ! empty( $body['offset'] ) ? intval( $body['offset'] ) : 0;
		$posts             = Models\Link::getDomainPostLinks( $hostname, 5, $offset );

		if ( empty( $posts ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'error'   => 'No rows were found.'
			], 200 );
		}

		return new \WP_REST_Response( [
			'success' => true,
			'posts'   => $posts
		], 200 );
	}

	/**
	 * Processes a bulk action on posts linking to a domain.
	 *
	 * @since 1.0.0
	 *
	 * @param  \WP_REST_Request  $request The request.
	 * @return \WP_REST_Response          The response.
	 */
	public static function bulk( $request ) {
		$body  = $request->get_json_params();
		$links = ! empty( $body['links'] ) ? $body['links'] : [];

		if ( empty( $links ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'error'   => 'No valid links were passed.'
			], 400 );
		}

		aioseoLinkAssistant()->helpers->deleteLinksInPost( $links );

		return new \WP_REST_Response( [
			'success' => true
		], 200 );
	}

	/**
	 * Deletes a given link.
	 *
	 * @since 1.0.0
	 *
	 * @param  \WP_REST_Request  $request The request.
	 * @return \WP_REST_Response          The response.
	 */
	public static function deleteLink( $request ) {
		$body = $request->get_json_params();
		$link = ! empty( $body['link'] ) ? $body['link'] : [];

		if ( empty( $link ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'error'   => 'No valid link was passed.'
			], 400 );
		}

		aioseoLinkAssistant()->helpers->deleteLinksInPost( $link['id'] );

		return new \WP_REST_Response( [
			'success' => true
		], 200 );
	}

	/**
	 * Updates a given link.
	 *
	 * @since 1.0.0
	 *
	 * @param  \WP_REST_Request  $request The request.
	 * @return \WP_REST_Response          The response.
	 */
	public static function updateLink( $request ) {
		$body        = $request->get_json_params();
		$hostname    = ! empty( $body['hostname'] ) ? $body['hostname'] : [];
		$newLinkData = ! empty( $body['link'] ) ? $body['link'] : [];

		if ( empty( $newLinkData ) || empty( $hostname ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'error'   => 'No valid link or hostname was passed.'
			], 400 );
		}

		$success = self::updateLinkInPost( $newLinkData );
		if ( ! $success ) {
			return new \WP_REST_Response( [
				'success' => false,
				'error'   => 'Couldn\'t update the post.'
			], 400 );
		}

		return new \WP_REST_Response( [
			'success' => true
		], 200 );
	}

	/**
	 * Adds a link to the given post or updates an existing one.
	 *
	 * @since 1.0.0
	 *
	 * @param  array $newLinkData An array of data for the new link.
	 * @return bool               Whether the link was successfully updated.
	 */
	private static function updateLinkInPost( $newLinkData ) {
		// Get the Link object first. We need the original phrase so that we can replace it in the post content.
		$link = Models\Link::getLinkById( $newLinkData['id'] );
		if ( ! $link->exists() ) {
			return false;
		}

		$post = aioseo()->helpers->getPost( $newLinkData['post_id'] );
		if ( ! is_object( $post ) ) {
			return false;
		}

		// Replace the old HTML phrase with the new one.
		$postContent   = str_replace( '&nbsp;', ' ', $post->post_content );
		$newPhraseHtml = aioseoLinkAssistant()->helpers->wpKsesPhrase( $newLinkData['phrase_html'] );
		if ( ! $newPhraseHtml ) {
			return false;
		}

		$oldPhraseHtml = aioseo()->helpers->escapeRegex( $link->phrase_html );
		$pattern       = "/$oldPhraseHtml/i";

		$postContent = preg_replace( $pattern, $newPhraseHtml, (string) $postContent );

		// Confirm that the old phrase is no longer there.
		if ( preg_match( $pattern, (string) $postContent ) ) {
			return false;
		}

		// Finally, we update the post with the modified post content.
		// We don't need to manually update the Link record because the "save_post" action will automatically re-scan the post.
		$error = wp_update_post( [
			'ID'           => $newLinkData['post_id'],
			'post_content' => $postContent
		], true );

		if ( 0 === $error || is_a( $error, 'WP_Error' ) ) {
			return false;
		}

		// Immediately update the link with the new phrase, paragraph and HTML versions.
		$newParagraphHtml = preg_replace( $pattern, $newPhraseHtml, $link->paragraph_html );
		$oldPhrase        = aioseo()->helpers->escapeRegex( $link->phrase );
		$pattern          = "/$oldPhrase/i";
		$newParagraph     = preg_replace( $pattern, $newLinkData['phrase'], $link->paragraph );

		$link->set( [
			'phrase'         => $newLinkData['phrase'],
			'phrase_html'    => $newLinkData['phrase_html'],
			'paragraph'      => $newParagraph,
			'paragraph_html' => $newParagraphHtml
		] );
		$link->save();

		return true;
	}
}