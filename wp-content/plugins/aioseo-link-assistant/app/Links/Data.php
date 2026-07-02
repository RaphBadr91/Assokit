<?php
namespace AIOSEO\Plugin\Addon\LinkAssistant\Links;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Addon\LinkAssistant\Models;

/**
 * Handles the extraction, parsing and storage of links for the links scan.
 *
 * @since 1.0.3
 */
class Data {
	/**
	 * Indexes the links in the given post.
	 *
	 * @since 1.0.0
	 *
	 * @param  int    $postId      The post ID.
	 * @param  string $postContent The post content.
	 * @return void
	 */
	public function indexLinks( $postId, $postContent = null ) {
		if ( empty( $postContent ) ) {
			$post = aioseo()->helpers->getPost( $postId );
			if ( ! is_a( $post, 'WP_Post' ) ) {
				return;
			}

			$postContent = $post->post_content;
		}

		$links = $this->extractLinks( $postId, $postContent );

		// Delete all links first. We have to do this in order to remove old links that no longer exist.
		// Then, store the new ones.
		Models\Link::deleteLinks( $postId );
		$this->storeLinks( $links );
	}

	/**
	 * Stores the given links to the DB.
	 *
	 * @since 1.0.3
	 *
	 * @param  array $links The links.
	 * @return void
	 */
	private function storeLinks( $links ) {
		if ( empty( $links ) ) {
			return;
		}

		$insertValues = [];
		$currentDate  = gmdate( 'Y-m-d H:i:s' );
		foreach ( $links as $linkData ) {
			$data = Models\Link::sanitizeLink( $linkData );
			if ( empty( $data ) ) {
				continue;
			}

			if ( ! Models\Link::validateLink( $data ) ) {
				continue;
			}

			$linkedPostId = '%d';
			if ( empty( $data['linked_post_id'] ) ) {
				$linkedPostId           = '%s';
				$data['linked_post_id'] = 'null';
			}

			$insertValues[] = vsprintf(
				"(%d, $linkedPostId, %d, %d, %d, '%s', '%s', '%s', '%s', '%s', '%s', '%s', '$currentDate', '$currentDate')",
				$data
			);
		}

		$implodedInsertValues = implode( ',', $insertValues );

		$tableName = aioseo()->core->db->prefix . 'aioseo_links';
		aioseo()->core->db->execute(
			"INSERT INTO $tableName
			(`post_id`, `linked_post_id`, `internal`, `external`, `affiliate`, `url`, `hostname`, `anchor`, `phrase`, `phrase_html`, `paragraph`, `paragraph_html`, `created`, `updated`)
			VALUES $implodedInsertValues"
		);
	}

	/**
	 * Returns the links that are in the post content.
	 *
	 * @since 1.0.0
	 *
	 * @param  int    $postId      The post ID.
	 * @param  string $postContent The post content.
	 * @return array               The links.
	 */
	private function extractLinks( $postId, $postContent ) {
		$postContent = aioseo()->helpers->decodeHtmlEntities( $postContent );

		// Strip data URIs to prevent catastrophic backtracking.
		$postContent = preg_replace( '/data:[^;]+;base64,[^"]+/', '', (string) $postContent );

		// Render blocks so we can extract links from the reusable blocks as well.
		$postContent = aioseo()->helpers->doBlocks( $postContent );

		/**
		 * Regex pattern divided into groups:
		 * 0  - Full phrase with link tag.
		 * 2  - Start of the phrase, before the anchor.
		 * 4  - The URL.
		 * 6  - The anchor.
		 * 9  - The end of the phrase, after the anchor.
		 * 10 - The ending punctuation mark.
		 */
		preg_match_all(
			'/(([^\r\n.?!]*)<t?a[^>]*?href=(\"|\')([^\"\']*?)(\"|\')[^>]*?>([\s\w\W]*?)<\/t?a>|<!-- wp:core-embed\/wordpress {"url":"([^"]*?)"[^}]*?"} -->|(?:>|&nbsp;|\s)((?:(?:http|ftp|https)\:\/\/)(?:[\w_-]+(?:(?:\.[\w_-]+)+))(?:[\w.,@?^=%&:\/~+#-]*[\w@?^=%&\/~+#-]))(?:<|&nbsp;|\s))([^<>.?!\r\n]*)([.?!]?)/i', // phpcs:disable Generic.Files.LineLength.MaxExceeded
			$postContent,
			$matches
		);

		if ( empty( $matches[0] ) ) {
			return [];
		}

		$links = [];
		foreach ( $matches[0] as $k => $v ) {
			if ( empty( $matches[4][ $k ] ) || empty( $matches[6][ $k ] ) ) {
				continue;
			}

			$url      = $matches[4][ $k ];
			$hostname = wp_parse_url( $url, PHP_URL_HOST );

			// If the URL is relative, add the hostname of the site.
			if ( ! $hostname ) {
				$hostname = $this->getHostname();
				$url      = aioseo()->helpers->makeUrlAbsolute( $url );
			}

			// NOTE: We need to check this here before we strip off the "www" part.
			// Otherwise we will not be able to detect internal links on sites running on "www".
			$isInternal = $hostname === $this->getHostname();

			$hostname = aioseo()->helpers->pregReplace( '/www\./i', '', $hostname );

			// Replace br tags with spaces and then strip all other tags from the anchor.
			$anchor = preg_replace( '/<br\s*\/?>/i', ' ', $matches[6][ $k ] );
			$anchor = trim( wp_strip_all_tags( $anchor ) );

			// Remove trailing URL tags. The regex isn't sufficient for this.
			$phrase = wp_strip_all_tags( $matches[0][ $k ] );
			$phrase = trim( preg_replace( '/(.*)(<t?a[^<>].*$)/', '', (string) $phrase ) );

			// Don't continue if the anchor or phrase are empty, e.g. blank link tag.
			if ( ! $anchor || ! $phrase ) {
				continue;
			}

			$phraseHtml = aioseo()->helpers->stripIncompleteHtmlTags( $matches[0][ $k ] );
			$phraseHtml = aioseo()->helpers->stripScriptTags( $phraseHtml );
			$phraseHtml = trim( aioseoLinkAssistant()->helpers->trimParagraphTags( $phraseHtml ) );

			// For now, we'll drop list items as we can't parse them well.
			if ( empty( $phraseHtml ) || preg_match( '/<li|li>|<ol|ol>|<ul|ul>/', (string) $phraseHtml ) ) {
				continue;
			}

			$paragraph     = aioseoLinkAssistant()->main->paragraph->get( $postId, $postContent, $phrase );
			$paragraphHtml = aioseoLinkAssistant()->main->paragraph->getHtml( $anchor, $paragraph, $postContent );
			$isAffiliate   = $this->isAffiliate( $url, $phraseHtml );

			$linkData = [
				'post_id'        => (int) $postId,
				'linked_post_id' => null,
				'internal'       => $isInternal,
				'external'       => ! $isInternal,
				'affiliate'      => $isAffiliate,
				'url'            => $url,
				'hostname'       => $hostname,
				'anchor'         => $anchor,
				'phrase'         => $phrase,
				'phrase_html'    => $phraseHtml,
				'paragraph'      => $paragraph,
				'paragraph_html' => $paragraphHtml
			];

			if ( $isInternal && ! $isAffiliate ) {
				$linkedPostId = url_to_postid( $linkData['url'] );
				if ( $linkedPostId ) {
					$linkData['linked_post_id'] = (int) $linkedPostId;
				}
			}

			$links[] = $linkData;
		}

		return $links;
	}

	/**
	 * Returns the site's hostname.
	 *
	 * @since 1.0.3
	 *
	 * @return string The hostname.
	 */
	private function getHostname() {
		static $siteUrl = null;
		if ( null === $siteUrl ) {
			$siteUrl = wp_parse_url( get_site_url(), PHP_URL_HOST );
		}

		return $siteUrl;
	}

	/**
	 * Checks whether the given link is an affiliate link.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $url        The URL.
	 * @param  string $phraseHtml The phrase HTML.
	 * @return bool               Whether the link is an affiliate link.
	 */
	private function isAffiliate( $url, $phraseHtml ) {
		static $affiliatePrefixes = null;
		if ( null === $affiliatePrefixes ) {
			$affiliatePrefixes = [];

			$prefixes = json_decode( aioseoLinkAssistant()->options->main->affiliatePrefix );
			if ( $prefixes ) {
				$affiliatePrefixes = array_map( function( $prefix ) {
					return $prefix->value;
				}, $prefixes );
			}
		}

		foreach ( $affiliatePrefixes as $affiliatePrefix ) {
			if ( aioseo()->helpers->stringContains( $url, $affiliatePrefix ) ) {
				return true;
			}
		}

		// Check if the link is wrapped in a ThirstyAffiliates tag.
		if ( preg_match( '/<ta[^>]*?href=(\"|\')([^\"\']*?)(\"|\')[^>]*?>[\s\w\W]*?<\/ta>/i', (string) $phraseHtml ) ) {
			return true;
		}

		return false;
	}
}