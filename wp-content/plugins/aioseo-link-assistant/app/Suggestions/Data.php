<?php
namespace AIOSEO\Plugin\Addon\LinkAssistant\Suggestions;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Addon\LinkAssistant\Models;

/**
 * Contains methods to get the data we need to send to the server and process the data we receive back.
 *
 * @since 1.0.3
 */
class Data {
	/**
	 * Returns the base data we need to include in our requests to the server.
	 *
	 * @since 1.0.3
	 *
	 * @return array List of options.
	 */
	public function getBaseData() {
		return [
			'domain'   => aioseo()->helpers->getSiteDomain(),
			'options'  => [
				'postTypes'       => aioseoLinkAssistant()->options->postTypes->all(),
				'postStatuses'    => aioseoLinkAssistant()->options->postStatuses->all(),
				'skipSentences'   => aioseoLinkAssistant()->options->skipSentences,
				'affiliatePrefix' => aioseoLinkAssistant()->options->affiliatePrefix,
				'excludePosts'    => aioseoLinkAssistant()->options->excludePosts,
				'ignoredWords'    => $this->getIgnoredWords(),
				'languageCode'    => get_locale()
			],
			'versions' => [
				'main'          => AIOSEO_VERSION,
				'linkAssistant' => AIOSEO_LINK_ASSISTANT_VERSION
			]
		];
	}

	/**
	 * Returns all posts that need to be scanned.
	 *
	 * @since 1.0.3
	 *
	 * @param  bool          $includePhrases Whether or not to include the phrases.
	 * @return array[object]                 The posts to scan.
	 */
	public function getPostsToScan( $includePhrases = false ) {
		$postsPerScan = apply_filters( 'aioseo_link_assistant_suggestions_posts_per_scan', 50 );

		$posts = $this->getPostsToScanHelper( $postsPerScan );

		if ( $includePhrases ) {
			$posts = array_map( function( $post ) {
				$post->phrases = $this->getPhrases( $post );

				unset( $post->post_content );

				return $post;
			}, $posts );
		}

		foreach ( $posts as $index => $post ) {
			if ( ! aioseoLinkAssistant()->helpers->isScannablePost( $post ) ) {
				unset( $posts[ $index ] );
			}
		}

		return $posts;
	}

	/**
	 * Checks whether there are any posts that need to be scanned.
	 *
	 * @since 1.0.3
	 *
	 * @return bool Whether there are posts that need to be scanned.
	 */
	public function arePostsToScan() {
		$posts = $this->getPostsToScanHelper( 1 );

		return count( $posts );
	}

	/**
	 * Helper method for getPostsToScan() and arePostsToScan().
	 *
	 * @since 1.0.3
	 *
	 * @param  int           $limit The limit.
	 * @return array[object]        The posts to scan.
	 */
	private function getPostsToScanHelper( $limit ) {
		// If this isn't set yet, the plugin has just been installed and the full site still needs to be scanned.
		// The option should be set on the next request.
		$minimumSuggestionScanDate = aioseoLinkAssistant()->internalOptions->internal->minimumSuggestionScanDate;
		if ( empty( $minimumSuggestionScanDate ) ) {
			return [];
		}

		return $this->basePostQuery()
			->select( 'p.ID, p.post_title, p.post_name, p.post_type, p.post_status, p.post_content' )
			->join( 'aioseo_posts as ap', 'p.ID = ap.post_id' )
			->where( 'p.post_name !=', '' )
			->whereRaw( "(
				ap.post_id IS NULL OR
				ap.link_suggestions_scan_date IS NULL OR
				ap.link_suggestions_scan_date < p.post_modified_gmt OR
				ap.link_suggestions_scan_date < '$minimumSuggestionScanDate'
			)" )
			->limit( $limit )
			->run()
			->result();
	}

	/**
	 * Returns all post data.
	 *
	 * @since 1.0.3
	 *
	 * @return array[object] The posts.
	 */
	public function getAllPosts() {
		return $this->basePostQuery()
			->select( 'p.ID, p.post_title, p.post_name' )
			->run()
			->result();
	}

	/**
	 * Returns the base query.
	 *
	 * @since 1.0.3
	 *
	 * @return \AIOSEO\Plugin\Common\Utils\Database The Database class instance.
	 */
	private function basePostQuery() {
		$postTypes    = aioseoLinkAssistant()->helpers->getScannablePostTypes();
		$postStatuses = aioseo()->helpers->getPublicPostStatuses( true );

		return aioseo()->core->db->start( 'posts as p' )
			->whereIn( 'post_type', $postTypes )
			->whereIn( 'post_status', $postStatuses );
	}

	/**
	 * Parses and stores the suggestions we've received from the API in the database for multiple posts.
	 *
	 * @since 1.0.3
	 *
	 * @param  array $scannedPostsWithSuggestions The scanned posts with their suggestions from the API.
	 * @return void
	 */
	public function parseSuggestions( $scannedPostsWithSuggestions ) {
		$parsedSuggestions = [];
		foreach ( $scannedPostsWithSuggestions as $scannedPostId => $postSuggestions ) {
			if ( empty( $postSuggestions ) ) {
				continue;
			}

			$scannedPost = get_post( $scannedPostId );
			if ( ! is_a( $scannedPost, 'WP_Post' ) ) {
				continue;
			}

			$postContent = $this->getPostContent( $scannedPost );

			foreach ( $postSuggestions as $targetPostId => $suggestions ) {
				if (
					in_array( $targetPostId, $this->getUsedPostIds( $scannedPostId ), true ) ||
					(int) $targetPostId === (int) $scannedPostId
				) {
					continue;
				}

				foreach ( $suggestions as $suggestion ) {
					// It's possible we've matched with a tag attribute, e.g. the alt tag of an image. In that case, we can ignore it.
					// We also want to do some initial sanitization there to strip off shortcode content from page builders for example.
					$phrase = trim( strip_shortcodes( $suggestion->phrase ) );
					if ( empty( $phrase ) ) {
						continue;
					}

					$anchor = $this->getAnchor( $suggestion );
					if ( empty( $anchor ) ) {
						continue;
					}

					$phraseHtml = aioseoLinkAssistant()->helpers->trimParagraphTags( $suggestion->phrase );

					// Before we save the phrase, we must wrap the anchor around a link tag so that we can prefill it.
					static $permalink = [];
					if ( ! isset( $permalink[ $targetPostId ] ) ) {
						$permalink[ $targetPostId ] = get_permalink( $targetPostId );
					}

					$url                = $permalink[ $targetPostId ];
					$linkTag            = "<a href=\"{$url}\">$1</a>";
					$escapedAnchor      = aioseo()->helpers->escapeRegex( $anchor );
					$phraseHtmlWithLink = preg_replace( "/({$escapedAnchor})/i", $linkTag, (string) $phraseHtml, 1 );

					$parsedSuggestions[] = [
						'post_id'              => (int) $scannedPostId,
						'linked_post_id'       => (int) $targetPostId,
						'anchor'               => $anchor,
						'phrase'               => $phrase,
						'phrase_html'          => $phraseHtmlWithLink,
						'original_phrase_html' => $phraseHtml,
						'paragraph'            => aioseoLinkAssistant()->main->paragraph->get( $scannedPostId, $postContent, $phrase ),
						'paragraph_html'       => aioseoLinkAssistant()->main->paragraph->getHtml( $anchor, $phrase, $postContent, true )
					];
				}
			}
		}

		$this->storeSuggestions( $parsedSuggestions );
	}

	/**
	 * Stores the parsed suggestions to the DB.
	 *
	 * @since 1.0.3
	 *
	 * @param  array $suggestions The suggestions.
	 * @return void
	 */
	private function storeSuggestions( $suggestions ) {
		$insertValues = [];
		$currentDate  = gmdate( 'Y-m-d H:i:s' );
		foreach ( $suggestions as $suggestionData ) {
			$data = Models\Suggestion::sanitizeSuggestion( $suggestionData );
			if ( empty( $data ) ) {
				continue;
			}

			if ( ! Models\Suggestion::validateSuggestion( $suggestionData ) ) {
				continue;
			}

			$insertValues[] = vsprintf(
				"(%d, %d, '%s', '%s', '%s', '%s', '%s', '%s', 0, '$currentDate', '$currentDate')",
				$data
			);
		}

		if ( empty( $insertValues ) ) {
			return;
		}

		$implodedInsertValues = implode( ',', $insertValues );

		$tableName = aioseo()->core->db->prefix . 'aioseo_links_suggestions';
		aioseo()->core->db->execute(
			"INSERT INTO $tableName (`post_id`, `linked_post_id`, `anchor`, `phrase`, `phrase_html`, `original_phrase_html`, `paragraph`, `paragraph_html`, `dismissed`, `created`, `updated`)
			VALUES $implodedInsertValues"
		);
	}

	/**
	 * Get a curated list of removed and added ignored words for the API to compare against.
	 * We only send the differences to limit the bandwidth we use.
	 *
	 * @since 1.0.0
	 *
	 * @return array The ignored word differences.
	 */
	public function getIgnoredWords() {
		$pattern              = '/([\.?!][\r\n\s]+|\r|\n|\s{2,})/u';
		$wordsToIgnore        = array_map( 'trim', preg_split( $pattern, (string) aioseoLinkAssistant()->options->main->wordsToIgnore, -1, PREG_SPLIT_NO_EMPTY ) );
		$defaultWordsToIgnore = array_map( 'trim', preg_split( $pattern, (string) aioseoLinkAssistant()->options->main->getDefault( 'wordsToIgnore' ), -1, PREG_SPLIT_NO_EMPTY ) );

		$removed = array_diff( $defaultWordsToIgnore, $wordsToIgnore );
		$added   = array_diff( $wordsToIgnore, array_intersect( $defaultWordsToIgnore, $wordsToIgnore ) );

		return [
			'removed' => $removed,
			'added'   => $added
		];
	}

	/**
	 * Returns the anchor for the current suggestion/phrase.
	 *
	 * @since 1.0.0
	 *
	 * @param  Object $suggestion The suggestion.
	 * @return string             The anchor.
	 */
	private function getAnchor( $suggestion ) {
		$phrase    = trim( $suggestion->phrase );
		$firstWord = aioseo()->helpers->escapeRegex( $suggestion->words[0] );
		$lastWord  = aioseo()->helpers->escapeRegex( $suggestion->words[ count( $suggestion->words ) - 1 ] );

		preg_match( "/$firstWord.*$lastWord/u", $phrase, $anchor );
		if ( empty( $anchor[0] ) ) {
			return '';
		}

		$anchor = $anchor[0];

		// Prevent us from capturing partial words.
		$escapedAnchor = aioseo()->helpers->escapeRegex( $anchor );
		preg_match( "/{$escapedAnchor}(\b|[^.\s\\r\\n!?-]*)/i", (string) $phrase, $match );
		$fullAnchor = ! empty( $match[0] ) ? trim( $match[0] ) : $anchor;

		return $fullAnchor;
	}

	/**
	 * Returns the post content after parsing it.
	 *
	 * @since 1.0.0
	 *
	 * @param  Object $post The post object.
	 * @return string       The post content.
	 */
	private function getPostContent( $post ) {
		static $postContent = [];
		if ( isset( $postContent[ $post->ID ] ) ) {
			return $postContent[ $post->ID ];
		}

		$parsedContent = strip_shortcodes( $post->post_content );
		$parsedContent = aioseo()->helpers->pregReplace( '/&nbsp;/', ' ', $parsedContent );
		$parsedContent = aioseo()->helpers->decodeHtmlEntities( $parsedContent );

		$postContent[ $post->ID ] = $parsedContent;

		return $postContent[ $post->ID ];
	}

	/**
	 * Returns the IDs of posts that are already linked to in the given post.
	 *
	 * @since 1.0.0
	 *
	 * @param  int        $postId The post ID>
	 * @return array[int]         The used post IDs.
	 */
	private function getUsedPostIds( $postId ) {
		static $usedPostIds = [];
		if ( isset( $usedPostIds[ $postId ] ) ) {
			return $usedPostIds[ $postId ];
		}

		$links = Models\Link::getOutboundInternalLinks( $postId );

		$usedPostIds[ $postId ] = array_map( function ( $link ) {
			return $link->linked_post_id;
		}, $links );

		return $usedPostIds[ $postId ];
	}

	/**
	 * Extracts and returns the phrases from the current post.
	 *
	 * @since 1.0.0
	 *
	 * @param  Object $post The post object.
	 * @return array        The phrases.
	 */
	public function getPhrases( $post ) {
		static $postPhrases = [];
		if ( isset( $postPhrases[ $post->ID ] ) ) {
			return $postPhrases[ $post->ID ];
		}

		$postContent = $this->getPostContent( $post );

		$phrases = preg_split( '/([\.?!][\r\n\s]+|\r|\n|\s{2,})/u', (string) $postContent, -1, PREG_SPLIT_NO_EMPTY );
		$phrases = array_filter( $phrases, [ $this, 'isValidPhrase' ] );

		$skipSentences = aioseoLinkAssistant()->options->main->skipSentences;
		if ( $skipSentences ) {
			array_splice( $phrases, 0, absint( $skipSentences ) );
		}

		$postPhrases[ $post->ID ] = $phrases;

		return $postPhrases[ $post->ID ];
	}

	/**
	 * Determines whether the given phrase is valid for our purposes.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $phrase The phrase.
	 * @return bool           Whether the phrase is valid.
	 */
	private function isValidPhrase( $phrase ) {
		$phrase = trim( wp_strip_all_tags( $phrase ) );
		if ( 1 >= str_word_count( $phrase ) ) {
			return false;
		}

		// Ignore the phrase if it already contains a link or HTML tags that are to display.
		if ( preg_match( '/<a|a>|<h[0-9]|h[0-9]>|<li|li>|<ul|ul>|<ol|ol>/i', (string) $phrase ) ) {
			return false;
		}

		// Ignore JSON.
		if ( preg_match( '/".*":\s".*"|".*": {/i', (string) $phrase ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Returns a list of post IDs with cornerstone_content enabled
	 *
	 * @since 1.1.0
	 *
	 * @return array The list of post IDs with cornerstone_content enabled
	 */
	public function getCornerstoneContentPostIds() {
		return aioseo()->core->db->start( 'posts as p' )
			->join( 'aioseo_posts as ap', 'p.ID = ap.post_id' )
			->where( 'ap.pillar_content', 1 )
			->run( true, 'col' )
			->result();
	}
}