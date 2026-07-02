<?php
namespace AIOSEO\Plugin\Pro\Schema\Graphs\Product;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * EDD product graph class.
 *
 * @since 4.0.13
 */
class EddProduct extends Product {
	/**
	 * The download object.
	 *
	 * @since 4.0.13
	 *
	 * @var \EDD_Download
	 */
	private $download = null;

	/**
	 * Class constructor.
	 *
	 * @since 4.0.13
	 */
	public function __construct() {
		if ( version_compare( EDD_VERSION, '3.0.0', '<' ) ) {
			add_filter( 'edd_add_schema_microdata', '__return_false' );

			if ( aioseo()->helpers->isEddReviewsActive() ) {
				add_filter( 'edd_reviews_json_ld_data', [ $this, 'unsetEddHeadSchema' ] );
				remove_action( 'the_content', [ \EDD_Reviews::get_instance(), 'microdata' ] );
			}
		}

		if ( version_compare( EDD_VERSION, '3.0.0', '>=' ) ) {
			remove_action( 'wp_footer', [ \Easy_Digital_Downloads::instance()->structured_data, 'output_structured_data' ] );
		}

		$this->download = edd_get_download( get_the_id() );
	}

	/**
	 * Unsets the AggregateRating graph EDD outputs in the HEAD.
	 *
	 * @since 4.0.13
	 *
	 * @param  array $data The graph data.
	 * @return array $data The neutralized graph data.
	 */
	public function unsetEddHeadSchema( $data ) {
		if ( isset( $data['aggregateRating']['ratingCount'] ) ) {
			$data['aggregateRating']['ratingCount'] = 0;
		}

		return $data;
	}

	/**
	 * Returns the graph data.
	 *
	 * @since 4.0.13
	 *
	 * @param  object $graphData The graph data.
	 * @return array             The parsed graph data.
	 */
	public function get( $graphData = null ) {
		$this->download  = edd_get_download( get_the_id() );
		$this->graphData = $graphData;

		if ( function_exists( 'edd_has_variable_prices' ) && edd_has_variable_prices( $this->download->ID ) ) {
			return $this->getVariationProductData( $this->download, $graphData );
		}

		return $this->getCommonProductSchema( $this->download, $graphData );
	}

	/**
	 * Returns the variation product data.
	 *
	 * @since 4.6.8
	 *
	 * @param  \EDD_Download $download  The download object.
	 * @param  object        $graphData The graph data.
	 * @return array                    The variation product data.
	 */
	protected function getVariationProductData( $download, $graphData ) {
		$data                   = $this->getCommonProductSchema( $download, $graphData );
		$data['@type']          = 'ProductGroup';
		$data['productGroupID'] = $download->ID;
		$data['hasVariant']     = [];

		$prices       = $download->get_prices();
		$defaultOffer = $this->getDefaultOffer( $download );

		// Unset unncessary data for child products.
		$parentData = $data;
		unset(
			$parentData['url'],
			$parentData['sku'],
			$parentData['productGroupID'],
			$parentData['offers'],
			$parentData['hasVariant']
		);

		foreach ( $prices as $priceObject ) {
			$variantItem                            = $parentData;
			$variantItem['@type']                   = 'Product';
			$variantItem['name']                    = $data['name'] . ' - ' . $priceObject['name'];
			$variantItem['offers']                  = $defaultOffer;
			$variantItem['offers']['price']         = (float) $priceObject['amount'];
			$variantItem['offers']['priceCurrency'] = $this->getPriceCurrency();
			$data['hasVariant'][]                   = $variantItem;
		}

		return $data;
	}

	/**
	 * Returns the default offer data.
	 *
	 * @since 4.6.8
	 *
	 * @param  \EDD_Download|null $download The download object.
	 * @return array                        The default offer data.
	 */
	private function getDefaultOffer( $download = null ) {
		if ( ! $download instanceof \EDD_Download ) {
			$download = $this->download;
		}

		$defaultOffer = [
			'@type'           => 'Offer',
			'url'             => ! empty( $this->graphData->properties->id )
				? aioseo()->schema->context['url'] . '#eddOffer-' . $this->graphData->id
				: aioseo()->schema->context['url'] . '#eddOffer',
			'priceValidUntil' => ! empty( $this->graphData->properties->offer->validUntil )
				? aioseo()->helpers->dateToIso8601( $this->graphData->properties->offer->validUntil )
				: '',
			'availability'    => ! empty( $this->graphData->properties->offer->availability )
				? $this->graphData->properties->offer->availability
				: 'https://schema.org/InStock'
		];

		if ( 'organization' === aioseo()->options->searchAppearance->global->schema->siteRepresents ) {
			$homeUrl         = trailingslashit( home_url() );
			$defaultOffer['seller'] = [
				'@type' => 'Organization',
				'@id'   => $homeUrl . '#organization',
			];
		}

		return $defaultOffer;
	}

	/**
	 * Returns the offer(s) data.
	 *
	 * @since 4.0.13
	 *
	 * @return array The offer(s) data.
	 */
	protected function getOffers( $download = null ) {
		if ( ! $download instanceof \EDD_Download ) {
			$download = $this->download;
		}

		$isVariable = method_exists( $download, 'has_variable_prices' ) && method_exists( $download, 'get_prices' ) ? $download->has_variable_prices() : false;

		$defaultOffer = $this->getDefaultOffer( $download );

		if ( $isVariable ) {
			return [];
		}

		$dataFunctions = [
			'price'         => 'getPrice',
			'priceCurrency' => 'getPriceCurrency',
			'category'      => 'getCategory'
		];

		return $this->getData( $defaultOffer, $dataFunctions );
	}

	/**
	 * Returns the product price.
	 *
	 * @since 4.0.13
	 *
	 * @param  \EDD_Download|null $download The download object.
	 * @return float                        The product price.
	 */
	protected function getPrice( $download = null ) {
		if ( ! $download instanceof \EDD_Download ) {
			$download = $this->download;
		}

		if ( method_exists( $download, 'is_free' ) && $download->is_free() ) {
			return '0';
		}

		return method_exists( $download, 'get_price' ) ? $download->get_price() : '';
	}

	/**
	 * Returns the product currency.
	 *
	 * @since 4.0.13
	 *
	 * @return string The product currency.
	 */
	protected function getPriceCurrency() {
		return function_exists( 'edd_get_currency' ) ? edd_get_currency() : 'USD';
	}

	/**
	 * Returns the product category.
	 *
	 * @since 4.0.13
	 *
	 * @param  \EDD_Download $download The download object.
	 * @return string                  The product category.
	 */
	protected function getCategory( $download = null ) {
		if ( ! $download instanceof \EDD_Download ) {
			$download = $this->download;
		}

		$categories = wp_get_post_terms( $download->get_id(), 'download_category', [ 'fields' => 'names' ] );

		return ! empty( $categories ) && __( 'Uncategorized' ) !== $categories[0] ? $categories[0] : ''; // phpcs:ignore AIOSEO.Wp.I18n.MissingArgDomain
	}

	/**
	 * Returns the AggregateRating graph data.
	 *
	 * @since 4.0.13
	 *
	 * @param  \EDD_Download|null $download The download object.
	 * @return array                        The graph data.
	 */
	protected function getEddAggregateRating( $download = null ) {
		if ( ! $download instanceof \EDD_Download ) {
			$download = $this->download;
		}

		$reviewCount   = (int) get_comments_number( $download->get_id() );
		$averageRating = get_post_meta( $download->get_id(), 'edd_reviews_average_rating', true );
		if ( 0 === $reviewCount || false === $averageRating ) {
			return [];
		}

		return [
			'@type'       => 'AggregateRating',
			'@id'         => aioseo()->schema->context['url'] . '#aggregrateRating',
			'worstRating' => 1,
			'bestRating'  => 5,
			'ratingValue' => (float) $averageRating,
			'reviewCount' => $reviewCount,
		];
	}

	/**
	 * Returns the Review graph data.
	 *
	 * @since 4.0.13
	 *
	 * @param  \EDD_Download|null $download The download object.
	 * @return array                        The graph data.
	 */
	protected function getEddReview( $download = null ) {
		if ( ! $download instanceof \EDD_Download ) {
			$download = $this->download;
		}

		// Because get_comments() doesn't seem to work for EDD, we use our own DB class here.
		$comments = aioseo()->core->db->start( 'comments' )
			->where( 'comment_post_ID', $download->get_id() )
			->where( 'comment_type', 'edd_review' )
			->limit( 25 )
			->run()
			->result();

		if ( empty( $comments ) ) {
			return [];
		}

		$reviews = [];
		foreach ( $comments as $comment ) {
			$approved = get_comment_meta( $comment->comment_ID, 'edd_review_approved', true );
			$rating   = (float) get_comment_meta( $comment->comment_ID, 'edd_rating', true );
			if ( ! $approved || false === $rating ) {
				continue;
			}

			$review = [
				'@type'         => 'Review',
				'reviewRating'  => [
					'@type'       => 'Rating',
					'ratingValue' => $rating,
					'worstRating' => 1,
					'bestRating'  => 5
				],
				'author'        => [
					'@type' => 'Person',
					'name'  => $comment->comment_author
				],
				'datePublished' => mysql2date( DATE_W3C, $comment->comment_date_gmt, false )
			];

			$reviewTitle = get_comment_meta( $comment->comment_ID, 'edd_review_title', true );
			if ( ! empty( $reviewTitle ) ) {
				$review['headline'] = $reviewTitle;
			}

			if ( ! empty( $comment->comment_content ) ) {
				$review['reviewBody'] = $comment->comment_content;
			}

			$reviews[] = $review;
		}

		return $reviews;
	}

	/**
	 * Returns the product SKU.
	 *
	 * @since 4.4.0
	 *
	 * @param  \EDD_Download|null $download The download object.
	 * @return string                       The SKU.
	 */
	private function getEddSku( $download = null ) {
		if ( ! $download instanceof \EDD_Download ) {
			$download = $this->download;
		}

		if ( ! empty( $this->graphData->properties->identifiers->sku ) ) {
			return $this->graphData->properties->identifiers->sku;
		}

		// This is based on the code in EDD's includes/class-structured-data.php.
		if ( method_exists( $download, 'get_sku' ) ) {
			if ( '-' === $download->get_sku() ) {
				return $download->get_id();
			}

			return $download->get_sku();
		}

		return '';
	}

	/**
	 * Returns the common product schema data.
	 *
	 * @since 4.6.8
	 *
	 * @param  \EDD_Download $download  The download object.
	 * @param  object        $graphData The graph data.
	 * @return array                    The common product schema data.
	 */
	private function getCommonProductSchema( $download = null, $graphData = null ) {
		if ( ! $download ) {
			$download = $this->download;
		}

		$data = [
			'@type'           => 'Product',
			'@id'             => ! empty( $graphData->id ) ? aioseo()->schema->context['url'] . $graphData->id : aioseo()->schema->context['url'] . '#eddProduct',
			'name'            => get_the_title(),
			'description'     => ! empty( $graphData->properties->description ) ? $graphData->properties->description : aioseo()->helpers->getDescriptionFromContent( $download ),
			'url'             => aioseo()->schema->context['url'],
			'brand'           => '',
			'sku'             => $this->getEddSku( $download ),
			'gtin'            => ! empty( $graphData->properties->identifiers->gtin ) ? $graphData->properties->identifiers->gtin : '',
			'mpn'             => ! empty( $graphData->properties->identifiers->mpn ) ? $graphData->properties->identifiers->mpn : '',
			'isbn'            => ! empty( $graphData->properties->identifiers->isbn ) ? $graphData->properties->identifiers->isbn : '',
			'image'           => ! empty( $graphData->properties->image ) ? $graphData->properties->image : $this->getFeaturedImage(),
			'aggregateRating' => aioseo()->helpers->isEddReviewsActive() ? $this->getEddAggregateRating( $download ) : $this->getAggregateRating(),
			'review'          => aioseo()->helpers->isEddReviewsActive() ? $this->getEddReview( $download ) : $this->getReview(),
			'audience'        => $this->getAudience()
		];

		if ( ! empty( $this->graphData->properties->brand ) ) {
			$data['brand'] = [
				'@type' => 'Brand',
				'name'  => $this->graphData->properties->brand
			];
		}

		// If it has variations, we don't need to get offers here
		// as we will handle that on variations.
		if ( ! edd_has_variable_prices( $download->ID ) ) {
			$data['offers'] = $this->getOffers( $download );
		}

		return $data;
	}
}