<?php
namespace AIOSEO\Plugin\Pro\Schema\Graphs\Product;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Pro\Traits\Schema\ReviewRating;

use AIOSEO\Plugin\Common\Schema\Graphs as CommonGraphs;

/**
 * Product graph class.
 *
 * @since 4.0.13
 */
class Product extends CommonGraphs\Graph {
	use ReviewRating;

	/**
	 * Returns the graph data.
	 *
	 * @since 4.0.13
	 *
	 * @param  Object $graphData The graph data.
	 * @return array             The parsed graph data.
	 */
	public function get( $graphData = null ) {
		if ( ! empty( $graphData ) && ! isset( $graphData->properties ) ) {
			$graphData = (object) [
				'properties' => $graphData
			];
		}

		if (
			aioseo()->helpers->isWooCommerceActive() &&
			is_singular( 'product' ) &&
			// Use the WooCommerce class if this is a default graph or if the autogenerate setting is enabled.
			( empty( $graphData ) || ( isset( $graphData->properties->autogenerate ) && $graphData->properties->autogenerate ) )
		) {
			return ( new WooCommerceProduct() )->get( $graphData );
		}

		if (
			aioseo()->helpers->isEddActive() &&
			is_singular( 'download' ) &&
			function_exists( 'edd_get_download' ) &&
			// Use the EDD class if this is a default graph or if the autogenerate setting is enabled.
			( empty( $graphData ) || ( isset( $graphData->properties->autogenerate ) && $graphData->properties->autogenerate ) )
		) {
			return ( new EddProduct() )->get( $graphData );
		}

		$this->graphData = $graphData;

		$data = $this->getCommonGraphData( $graphData );

		return $this->getDataWithOfferPriceData( $data, $graphData );
	}

	/**
	 * The commong graph data for products.
	 *
	 * @since 4.6.8
	 *
	 * @param  object $graphData The graph data.
	 * @return array             The parsed graph data.
	 */
	protected function getCommonGraphData( $graphData = null ) {
		$this->graphData = $graphData;

		$data = [
			'@type'           => 'Product',
			'@id'             => ! empty( $graphData->id ) ? aioseo()->schema->context['url'] . $graphData->id : aioseo()->schema->context['url'] . '#product',
			'name'            => ! empty( $graphData->properties->name ) ? $graphData->properties->name : get_the_title(),
			'description'     => ! empty( $graphData->properties->description ) ? $graphData->properties->description : '',
			'url'             => aioseo()->schema->context['url'],
			'brand'           => '',
			'sku'             => ! empty( $graphData->properties->identifiers->sku ) ? $graphData->properties->identifiers->sku : '',
			'gtin'            => ! empty( $graphData->properties->identifiers->gtin ) ? $graphData->properties->identifiers->gtin : '',
			'mpn'             => ! empty( $graphData->properties->identifiers->mpn ) ? $graphData->properties->identifiers->mpn : '',
			'isbn'            => ! empty( $graphData->properties->identifiers->isbn ) ? $graphData->properties->identifiers->isbn : '',
			'material'        => ! empty( $graphData->properties->attributes->material ) ? $graphData->properties->attributes->material : '',
			'color'           => ! empty( $graphData->properties->attributes->color ) ? $graphData->properties->attributes->color : '',
			'pattern'         => ! empty( $graphData->properties->attributes->pattern ) ? $graphData->properties->attributes->pattern : '',
			'size'            => ! empty( $graphData->properties->attributes->size ) ? $graphData->properties->attributes->size : '',
			'energyRating'    => ! empty( $graphData->properties->attributes->energyRating ) ? $graphData->properties->attributes->energyRating : '',
			'image'           => ! empty( $graphData->properties->image ) ? $this->image( $graphData->properties->image ) : $this->getFeaturedImage(),
			'aggregateRating' => $this->getAggregateRating(),
			'review'          => $this->getReview(),
			'audience'        => $this->getAudience()
		];

		if ( ! empty( $graphData->properties->brand ) ) {
			$data['brand'] = [
				'@type' => 'Brand',
				'name'  => $graphData->properties->brand
			];
		}

		return $data;
	}

	/**
	 * Returns data with offer price data.
	 *
	 * @since 4.6.8
	 *
	 * @param  array $data      The data array.
	 * @param  object $graphData The graph data.
	 * @return array            The data array.
	 */
	protected function getDataWithOfferPriceData( $data, $graphData = null ) {
		if ( isset( $graphData->properties->offer->price ) && isset( $graphData->properties->offer->currency ) ) {
			$price = ! empty( $graphData->properties->offer->salePrice )
				? (float) $graphData->properties->offer->salePrice
				: ( ! empty( $graphData->properties->offer->price ) ? (float) $graphData->properties->offer->price : 0 );

			$data['offers'] = [
				'@type'           => 'Offer',
				'price'           => $price,
				'priceCurrency'   => ! empty( $graphData->properties->offer->currency ) ? $graphData->properties->offer->currency : '',
				'priceValidUntil' => ! empty( $graphData->properties->offer->validUntil )
					? aioseo()->helpers->dateToIso8601( $graphData->properties->offer->validUntil )
					: '',
				'availability'    => ! empty( $graphData->properties->offer->availability ) ? $graphData->properties->offer->availability : 'https://schema.org/InStock',
				'shippingDetails' => $this->getShippingDetails()
			];

			if ( 'organization' === aioseo()->options->searchAppearance->global->schema->siteRepresents ) {
				$homeUrl                  = trailingslashit( home_url() );
				$data['offers']['seller'] = [
					'@type' => 'Organization',
					'@id'   => $homeUrl . '#organization',
				];
			}
		}

		return $data;
	}

	/**
	 * Returns the intended audience.
	 *
	 * @since 4.2.7
	 *
	 * @return array The audience data.
	 */
	protected function getAudience() {
		if ( empty( $this->graphData->properties->audience->gender ) ) {
			return [];
		}

		// converts variable to integer if it's a number otherwise is null.
		$parsedMinAge = filter_var( $this->graphData->properties->audience->minimumAge, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE );
		$parsedMaxAge = filter_var( $this->graphData->properties->audience->maximumAge, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE );

		// Don't output the audience if the minimum age and maximum age both are not set.
		if (
			! is_numeric( $parsedMinAge ) &&
			! is_numeric( $parsedMaxAge )
		) {
			return [];
		}

		$audience = [
			'@type'           => 'PeopleAudience',
			'suggestedGender' => $this->graphData->properties->audience->gender,
			'suggestedMinAge' => (float) $this->graphData->properties->audience->minimumAge,
		];

		// If the minimum age is less than the adult age (13.0) and the maximum age is numeric, set the suggested maximum age.
		if ( 13.0 > $audience['suggestedMinAge'] && is_numeric( $this->graphData->properties->audience->maximumAge ) ) {
			$audience['suggestedMaxAge'] = (float) $this->graphData->properties->audience->maximumAge;
		}

		return $audience;
	}

	/**
	 * Returns the shipping details.
	 *
	 * @since 4.2.7
	 *
	 * @return array The shipping details.
	 */
	public function getShippingDetails() {
		if ( empty( $this->graphData->properties->shippingDestinations ) ) {
			return [];
		}

		$shippingDetails = [];
		foreach ( $this->graphData->properties->shippingDestinations as $shippingDestination ) {
			if ( empty( $shippingDestination->country ) ) {
				continue;
			}

			$shippingDetail = [
				'@type'               => 'OfferShippingDetails',
				'shippingRate'        => [
					'@type'    => 'MonetaryAmount',
					'value'    => ! empty( $shippingDestination->rate ) ? (float) $shippingDestination->rate : 0,
					'currency' => ! empty( $this->graphData->properties->offer->currency ) ? $this->graphData->properties->offer->currency : ''
				],
				'shippingDestination' => [
					'@type'          => 'DefinedRegion',
					'addressCountry' => $shippingDestination->country
				],
				'deliveryTime'        => $this->getDeliveryTime( $shippingDestination->deliveryTime ),
			];

			// States can't be combined with postal codes so it's either one or the other.
			if ( ! empty( $shippingDestination->states ) ) {
				$states = json_decode( $shippingDestination->states );
				$states = array_map( function ( $state ) {
					return $state->value;
				}, $states );
				$shippingDetail['shippingDestination']['addressRegion'] = $states;
			} elseif ( $shippingDestination->postalCodes ) {
				$postalCodes = json_decode( $shippingDestination->postalCodes );
				$postalCodes = array_map( function ( $postalCode ) {
					return $postalCode->value;
				}, $postalCodes );
				$shippingDetail['shippingDestination']['postalCode'] = $postalCodes;
			}

			$shippingDetails[] = $shippingDetail;
		}

		return $shippingDetails;
	}

	/**
	 * Returns the delivery time.
	 *
	 * @since 4.6.4
	 *
	 * @param  object|null $deliveryTime The unparsed delivery time data.
	 * @return array                     The delivery time schema data.
	 */
	protected function getDeliveryTime( $deliveryTime ) {
		if ( empty( $deliveryTime->handlingTime ) ) {
			return [];
		}

		if (
			! is_numeric( $deliveryTime->handlingTime->minValue )
			&& ! is_numeric( $deliveryTime->handlingTime->maxValue )
			&& ! is_numeric( $deliveryTime->transitTime->minValue )
			&& ! is_numeric( $deliveryTime->transitTime->maxValue )
		) {
			return [];
		}

		$schema = [
			'@type' => 'ShippingDeliveryTime'
		];

		if (
			is_numeric( $deliveryTime->handlingTime->minValue )
			&& is_numeric( $deliveryTime->handlingTime->maxValue )
		) {
			$schema['handlingTime'] = [
				'@type'    => 'QuantitativeValue',
				'minValue' => (int) $deliveryTime->handlingTime->minValue,
				'maxValue' => (int) $deliveryTime->handlingTime->maxValue,
				'unitCode' => ! empty( $deliveryTime->handlingTime->unitCode ) ? $deliveryTime->handlingTime->unitCode : 'DAY'
			];
		}

		if (
			is_numeric( $deliveryTime->transitTime->minValue )
			&& is_numeric( $deliveryTime->transitTime->maxValue )
		) {
			$schema['transitTime'] = [
				'@type'    => 'QuantitativeValue',
				'minValue' => (int) $deliveryTime->transitTime->minValue,
				'maxValue' => (int) $deliveryTime->transitTime->maxValue,
				'unitCode' => ! empty( $deliveryTime->transitTime->unitCode ) ? $deliveryTime->transitTime->unitCode : 'DAY'
			];
		}

		if ( empty( $schema['handlingTime'] ) || empty( $schema['transitTime'] ) ) {
			return [];
		}

		return $schema;
	}
}