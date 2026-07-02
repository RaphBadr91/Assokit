<?php
namespace AIOSEO\Plugin\Pro\Schema\Graphs;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Schema\Graphs as CommonGraphs;

/**
 * Video graph class.
 *
 * @since 4.2.5
 */
class Video extends CommonGraphs\Graph {
	/**
	 * Returns the graph data.
	 *
	 * @since 4.2.5
	 *
	 * @param  Object $graphData The graph data.
	 * @return array             The parsed graph data.
	 */
	public function get( $graphData = null ) {
		$data = [
			'@type'        => 'VideoObject',
			'@id'          => ! empty( $graphData->id ) ? aioseo()->schema->context['url'] . $graphData->id : aioseo()->schema->context['url'] . '#video',
			'name'         => ! empty( $graphData->properties->name ) ? $graphData->properties->name : get_the_title(),
			'description'  => ! empty( $graphData->properties->description ) ? $graphData->properties->description : aioseo()->schema->context['description'],
			'contentUrl'   => ! empty( $graphData->properties->contentUrl ) ? $graphData->properties->contentUrl : '',
			'embedUrl'     => ! empty( $graphData->properties->embedUrl ) ? $graphData->properties->embedUrl : '',
			'thumbnailUrl' => ! empty( $graphData->properties->thumbnailUrl ) ? $graphData->properties->thumbnailUrl : '',
			'uploadDate'   => ! empty( $graphData->properties->uploadDate ) ? mysql2date( DATE_W3C, $graphData->properties->uploadDate, false ) : ''
		];

		if ( isset( $graphData->properties->familyFriendly ) ) {
			if ( ! empty( $graphData->properties->familyFriendly ) ) {
				$data['isFamilyFriendly'] = 'https://schema.org/True';
			} else {
				$data['isFamilyFriendly'] = 'https://schema.org/False';
			}
		}

		return $data;
	}
}