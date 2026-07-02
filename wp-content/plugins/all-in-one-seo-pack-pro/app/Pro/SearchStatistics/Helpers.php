<?php
namespace AIOSEO\Plugin\Pro\SearchStatistics;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Models;
use AIOSEO\Plugin\Addon\LinkAssistant\Models as LinkAssistantModels;
use AIOSEO\Plugin\Addon\Redirects\Utils as RedirectsUtils;

/**
 * Contains helper functions specific to Search Statistics.
 *
 * @since 4.3.0
 */
class Helpers {
	/**
	 * Retrieves the page expression, which Search Statistics uses to identify a page.
	 *
	 * @since 4.7.8
	 *
	 * @param  int|string $postId The post ID.
	 * @return string             The page expression for the given post ID. An empty string if a permalink for the post ID is not found.
	 */
	public function buildPageExpression( $postId ) {
		$output    = '';
		$postId    = (int) $postId;
		$permalink = get_permalink( $postId ?: 0 );
		if ( $permalink ) {
			$slug    = $this->getPageSlug( $permalink );
			$baseUrl = untrailingslashit( aioseo()->searchStatistics->api->auth->getAuthedSite() );
			$output  = $baseUrl . $slug;
		}

		return $output;
	}

	/**
	 * Maps all rows to use to the given property.
	 *
	 * @since 4.3.0
	 *
	 * @param  array  $rows     The rows.
	 * @param  string $property The property,
	 * @return array            The mapped rows.
	 */
	public function setRowKey( $rows, $property = 'keyword' ) {
		$mappedRows = [];
		foreach ( $rows as $row ) {
			$value              = is_object( $row ) ? $row->$property : $row[ $property ];
			$key                = 'page' === $property ? aioseo()->searchStatistics->helpers->getPageSlug( $value ) : strtolower( $value );
			$key                = is_numeric( $key ) ? '_' . $key : $key; // To prevent sorting issues, the key can never be numeric.
			$mappedRows[ $key ] = $row;
		}

		return $mappedRows;
	}

	/**
	 * Returns the page slug from a URL.
	 *
	 * @since 4.3.0
	 *
	 * @param  string $url The URL.
	 * @return string      The page slug.
	 */
	public function getPageSlug( $url ) {
		$siteUrl = [
			aioseo()->searchStatistics->api->auth->getAuthedSite(), // Replaces the authed site url.
			home_url() // Also replaces the home url.
		];

		$url = strtolower( trim( $url ) );
		$url = str_replace( $siteUrl, '', $url );
		$url = urldecode( $url );
		$url = wp_make_link_relative( $url );
		$url = trim( $url, '/' );

		if ( empty( $url ) ) {
			return '/';
		}

		return aioseo()->helpers->maybeRemoveTrailingSlash( '/' . $url . '/' );
	}

	/**
	 * Returns the included Search Statistics post types.
	 *
	 * @since 4.3.0
	 *
	 * @return array The included post types.
	 */
	public function getIncludedPostTypes() {
		if ( aioseo()->options->searchStatistics->postTypes->all ) {
			return aioseo()->helpers->getPublicPostTypes( true );
		}

		return aioseo()->options->searchStatistics->postTypes->included;
	}

	/**
	 * Returns the Link Assistant data for the given post ID if the addon is active.
	 *
	 * @since 4.3.0
	 *
	 * @param  int $postId The post ID.
	 * @return array       The Link Assistant data.
	 */
	public function getLinkAssistantData( $postId ) {
		if ( ! $postId ) {
			return [];
		}

		$isLinkAssistantLoaded = function_exists( 'aioseoLinkAssistant' );
		$linkAssistantAddon    = aioseo()->addons->getAddon( 'aioseo-link-assistant' );

		if ( ! $isLinkAssistantLoaded || ! aioseo()->license->isActive() || ! $linkAssistantAddon->isActive ) {
			return [];
		}

		$totalOutboundSuggestions = LinkAssistantModels\Suggestion::getTotalOutboundSuggestions( $postId );
		$totalInboundSuggestions  = LinkAssistantModels\Suggestion::getTotalInboundSuggestions( $postId );

		$totalLinks = LinkAssistantModels\Link::getLinkTotals( $postId );

		return [
			'inboundInternal'  => ! empty( $totalLinks->inboundInternal ) ? (int) $totalLinks->inboundInternal : 0,
			'outboundInternal' => ! empty( $totalLinks->outboundInternal ) ? (int) $totalLinks->outboundInternal : 0,
			'affiliate'        => ! empty( $totalLinks->affiliate ) ? (int) $totalLinks->affiliate : 0,
			'external'         => ! empty( $totalLinks->external ) ? (int) $totalLinks->external : 0,
			'linkSuggestions'  => $totalOutboundSuggestions + $totalInboundSuggestions
		];
	}

	/**
	 * Returns the Redirects data for the given post ID if the addon is active.
	 *
	 * @since 4.3.0
	 *
	 * @param  int $postId The post ID.
	 * @return array       The Redirects data.
	 */
	public function getRedirectsData( $postId ) {
		if ( ! $postId ) {
			return [];
		}

		$isRedirectsLoaded = function_exists( 'aioseoRedirects' );
		$redirectsAddon    = aioseo()->addons->getAddon( 'aioseo-redirects' );

		if (
			! $isRedirectsLoaded ||
			! aioseo()->license->isActive() ||
			! $redirectsAddon->isActive ||
			! method_exists( aioseoRedirects()->redirect, 'getRedirects' ) ||
			! method_exists( aioseoRedirects()->redirect, 'getRedirectsByTarget' )
		) {
			return [];
		}

		$from      = [];
		$to        = '';
		$toCode    = 0;
		$permalink = get_permalink( $postId );
		$targetUrl = RedirectsUtils\WpUri::excludeHomeUrl( $permalink );

		foreach ( aioseoRedirects()->redirect->getRedirects( $permalink ) as $redirect ) {
			$to     = RedirectsUtils\Request::formatTargetUrl( trim( $redirect->target_url ) );
			$toCode = $redirect->type;
			break;
		}

		foreach ( aioseoRedirects()->redirect->getRedirectsByTarget( $targetUrl ) as $redirect ) {
			$from[] = RedirectsUtils\Request::formatTargetUrl( trim( $redirect->target_url ) );
		}

		return [
			'from'   => $from,
			'to'     => $to,
			'toCode' => $toCode
		];
	}

	/**
	 * Get the suggested changes for the current post.
	 *
	 * @since   4.3.0
	 * @version 4.7.2 Moved function.
	 *
	 * @param  Models\Post $post The post to get the schema graphs.
	 * @return array             List of the suggested changes.
	 */
	public function getSuggestedChanges( $post ) {
		$analysis         = Models\Post::getPageAnalysisDefaults( $post->page_analysis );
		$suggestedChanges = [];

		foreach ( $analysis->analysis as $analysis ) {
			foreach ( $analysis as $change => $score ) {
				if ( is_object( $score ) && 1 === $score->error ) {
					$suggestedChanges[] = self::getSuggestedChangeDescription( $change, $score->score );
				}
			}
		}

		return array_values( array_filter( $suggestedChanges ) );
	}

	/**
	 * Get the suggested changes description and tooltip.
	 *
	 * @since   4.3.0
	 * @version 4.7.2 Moved function.
	 *
	 * @param  string $change The change name.
	 * @param  int    $score  The score for the current change.
	 * @return array          An array with the description and whether or not to show a tooltip.
	 */
	private function getSuggestedChangeDescription( $change, $score ) {
		// phpcs:disable Universal.Arrays.MixedArrayKeyTypes
		$keyphraseString = __( 'Focus Keyword', 'aioseo-pro' );
		$strings         = [
			'keyphraseInContent'        => [
				'3' => __( 'Your Focus Keyword was not found in your content.', 'aioseo-pro' )
			],
			'keyphraseInIntroduction'   => [
				'0' => __( 'No content added yet.', 'aioseo-pro' ),
				'3' => sprintf(
					// Translators: 1 - Focus Keyword or Keyword.
					__( 'Your %1$s does not appear in the first paragraph. Make sure the topic is clear immediately.', 'aioseo-pro' ),
					$keyphraseString
				)
			],
			'keyphraseInDescription'    => [
				'3' => sprintf(
					// Translators: 1 - Focus Keyword or Keyword.
					__( 'Your %1$s was not found in the meta description.', 'aioseo-pro' ),
					$keyphraseString
				)
			],
			'keyphraseInURL'            => [
				'1' => __( 'Focus Keyword not found in the URL.', 'aioseo-pro' )
			],
			'keyphraseLength'           => [
				'-999' => sprintf(
					// Translators: 1 - Focus Keyword or Keyword.
					__( 'No %1$s was set. Set a %1$s in order to calculate your SEO score.', 'aioseo-pro' ),
					$keyphraseString
				),
				'3'    => sprintf(
					// Translators: 1 - Focus Keyword or Keyword.
					__( 'The %1$s is too long. Try to make it shorter.', 'aioseo-pro' ),
					$keyphraseString
				),
				'6'    => sprintf(
					// Translators: 1 - Focus Keyword or Keyword.
					__( 'The %1$s is slightly long. Try to make it shorter.', 'aioseo-pro' ),
					$keyphraseString
				)
			],
			'metadescriptionLength'     => [
				'tooltip' => true,
				'1'       => __( 'No meta description has been specified. Search engines will display copy from the page instead. Make sure to write one!', 'aioseo-pro' ),
				'6'       => __( 'Your meta description may not display correctly in search results.', 'aioseo-pro' )
			],
			'lengthContent'             => [
				'6'   => __( 'The content is below the minimum of words. Add more content.', 'aioseo-pro' ),
				'3'   => __( 'The content is below the minimum of words. Add more content.', 'aioseo-pro' ),
				'-10' => __( 'This is far below the recommended minimum of words.', 'aioseo-pro' ),
				'-20' => __( 'This is far below the recommended minimum of words.', 'aioseo-pro' )
			],
			'isInternalLink'            => [
				'3' => __( 'There are not enough internal links in your content, try adding some more.', 'aioseo-pro' )
			],
			'isExternalLink'            => [
				'3' => __( 'No outbound links were found. Link out to external resources.', 'aioseo-pro' )
			],
			'keyphraseInTitle'          => [
				'3' => __( 'Your Focus Keyword was not found in the SEO title.', 'aioseo-pro' )
			],
			'keyphraseInBeginningTitle' => [
				'3' => __( 'The Focus Keyword doesn\'t appear at the beginning of the SEO title.', 'aioseo-pro' )
			],
			'titleLength'               => [
				'tooltip' => true,
				'1'       => __( 'No title has been specified. Make sure to write one!', 'aioseo-pro' ),
				'6'       => __( 'Your title may not display correctly in search results.', 'aioseo-pro' )
			],
			'contentHasAssets'          => [
				'1' => __( 'You are not using rich media like images or videos.', 'aioseo-pro' )
			],
			'paragraphLength'           => [
				'1' => __( 'At least one paragraph is long. Consider using short paragraphs.', 'aioseo-pro' )
			],
			'sentenceLength'            => [
				'tooltip' => true,
				'6'       => __( 'Some of your sentences are too long, try shortening them to improve readability.', 'aioseo-pro' )
			],
			'passiveVoice'              => [
				'tooltip' => true,
				'3'       => __( 'Try to use active counterparts on the sentences.', 'aioseo-pro' )
			],
			'transitionWords'           => [
				'tooltip' => true,
				'3'       => __( 'Use more transition words in your content.', 'aioseo-pro' ),
				'6'       => __( 'Use more transition words in your content.', 'aioseo-pro' )
			],
			'consecutiveSentences'      => [
				'tooltip' => true,
				'3'       => __( 'The text contains a high number of consecutive sentences starting with the same word. Try to mix things up!', 'aioseo-pro' )
			],
			'subheadingsDistribution'   => [
				'tooltip' => true,
				'6'       => __( 'Add subheadings to improve readability.', 'aioseo-pro' ),
				'3'       => __( 'Add subheadings to improve readability.', 'aioseo-pro' ),
				'2'       => __( 'You are not using any subheadings, although your text is rather long. Try and add some subheadings.', 'aioseo-pro' )
			],
			'calculateFleschReading'    => [
				'tooltip' => true,
				'6'       => __( 'Use less difficult words to improve readability', 'aioseo-pro' ),
				'3'       => __( 'Use less difficult words to improve readability', 'aioseo-pro' )
			],
		];
		// phpcs:enable Universal.Arrays.MixedArrayKeyTypes

		if ( ! isset( $strings[ $change ] ) || ! isset( $strings[ $change ][ $score ] ) ) {
			return [];
		}

		return [
			'text'    => $strings[ $change ][ $score ],
			'tooltip' => in_array( $change, array_keys( wp_list_filter( $strings, [ 'tooltip' => true ] ) ), true )
		];
	}

	/**
	 * Gets the timestamp for the next 8 AM (UTC-0).
	 * Google quota resets daily at 00:00 Pacific Time (UTC-8 or UTC-7 depending on daylight saving time).
	 *
	 * @since 4.8.2
	 *
	 * @return int The timestamp for the next 8 AM.
	 */
	public function getNext8Am() {
		return strtotime( '8:00 AM ' . ( date( 'H' ) >= 8 ? '+1 day' : '' ) );
	}
}