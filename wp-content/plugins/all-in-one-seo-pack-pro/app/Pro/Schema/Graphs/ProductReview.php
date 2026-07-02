<?php
namespace AIOSEO\Plugin\Pro\Schema\Graphs;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * ProductReview graph class.
 *
 * @since 4.6.8
 */
class ProductReview extends Product\Product {
	/**
	 * Returns the graph data.
	 *
	 * @since 4.6.8
	 *
	 * @param  object $graphData The graph data.
	 * @return array             The parsed graph data.
	 */
	public function get( $graphData = null ) {
		$this->graphData = $graphData;

		$data = $this->getCommonGraphData( $graphData );
		$data = $this->getDataWithOfferPriceData( $data, $graphData );

		// Just one review is allowed, so convert the list to a single review.
		$data['review'] = $data['review'][0] ?? [];

		return $data;
	}
}