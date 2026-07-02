<?php
namespace AIOSEO\Plugin\Addon\LinkAssistant\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Addon\LinkAssistant\Models;

/**
 * Abstract class that contains common Link Assistant API callbacks.
 *
 * @since 1.0.0
 */
abstract class Common {
	/**
	 * Deletes a given link.
	 *
	 * @since 1.0.0
	 *
	 * @param  \WP_REST_Request  $request The request.
	 * @return \WP_REST_Response          The response.
	 */
	public static function linkDelete( $request ) {
		$postId = (int) $request['postId'];
		$linkId = (int) $request['linkId'];
		if ( empty( $linkId ) || empty( $postId ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'error'   => 'No valid link ID or post ID was passed.'
			], 404 );
		}

		aioseoLinkAssistant()->helpers->deleteLinksInPost( $linkId );

		return new \WP_REST_Response( [
			'success' => true
		], 200 );
	}

	/**
	 * Executes a given bulk action on links.
	 *
	 * @since 1.0.0
	 *
	 * @param  \WP_REST_Request  $request The request.
	 * @return \WP_REST_Response          The response.
	 */
	public static function linksBulk( $request ) {
		$postId   = (int) $request['postId'];
		$action   = $request['action'];
		$linkIds  = $request['linkIds'];
		$linkType = $request['linkType'];
		if ( ! $postId || ! $linkType || ! $action || ! $linkIds ) {
			return new \WP_REST_Response( [
				'success' => false,
				'error'   => 'No valid link IDs, post ID, action or link type were passed.'
			], 404 );
		}

		// We need to fetch these here because the client will never have the full set of rows, except in the metabox.
		if ( 'all' === $linkIds ) {
			$links = [];
			switch ( $linkType ) {
				case 'inboundInternal':
					$links = Models\Link::getInboundInternalLinks( $postId );
					break;
				case 'outboundInternal':
					$links = Models\Link::getOutboundInternalLinks( $postId );
					break;
				case 'affiliate':
					$links = Models\Link::getAffiliateLinks( $postId );
					break;
				case 'external':
					$links = Models\Link::getExternalLinks( $postId );
					break;
				default:
					return new \WP_REST_Response( [
						'success' => false,
						'error'   => 'No valid link type was passed.'
					], 404 );
			}

			$linkIds = array_map( function( $link ) {
				return $link->id;
			}, $links );
		}

		if ( 'delete' === $action ) {
			aioseoLinkAssistant()->helpers->deleteLinksInPost( $linkIds );
		}

		return new \WP_REST_Response( [
			'success' => true
		], 200 );
	}

	/**
	 * Dismisses the given suggestion.
	 *
	 * @since 1.0.0
	 *
	 * @param  \WP_REST_Request  $request The request.
	 * @return \WP_REST_Response          The response.
	 */
	public static function suggestionDismiss( $request ) {
		$suggestionId = (int) $request['suggestionId'];
		$postId       = (int) $request['postId'];
		if ( ! $suggestionId || ! $postId ) {
			return new \WP_REST_Response( [
				'success' => false,
				'error'   => 'No valid suggestion ID or post ID were passed.'
			], 404 );
		}

		Models\Suggestion::dismissSuggestion( $suggestionId );

		return new \WP_REST_Response( [
			'success' => true
		], 200 );
	}

	/**
	 * Processes the given bulk action on suggestions.
	 *
	 * @since 1.0.0
	 *
	 * @param  \WP_REST_Request  $request The request.
	 * @return \WP_REST_Response          The response.
	 */
	public static function suggestionsBulk( $request ) {
		$postId         = (int) $request['postId'];
		$action         = $request['action'];
		$suggestionType = $request['suggestionType'];
		$suggestionRows = $request['suggestionRows'];
		if ( ! $postId || ! $suggestionType || ! $action || ! $suggestionRows ) {
			return new \WP_REST_Response( [
				'success' => false,
				'error'   => 'No valid suggestion IDs, post ID, action or suggestion rows were passed.'
			], 404 );
		}

		// We cannot pass the link IDs to the API here because the Inner Links Report just has 5 of them.
		if ( 'all' === $suggestionRows ) {
			$suggestionRows = [];

			$rows = [];
			switch ( $suggestionType ) {
				case 'suggestionsOutbound':
					$rows = Models\Suggestion::getOutboundSuggestions( $postId );
					break;
				case 'suggestionsInbound':
					$rows = Models\Suggestion::getInboundSuggestions( $postId );
					break;
				default:
					return new \WP_REST_Response( [
						'success' => false,
						'error'   => 'No valid suggestion type was passed.'
					], 404 );
			}

			foreach ( $rows as $row ) {
				$suggestions = [];
				foreach ( $row['suggestions'] as $suggestion ) {
					$suggestions[] = json_decode( wp_json_encode( $suggestion ), true );
				}

				$row['suggestions'] = $suggestions;
				$suggestionRows[]   = $row;
			}
		}

		if ( 'add' === $action ) {
			self::addSuggestion( $suggestionRows );
		}

		if ( 'dismiss' === $action ) {
			foreach ( $suggestionRows as $suggestionRow ) {
				foreach ( $suggestionRow['suggestions'] as $suggestion ) {
					Models\Suggestion::dismissSuggestion( $suggestion['id'] );
				}
			}
		}

		return new \WP_REST_Response( [
			'success' => true,
			'links'   => aioseoLinkAssistant()->helpers->getPostLinks( $postId, 10, 0 )
		], 200 );
	}

	/**
	 * Adds one or multiple link suggestions to their respective posts.
	 *
	 * @since 1.0.0
	 *
	 * @param  Object|array[Object] $suggestionRows The suggestion rows.
	 * @return void
	 */
	private static function addSuggestion( $suggestionRows ) {
		$postContents = [];
		foreach ( $suggestionRows as $suggestionRow ) {
			$suggestionToAdd = $suggestionRow['suggestions'][0];

			$postId = $suggestionToAdd['post_id'];
			if ( ! isset( $postContents[ $postId ] ) ) {
				$post                    = aioseo()->helpers->getPost( $postId );
				$postContent             = preg_replace( '/&nbsp;/', ' ', (string) $post->post_content );
				$postContents[ $postId ] = $postContent;
			}

			$postContent   = $postContents[ $postId ];
			$newPhraseHtml = aioseoLinkAssistant()->helpers->wpKsesPhrase( $suggestionToAdd['phrase_html'] );
			// Replace double quotes with single ones for the tag attributes, as we don't know if the phrase belongs to a block attribute ("content":"Phrase").
			$newPhraseHtml = preg_replace( '/(\w{2,})="([^"]*)"/', "$1='$2'", (string) $newPhraseHtml );
			if ( ! $newPhraseHtml ) {
				continue;
			}

			$oldPhrase   = aioseo()->helpers->escapeRegex( $suggestionToAdd['original_phrase_html'] );
			$pattern     = "/$oldPhrase/i";
			$postContent = preg_replace( $pattern, $newPhraseHtml, (string) $postContent );

			// Confirm that the old phrase is no longer there.
			if ( preg_match( $pattern, (string) $postContent ) ) {
				continue;
			}

			// Store the post content so that we can continue to loop without saving the post, which would trigger the "save_post" hook.
			$postContents[ $postId ] = $postContent;

			// We must delete all suggestion that in the same row/group since they target the same phrase/post.
			foreach ( $suggestionRow['suggestions'] as $suggestionToDelete ) {
				Models\Suggestion::deleteSuggestionById( $suggestionToDelete['id'] );
			}
		}

		foreach ( $postContents as $postId => $postContent ) {
			wp_update_post( [
				'ID'           => $postId,
				'post_content' => wp_slash( $postContent )
			], true );
		}
	}

	/**
	 * Prioritizes the given post for the next scan.
	 * We'll also refresh the links right away since that's quite negligble in terms of performance.
	 *
	 * @since 1.0.0
	 *
	 * @param  \WP_REST_Request  $request The request
	 * @return \WP_REST_Response          The response.
	 */
	public static function refresh( $request ) {
		$postId = ! empty( $request['postId'] ) ? (int) $request['postId'] : 0;
		if ( ! $postId ) {
			return new \WP_REST_Response( [
				'success' => false,
				'error'   => 'No valid post ID was passed.'
			], 404 );
		}

		$post = get_post( $postId );
		if ( ! is_a( $post, 'WP_Post' ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'error'   => 'No valid post object was found.'
			], 404 );
		}

		aioseoLinkAssistant()->main->suggestions->refresh( $post );
		aioseoLinkAssistant()->main->links->scanPost( $postId );

		return new \WP_REST_Response( [
			'success' => true
		], 200 );
	}
}