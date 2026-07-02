<?php
namespace AIOSEO\Plugin\Pro\Schema\Graphs;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Schema\Graphs as CommonGraphs;

/**
 * DiscussionForumPosting graph class.
 *
 * @since 4.7.6
 */
class DiscussionForumPosting extends CommonGraphs\Graph {
	/**
	 * Returns the graph data.
	 *
	 * @since 4.7.6
	 *
	 * @param  object|null $graphData The graph data.
	 * @return array                  The parsed graph data.
	 */
	public function get( $graphData = null ) {
		if ( ! empty( self::$overwriteGraphData[ __CLASS__ ] ) ) {
			$graphData = json_decode( wp_json_encode( wp_parse_args( self::$overwriteGraphData[ __CLASS__ ], $graphData ) ) );
		}

		return [
			'@type'         => 'DiscussionForumPosting',
			'id'            => aioseo()->schema->context['url'] . '#discussion-forum-posting',
			'headline'      => $graphData->properties->headline ?? '',
			'text'          => $graphData->properties->text ?? '',
			'url'           => aioseo()->schema->context['url'],
			'datePublished' => ! empty( $graphData->properties->datePublished ) ? mysql2date( DATE_W3C, $graphData->properties->datePublished, false ) : '',
			'comment'       => $this->parseComment( $graphData->properties->comment ?? [] ),
			'author'        => [
				'@type' => 'Person',
				'name'  => $graphData->properties->author ?? '',
			],
		];
	}

	/**
	 * Retrieves comments.
	 *
	 * @since 4.8.1
	 *
	 * @param  array $comment The comment data.
	 * @return array          The formatted `comment` data.
	 */
	private function parseComment( $comment ) {
		if ( empty( $comment ) ) {
			return [];
		}

		$parsed = [];
		foreach ( $comment as $item ) {
			$itemData = [
				'@type'         => 'Comment',
				'text'          => $item->content,
				'datePublished' => mysql2date( DATE_W3C, $item->date_recorded, false ),
				'author'        => [
					'@type' => 'Person',
					'name'  => $item->user_fullname,
				],
			];

			if ( ! empty( $item->children ) ) {
				$itemData['comment'] = $this->parseComment( $item->children );
			}

			$parsed[] = $itemData;
		}

		return $parsed;
	}
}