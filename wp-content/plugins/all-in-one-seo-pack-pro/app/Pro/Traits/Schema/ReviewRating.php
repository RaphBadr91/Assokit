<?php
namespace AIOSEO\Plugin\Pro\Traits\Schema;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contains all the review rating related schema properties.
 *
 * @since 4.6.7
 */
trait ReviewRating {
	/**
	 * The graph data.
	 *
	 * @since 4.6.7
	 *
	 * @var Object
	 */
	public $graphData = null;

	/**
	 * Returns the Review graph data.
	 *
	 * @since 4.6.7
	 *
	 * @return array The graph data.
	 */
	public function getReview() {
		if ( empty( $this->graphData->properties->reviews ) ) {
			return [];
		}

		$graphs = [];
		foreach ( $this->graphData->properties->reviews as $reviewData ) {
			if ( empty( $reviewData->author ) || empty( $reviewData->rating ) ) {
				continue;
			}

			$graph = [
				'@type'        => 'Review',
				'headline'     => ! empty( $reviewData->headline ) ? $reviewData->headline : '',
				'reviewBody'   => ! empty( $reviewData->content ) ? $reviewData->content : '',
				'reviewRating' => [
					'@type'       => 'Rating',
					'ratingValue' => (float) $reviewData->rating,
					'worstRating' => ! empty( $this->graphData->properties->rating->minimum ) ? (float) $this->graphData->properties->rating->minimum : 1,
					'bestRating'  => ! empty( $this->graphData->properties->rating->maximum ) ? (float) $this->graphData->properties->rating->maximum : 5,
				],
				'author'       => [
					'@type' => 'Person',
					'name'  => $reviewData->author
				]
			];

			// Positive notes.
			if ( ! empty( $reviewData->positiveNotes ) ) {
				$graph['positiveNotes'] = [
					'@type'           => 'ItemList',
					'itemListElement' => $this->getReviewNotes( $reviewData->positiveNotes )
				];
			}

			// Negative notes.
			if ( ! empty( $reviewData->negativeNotes ) ) {
				$graph['negativeNotes'] = [
					'@type'           => 'ItemList',
					'itemListElement' => $this->getReviewNotes( $reviewData->negativeNotes )
				];
			}

			$graphs[] = $graph;
		}

		return $graphs;
	}

	/**
	 * Returns the AggregateRating graph data.
	 *
	 * @since 4.6.7
	 *
	 * @return array The graph data.
	 */
	public function getAggregateRating() {
		if ( empty( $this->graphData->properties->reviews ) ) {
			return [];
		}

		// Filter the reviews to only include those with an author and rating.
		$reviews = array_filter( $this->graphData->properties->reviews, function( $reviewData ) {
			return ! empty( $reviewData->author ) && ! empty( $reviewData->rating );
		} );

		$ratings = array_map( function( $reviewData ) {
			return $reviewData->rating;
		}, $reviews );

		$ratings = array_filter( $ratings );
		if ( empty( $ratings ) ) {
			return [];
		}

		$averageRating = array_sum( $ratings ) / count( $ratings );

		return [
			'@type'       => 'AggregateRating',
			'url'         => ! empty( $this->graphData->properties->id )
				? aioseo()->schema->context['url'] . '#aggregateRating-' . $this->graphData->id
				: aioseo()->schema->context['url'] . '#aggregateRating',
			'ratingValue' => (float) $averageRating,
			'worstRating' => ! empty( $this->graphData->properties->rating->minimum ) ? (float) $this->graphData->properties->rating->minimum : 1,
			'bestRating'  => ! empty( $this->graphData->properties->rating->maximum ) ? (float) $this->graphData->properties->rating->maximum : 5,
			'reviewCount' => count( $ratings )
		];
	}

	/**
	 * Returns the review notes.
	 *
	 * @since 4.6.8
	 *
	 * @param  object|null $notes The unparsed notes data.
	 * @return array              The review notes.
	 */
	public function getReviewNotes( $notes ) {
		if ( empty( $notes ) ) {
			return [];
		}

		$notes = json_decode( $notes );
		$notes = array_map( function ( $note ) {
			return $note->value;
		}, $notes );

		$notesList = [];
		foreach ( $notes as $key => $note ) {
			$notesList[] = [
				'@type'    => 'ListItem',
				'position' => $key + 1,
				'name'     => $note
			];
		}

		return $notesList;
	}
}