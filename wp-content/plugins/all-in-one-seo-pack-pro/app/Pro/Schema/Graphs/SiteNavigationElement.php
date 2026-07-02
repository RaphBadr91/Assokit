<?php
namespace AIOSEO\Plugin\Pro\Schema\Graphs;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Schema\Graphs as CommonGraphs;

/**
 * Site Navigation Element graph class.
 *
 * @since 4.6.8
 */
class SiteNavigationElement extends CommonGraphs\Graph {
	/**
	 * Returns the graph data.
	 *
	 * @since 4.6.8
	 *
	 * @param  object|null $graphData The graph data.
	 * @return array                  The parsed graph data.
	 */
	public function get( $graphData = null ) {
		$siteNavigations = ! empty( $graphData->properties->siteNavigations )
			? $this->getSiteNavigationsFromGraphData( $graphData->properties->siteNavigations )
			: $this->getSiteNavigationsFromTableOfContents();

		if ( empty( $siteNavigations ) ) {
			return [];
		}

		return [
			'@type'           => 'ItemList',
			'itemListElement' => $this->getSiteNavigationElementsGraphData( $siteNavigations ),
		];
	}

	/**
	 * Get site navigation elements from the graph data.
	 *
	 * @since 4.6.8
	 *
	 * @param  array $siteNavigations The site navigations.
	 * @return array                  The site navigation elements.
	 */
	private function getSiteNavigationsFromGraphData( $siteNavigations ) {
		$siteNavigationElements = [];

		foreach ( $siteNavigations as $siteNavigation ) {
			if ( empty( $siteNavigation['name'] ) || empty( $siteNavigation['url'] ) ) {
				continue;
			}

			$siteNavigationElements[] = [
				'name'        => $siteNavigation['name'],
				'url'         => $siteNavigation['url'],
				'description' => $siteNavigation['description'] ?? '',
			];
		}

		return $siteNavigationElements;
	}

	/**
	 * Get site navigation elements from the table of contents block's headings.
	 *
	 * @since 4.6.8
	 *
	 * @return array The site navigation elements.
	 */
	private function getSiteNavigationsFromTableOfContents() {
		$post = aioseo()->helpers->getPost();
		if ( ! is_a( $post, 'WP_Post' ) ) {
			return [];
		}

		$parsedBlocks           = aioseo()->helpers->parseBlocks( $post, true );
		$postUrl                = get_permalink( $post->ID );
		$siteNavigationElements = [];

		foreach ( $parsedBlocks as $block ) {
			$allHeadings = empty( $block['attrs']['headings'] ) ? 0 : count( $block['attrs']['headings'] );
			if ( isset( $block['blockName'] ) && 'aioseo/table-of-contents' === $block['blockName'] && 0 < $allHeadings ) {
				$headings = $this->flattenHeadings( $block['attrs']['headings'] );
				foreach ( $headings as $heading ) {

					$siteNavigationElements[] = [
						'name' => $heading['content'],
						'url'  => $postUrl . '#' . ( $heading['anchor'] ?? '' ),
					];
				}
			}
		}

		return $siteNavigationElements;
	}

	/**
	 * Get the site navigation elements graph data.
	 *
	 * @since 4.6.8
	 *
	 * @param  array $siteNavigations The site navigations.
	 * @return array                  The site navigation elements graph data.
	 */
	private function getSiteNavigationElementsGraphData( $siteNavigations ) {
		$siteNavigationElements = [];

		foreach ( $siteNavigations as $index => $siteNavigation ) {
			$siteNavigationElements[] = [
				'@type'       => 'SiteNavigationElement',
				'position'    => $index + 1,
				'name'        => $siteNavigation['name'],
				'url'         => $siteNavigation['url'],
				'description' => $siteNavigation['description'] ?? '',
			];
		}

		return $siteNavigationElements;
	}

	/**
	 * Flattens the given headings.
	 *
	 * @since 4.6.8
	 *
	 * @param  array $headings The headings.
	 * @return array           The flattened headings.
	 */
	private function flattenHeadings( $headings ) {
		$flattenedHeadings = [];

		foreach ( $headings as $heading ) {
			if ( ! empty( $heading['headings'] ) ) {
				// Flatten subheadings first.
				$subheadings = $this->flattenHeadings( $heading['headings'] );
				unset( $heading['headings'] );

				// Add the current block to the result.
				$flattenedHeadings[] = $heading;

				// Add the flattened subheadings to the result.
				$flattenedHeadings = array_merge( $flattenedHeadings, $subheadings );
			} else {
				// If no subheadings, just add the block to the result
				$flattenedHeadings[] = $heading;
			}
		}

		return $flattenedHeadings;
	}
}