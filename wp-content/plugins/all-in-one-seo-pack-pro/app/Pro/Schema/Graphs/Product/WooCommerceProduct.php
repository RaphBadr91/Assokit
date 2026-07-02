<?php
namespace AIOSEO\Plugin\Pro\Schema\Graphs\Product;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce product graph class.
 *
 * @since 4.0.13
 */
class WooCommerceProduct extends Product {
	/**
	 * The download object.
	 *
	 * @since 4.0.13
	 *
	 * @var \WC_Product|\WC_Product_Variable
	 */
	private $product = null;

	/**
	 * Class constructor.
	 *
	 * @since 4.0.13
	 */
	public function __construct() {
		remove_action( 'wp_footer', [ WC()->structured_data, 'output_structured_data' ], 10 );
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
		$this->product   = wc_get_product( get_the_ID() );
		$this->graphData = $graphData;

		if ( ! is_a( $this->product, 'WC_Product' ) ) {
			return [];
		}

		// Check if the product is a variation or group product.
		if (
			$this->product instanceof \WC_Product_Variable ||
			$this->product instanceof \WC_Product_Grouped
		) {
			return $this->getVariationProductData( $graphData, $this->product );
		}

		return $this->getData(
			$this->getCommonProductSchema( $this->product, $graphData ),
			$this->getDataFunctions()
		);
	}

	/**
	 * Returns the product default data functions.
	 *
	 * @since 4.6.8
	 *
	 * @return array The product data functions.
	 */
	public function getDataFunctions() {
		return [
			'offers'          => 'getOffers',
			'audience'        => 'getAudience',
			'aggregateRating' => 'getWooCommerceAggregateRating',
			'review'          => 'getWooCommerceReview'
		];
	}

	/**
	 * Returns the product data from variations.
	 *
	 * @since 4.6.8
	 *
	 * @param object      $graphData Graph data.
	 * @param \WC_Product $product   Product object.
	 *
	 * @return array The product data.
	 */
	protected function getVariationProductData( $graphData, $product = null ) {
		if ( ! is_a( $product, 'WC_Product' ) ) {
			$product = $this->product;
		}

		$variations = [];
		if ( $product instanceof \WC_Product_Variable ) {
			$variations = $product->get_available_variations();
		}

		if ( $product instanceof \WC_Product_Grouped ) {
			$variations = $product->get_children();
		}

		if ( empty( $variations ) ) {
			return [];
		}

		$data                   = $this->getCommonProductSchema( $product, $graphData );
		$data['@type']          = 'ProductGroup';
		$data['productGroupID'] = $product->get_id();
		$data['hasVariant']     = [];

		// Unset offers since they are not needed for variable/grouped products.
		$dataFunctions = $this->getDataFunctions();
		unset( $dataFunctions['offers'] );

		$data = $this->getData( $data, $dataFunctions );

		foreach ( $variations as $variation ) {
			$variationProduct = $this->getSingleVariationOrGroupedProductGraphData( $variation, $graphData );

			if ( ! empty( $variationProduct ) ) {
				$data['hasVariant'][] = $variationProduct;
			}
		}

		return $data;
	}

	/**
	 * Returns the product short description.
	 *
	 * @since 4.0.13
	 *
	 * @param  \WC_Product|null $product The product.
	 * @return string                    The product short description.
	 */
	protected function getShortDescription( $product = null ) {
		if ( ! is_a( $product, 'WC_Product' ) ) {
			$product = $this->product;
		}

		$description = method_exists( $product, 'get_short_description' ) ? $product->get_short_description() : $this->getDescription( $product );

		return strip_shortcodes( wp_strip_all_tags( $description ) );
	}

	/**
	 * Returns the product description.
	 *
	 * @since 4.0.13
	 *
	 * @param  \WC_Product|null $product The product.
	 * @return string                    The product description.
	 */
	protected function getDescription( $product = null ) {
		if ( ! is_a( $product, 'WC_Product' ) ) {
			$product = $this->product;
		}

		return method_exists( $product, 'get_description' ) ? $product->get_description() : aioseo()->schema->context['description'];
	}

	/**
	 * Returns the product brand.
	 *
	 * @since 4.0.13
	 *
	 * @param  \WC_Product|null $product The product.
	 * @return array                     The product brand.
	 */
	protected function getBrand( $product = null ) {
		if ( ! is_a( $product, 'WC_Product' ) ) {
			$product = $this->product;
		}

		$brand = ! empty( $this->graphData->properties->brand ) ? $this->graphData->properties->brand : '';
		if ( ! $brand || aioseo()->helpers->isWooCommerceBrandsActive() ) {
			$brand = aioseo()->helpers->getWooCommerceBrand( $product->get_id() );
		}

		return ! empty( $brand )
			? [
				'@type' => 'Brand',
				'name'  => $brand
			]
			: [];
	}

	/**
	 * Returns the GTIN number for the product.
	 *
	 * @since 4.2.6
	 *
	 * @param  \WC_Product|null $product The product.
	 * @return string                    The GTIN number.
	 */
	private function getGtin( $product = null ) {
		if ( ! is_a( $product, 'WC_Product' ) ) {
			$product = $this->product;
		}

		// Get the GTIN from the graph data.
		$gtin = $this->graphData->properties->identifiers->gtin ?? '';

		if ( ! $gtin && aioseo()->helpers->isWooCommerceUpcEanIsbnActive() ) {
			if ( $product instanceof \WC_Product_Variable ) {
				$gtin = get_post_meta( get_the_ID(), 'hwp_var_gtin', true );
			} else {
				$gtin = get_post_meta( get_the_ID(), 'hwp_product_gtin', true );
			}
		}

		// Fallback to the WC product global unique ID if no GTIN is found.
		if ( ! $gtin && method_exists( $product, 'get_global_unique_id' ) ) {
			$gtin = $product->get_global_unique_id();
		}

		return $gtin;
	}

	/**
	 * Returns the product image.
	 *
	 * @since 4.0.13
	 *
	 * @param  \WC_Product|null $product The product.
	 * @return array                     The product image.
	 */
	protected function getImage( $product = null ) {
		if ( ! is_a( $product, 'WC_Product' ) ) {
			$product = $this->product;
		}

		$imageId = method_exists( $product, 'get_image_id' ) ? $product->get_image_id() : get_post_thumbnail_id();

		return $imageId ? $this->image( $imageId, 'productImage' ) : '';
	}

	/**
	 * Returns the offer data.
	 *
	 * @since 4.0.13
	 *
	 * @return array The offer data.
	 */
	protected function getOffers() {
		$defaultOffer = [
			'@type' => 'Offer',
			'url'   => ! empty( $this->graphData->properties->id )
				? aioseo()->schema->context['url'] . '#wooCommerceOffer-' . $this->graphData->id
				: aioseo()->schema->context['url'] . '#wooCommerceOffer',
			'price' => function_exists( 'wc_get_price_to_display' )
				? wc_get_price_to_display( $this->product )
				: ( method_exists( $this->product, 'get_price' ) ? $this->product->get_price() : 0 )
		];

		$dataFunctions = [
			'priceCurrency'   => 'getPriceCurrency',
			'category'        => 'getCategory',
			'priceValidUntil' => 'getPriceValidUntil',
			'availability'    => 'getAvailability',
			'shippingDetails' => 'getShippingDetails'
		];

		$defaultOffer                       = $this->getData( $defaultOffer, $dataFunctions );
		$defaultOffer['priceSpecification'] = $this->getPriceSpecification( (float) $defaultOffer['price'] );

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
	 * Returns the product currency.
	 *
	 * @since 4.0.13
	 *
	 * @return string The product currency.
	 */
	protected function getPriceCurrency() {
		return function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';
	}

	/**
	 * Returns the offer count.
	 *
	 * @since 4.1.1
	 *
	 * @return int The offer count.
	 */
	protected function getOfferCount() {
		return count( $this->product->get_available_variations( 'objects' ) );
	}

	/**
	 * Returns the date the product price is valid until.
	 *
	 * @since 4.0.13
	 *
	 * @return string The date the product price is valid until.
	 */
	protected function getPriceValidUntil() {
		if ( ! method_exists( $this->product, 'get_date_on_sale_to' ) || ! $this->product->get_date_on_sale_to() ) {
			return '';
		}

		$date = $this->product->get_date_on_sale_to();

		return is_object( $date ) && method_exists( $date, 'date_i18n' ) ? $date->date_i18n() : '';
	}

	/**
	 * Returns the product availability.
	 *
	 * @since 4.0.13
	 *
	 * @param  \WC_Product|\WC_Product_Variation|null $product The product.
	 * @return string                                          The product availability.
	 */
	protected function getAvailability( $product = null ) {
		if ( ! is_a( $product, 'WC_Product' ) ) {
			$product = $this->product;
		}

		if ( ! method_exists( $product, 'get_stock_status' ) || ! $product->get_stock_status() ) {
			return 'https://schema.org/InStock';
		}

		switch ( $product->get_stock_status() ) {
			case 'outofstock':
				return 'https://schema.org/OutOfStock';
			case 'onbackorder':
				return 'https://schema.org/PreOrder';
			case 'instock':
			default:
				return 'https://schema.org/InStock';
		}
	}

	/**
	 * Returns the shipping details.
	 *
	 * @since 4.2.7
	 *
	 * @return array The shipping details.
	 */
	public function getShippingDetails() {
		// No shipping details needed if the product is virtual or shipping details schema is disabled.
		if (
			$this->product->is_virtual() ||
			! $this->product->is_in_stock() ||
			! $this->product->is_purchasable() ||
			! empty( $_REQUEST['add-to-cart'] ) || // phpcs:ignore
			is_admin() || // This doesn't need to run on admin pages.
			aioseo()->helpers->isServingRestRequest() || // This doesn't need to run on REST requests.
			apply_filters( 'aioseo_schema_woocommerce_shipping_disable', false )
		) {
			return [];
		}

		// If WooCommerce isn't instantiated or the cart has an item in it, bail.
		global $woocommerce;
		$woocommerce->initialize_cart(); // This does nothing if the cart exists; otherwise, it instantiates it.
		if (
			! is_object( $woocommerce ) ||
			! is_object( $woocommerce->cart ) ||
			! empty( $woocommerce->cart->cart_contents )
		) {
			return [];
		}

		// Check that the cart and customer were initialized.
		if (
			! is_object( $woocommerce->customer ) ||
			! is_object( $woocommerce->cart )
		) {
			return [];
		}

		// Clone the customer/session so that we can restore it later.
		$originalCustomer = null;
		if ( is_object( $woocommerce->customer ) ) {
			$originalCustomer = clone $woocommerce->customer;
		}

		$originalSession = null;
		if ( is_object( $woocommerce->session ) ) {
			$originalSession = clone $woocommerce->session;
		}

		try {
			if ( ! did_action( 'woocommerce_init' ) ) {
				$woocommerce->init();
			}

			$woocommerce->frontend_includes();

			if ( ! is_object( $woocommerce->session ) ) {
				$woocommerce->session = new \WC_Session_Handler();
				$woocommerce->session->init();
			}
		} catch ( \Exception $e ) {
			if ( is_object( $originalCustomer ) ) {
				$woocommerce->customer = $originalCustomer;
			}

			if ( is_object( $originalSession ) ) {
				$woocommerce->session = $originalSession;
			}

			return [];
		}

		$this->addToCart();

		// Load the zones.
		$dataStore = \WC_Data_Store::load( 'shipping-zone' );
		$rawZones  = $dataStore->get_zones();

		$zones   = [];
		$zones[] = new \WC_Shipping_Zone( 0 ); // The first zone needs to be instantiated manually.
		foreach ( $rawZones as $rawZone ) {
			$zones[] = new \WC_Shipping_Zone( $rawZone );
		}

		$shippingDetails = [];
		foreach ( $zones as $zone ) {
			$locations = $zone->get_zone_locations();

			// In a moment we'll loop over all the locations in the zone.
			// We'll use one address "$addressData" (as detailed as possible) to calculate the most detailed shipping rate.
			// We'll use a second variable "$locationData" to keep track of all the countries, states, postal codes, etc. that are included in the current zone.
			$addressData = [
				'country'  => '',
				'state'    => '',
				'postcode' => '',
				'city'     => ''
			];

			$locationData = [
				'country'  => [],
				'state'    => [],
				'postcode' => [],
				'city'     => [],
				'rate'     => []
			];

			foreach ( $locations as $location ) {
				$addressData[ $location->type ] = $location->code;

				// If the location is a state, group it under the relevant country code.
				if ( 'state' === $location->type ) {
					$countryCode = substr( $location->code, 0, 2 );
					$stateCode   = substr( $location->code, 3 );

					if ( ! isset( $locationData['state'][ $countryCode ] ) ) {
						$locationData['state'][ $countryCode ] = [];
					}

					$locationData['state'][ $countryCode ][] = $stateCode;
				} else {
					$locationData[ $location->type ][] = $location->code;
				}
			}

			// Save the original shipping address.
			$originalShipping = WC()->customer->get_shipping();

			// Set the address and get all shipping methods that are eligible for the "order".
			WC()->customer->set_shipping_location( $addressData['country'], $addressData['state'], $addressData['postcode'] );
			WC()->shipping->calculate_shipping( WC()->cart->get_shipping_packages() );

			// Restore the original shipping address.
			// We also need to manually set address_1 and address_2 since the set_shipping_location method empties them.
			WC()->customer->set_shipping_location( $originalShipping['country'], $originalShipping['state'], $originalShipping['postcode'], $originalShipping['city'] );
			WC()->customer->set_shipping_address_1( $originalShipping['address_1'] );
			WC()->customer->set_shipping_address_2( $originalShipping['address_2'] );

			$shippingMethods = WC()->shipping->packages;

			if ( empty( $shippingMethods[0]['rates'] ) ) {
				continue;
			}

			foreach ( $shippingMethods[0]['rates'] as $shippingMethod ) {
				// Ignore the free pickup method since this isn't relevant for Google.
				if ( 'local_pickup' === $shippingMethod->get_method_id() ) {
					continue 2;
				}

				// Ensure cost is numeric before formatting to prevent fatal errors.
				$cost                 = is_numeric( $shippingMethod->cost ?? null ) ? (float) $shippingMethod->cost : 0.0;
				$locationData['rate'] = number_format( $cost, 2, '.', '' );
			}

			// Once we've got all shipping methods and their rates, loop over the countries/states that are included in the zone.
			foreach ( $locationData['country'] as $countryCode ) {
				$shippingDetail = [
					'@type'               => 'OfferShippingDetails',
					'shippingRate'        => [
						'@type'    => 'MonetaryAmount',
						'value'    => $locationData['rate'],
						'currency' => $this->getPriceCurrency()
					],
					'shippingDestination' => [
						'@type'          => 'DefinedRegion',
						'addressCountry' => $countryCode
					]
				];

				if ( ! empty( $locationData['postcode'] ) ) {
					$shippingDetail['shippingDestination']['postalCode'] = $locationData['postcode'];
				}

				$shippingDetails[] = $shippingDetail;
			}

			foreach ( $locationData['state'] as $countryCode => $stateCodes ) {
				$shippingDetails[] = [
					'@type'               => 'OfferShippingDetails',
					'shippingRate'        => [
						'@type'    => 'MonetaryAmount',
						'value'    => $locationData['rate'],
						'currency' => $this->getPriceCurrency()
					],
					'shippingDestination' => [
						'@type'          => 'DefinedRegion',
						'addressCountry' => $countryCode,
						'addressRegion'  => $stateCodes
					]
				];
			}
		}

		WC()->cart->empty_cart();

		if ( is_object( $originalCustomer ) ) {
			$woocommerce->customer = $originalCustomer;
		}

		if ( is_object( $originalSession ) ) {
			$woocommerce->session = $originalSession;
		}

		return $shippingDetails;
	}

	/**
	 * Helper to add the product to the cart.
	 * This is in a separate method because some plugins might interfere with the add to cart process.
	 * We need to remove these hooks temporarily to ensure the product is added to the cart.
	 * We then readd the hooks to ensure the add to cart process works as expected.
	 *
	 * @since 4.6.7
	 *
	 * @return void
	 */
	private function addToCart() {
		global $wp_filter; // phpcs:ignore Squiz.NamingConventions.ValidVariableName

		$skippedHooks = [];
		$hooksToSkip  = apply_filters( 'aioseo_schema_woocommerce_add_to_cart_skip_hooks', [
			'MonsterInsights_eCommerce_WooCommerce_Integration' => 'add_to_cart',
			'WC_Facebookcommerce_EventsTracker'                 => 'inject_add_to_cart_event',
			'ElementorPro\Modules\Woocommerce\Module'           => 'localize_added_to_cart_on_product_single',
			'nm_add_to_cart_class'                              => true
		] );

		// First we need to remove any hooks that might interfere with the add to cart process.
		if ( ! empty( $wp_filter['woocommerce_add_to_cart'] ) ) { // phpcs:ignore Squiz.NamingConventions.ValidVariableName
			$callbacks = $wp_filter['woocommerce_add_to_cart']->callbacks; // phpcs:ignore Squiz.NamingConventions.ValidVariableName
			foreach ( $callbacks as $priority => $callback ) {
				foreach ( $callback as $value ) {
					// Callback is a class method.
					if (
						is_array( $value['function'] ) &&
						is_object( $value['function'][0] ) &&
						! empty( $hooksToSkip[ get_class( $value['function'][0] ) ] ) &&
						$hooksToSkip[ get_class( $value['function'][0] ) ] === $value['function'][1]
					) {
						remove_action( 'woocommerce_add_to_cart', [ $value['function'][0], $value['function'][1] ], $priority );
						$skippedHooks[] = array_merge( $value, [ 'priority' => $priority ] );

						continue;
					}

					// Callback is a function.
					if (
						is_string( $value['function'] ?? null ) &&
						true === ( $hooksToSkip[ $value['function'] ] ?? '' )
					) {
						remove_action( 'woocommerce_add_to_cart', $value['function'], $priority );
						$skippedHooks[] = array_merge( $value, [ 'priority' => $priority ] );
					}
				}
			}
		}

		// Add the product to the cart.
		WC()->cart->add_to_cart( $this->product->get_id() );

		// Then we readd them back.
		foreach ( $skippedHooks as $hook ) {
			if ( is_array( $hook['function'] ) ) {
				add_action( 'woocommerce_add_to_cart', [ $hook['function'][0], $hook['function'][1] ], $hook['priority'], $hook['accepted_args'] );
			} else {
				add_action( 'woocommerce_add_to_cart', $hook['function'], $hook['priority'], $hook['accepted_args'] );
			}
		}
	}

	/**
	 * Returns the product category.
	 *
	 * @since 4.0.13
	 *
	 * @return string The product category.
	 */
	protected function getCategory() {
		$categories = wp_get_post_terms( $this->product->get_id(), 'product_cat', [ 'fields' => 'names' ] );

		return ! empty( $categories ) && __( 'Uncategorized' ) !== $categories[0] ? $categories[0] : ''; // phpcs:ignore AIOSEO.Wp.I18n.MissingArgDomain
	}

	/**
	 * Returns the AggregateRating graph data.
	 *
	 * @since 4.0.13
	 *
	 * @return array The graph data.
	 */
	protected function getWooCommerceAggregateRating() {
		if ( ! $this->isSchemaReviewEnabled() ) {
			// If WooCommerce reviews are disabled, check if manual reviews have been added.
			$sumOfRatings = 0;
			$reviewCount  = 0;
			if ( ! empty( $this->graphData->properties->autogenerate ) ) {
				foreach ( $this->graphData->properties->reviews as $reviewData ) {
					if ( ! $this->isValidReview( $reviewData ) ) {
						continue;
					}

					$reviewCount++;
					$sumOfRatings += (int) $reviewData->rating;
				}
			}

			if ( ! $reviewCount ) {
				return [];
			}

			return [
				'@type'       => 'AggregateRating',
				'@id'         => aioseo()->schema->context['url'] . '#aggregrateRating',
				'worstRating' => ! empty( $this->graphData->properties->rating->minimum ) ? (float) $this->graphData->properties->rating->minimum : 1,
				'bestRating'  => ! empty( $this->graphData->properties->rating->maximum ) ? (float) $this->graphData->properties->rating->maximum : 5,
				'ratingValue' => (float) $sumOfRatings / $reviewCount,
				'reviewCount' => $reviewCount
			];
		}

		if ( ! method_exists( $this->product, 'get_average_rating' ) || false === $this->product->get_average_rating() ) {
			return [];
		}

		$reviewCount = (int) $this->product->get_review_count();
		if ( 0 === $reviewCount ) {
			return [];
		}

		return [
			'@type'       => 'AggregateRating',
			'@id'         => aioseo()->schema->context['url'] . '#aggregrateRating',
			'worstRating' => 1,
			'bestRating'  => 5,
			'ratingValue' => (float) $this->product->get_average_rating(),
			'reviewCount' => $reviewCount
		];
	}

	/**
	 * Returns the Review graph data.
	 *
	 * @since 4.0.13
	 *
	 * @return array The graph data.
	 */
	protected function getWooCommerceReview() {
		if ( ! $this->isSchemaReviewEnabled() ) {
			// If WooCommerce reviews are disabled, check if manual reviews have been added.
			$reviews = [];
			if ( ! empty( $this->graphData->properties->autogenerate ) ) {
				foreach ( $this->graphData->properties->reviews as $reviewData ) {
					if ( ! $this->isValidReview( $reviewData ) ) {
						continue;
					}

					$reviews[] = [
						'@type'        => 'Review',
						'headline'     => $reviewData->headline,
						'reviewBody'   => $reviewData->content,
						'author'       => [
							'@type' => 'Person',
							'name'  => $reviewData->author
						],
						'reviewRating' => [
							'@type'       => 'Rating',
							'ratingValue' => (float) $reviewData->rating,
							'worstRating' => ! empty( $this->graphData->properties->rating->minimum ) ? (float) $this->graphData->properties->rating->minimum : 1,
							'bestRating'  => ! empty( $this->graphData->properties->rating->maximum ) ? (float) $this->graphData->properties->rating->maximum : 5
						]
					];
				}
			}

			return $reviews;
		}

		$comments = get_comments( [
			'post_id' => $this->product->get_id(),
			'type'    => 'review',
			'status'  => 'approve',
			'number'  => 25
		] );

		if ( empty( $comments ) ) {
			return [];
		}

		$reviews = [];
		foreach ( $comments as $comment ) {
			$ratingValue = (float) get_comment_meta( $comment->comment_ID, 'rating', true );
			if ( ! is_numeric( $ratingValue ) ) {
				continue;
			}

			// If a review has no rating, WooCommerce falls back to a 1 star rating.
			if ( 0 === absint( $ratingValue ) ) {
				$ratingValue = 1;
			}

			$review = [
				'@type'         => 'Review',
				'reviewRating'  => [
					'@type'       => 'Rating',
					'ratingValue' => $ratingValue,
					'worstRating' => 1,
					'bestRating'  => 5
				],
				'author'        => [
					'@type' => 'Person',
					'name'  => $comment->comment_author
				],
				'datePublished' => mysql2date( DATE_W3C, $comment->comment_date_gmt, false )
			];

			if ( ! empty( $comment->comment_content ) ) {
				$review['reviewBody'] = $comment->comment_content;
			}

			$reviews[] = $review;
		}

		return $reviews;
	}

	/**
	 * Generates common product schema properties.
	 *
	 * @since 4.6.8
	 *
	 * @param \WC_Product $product   The WooCommerce product.
	 * @param object      $graphData Additional graph data.
	 *
	 * @return array The common product schema properties.
	 */
	protected function getCommonProductSchema( $product, $graphData ) {
		return [
			'@type'        => 'Product',
			'@id'          => ! empty( $graphData->id ) ? aioseo()->schema->context['url'] . $graphData->id : aioseo()->schema->context['url'] . '#wooCommerceProduct',
			'name'         => method_exists( $product, 'get_name' ) ? $product->get_name() : get_the_title(),
			'description'  => $this->getShortDescription(),
			'url'          => aioseo()->schema->context['url'],
			'brand'        => $this->getBrand( $product ),
			'sku'          => method_exists( $product, 'get_sku' ) ? $product->get_sku() : '',
			'gtin'         => $this->getGtin( $product ),
			'mpn'          => ! empty( $graphData->properties->identifiers->mpn ) ? $graphData->properties->identifiers->mpn : '',
			'isbn'         => ! empty( $graphData->properties->identifiers->isbn ) ? $graphData->properties->identifiers->isbn : '',
			'material'     => ! empty( $graphData->properties->attributes->material ) ? $graphData->properties->attributes->material : '',
			'color'        => ! empty( $graphData->properties->attributes->color ) ? $graphData->properties->attributes->color : '',
			'pattern'      => ! empty( $graphData->properties->attributes->pattern ) ? $graphData->properties->attributes->pattern : '',
			'size'         => ! empty( $graphData->properties->attributes->size ) ? $graphData->properties->attributes->size : '',
			'energyRating' => ! empty( $graphData->properties->attributes->energyRating ) ? $graphData->properties->attributes->energyRating : '',
			'image'        => $this->getImage( $product )
		];
	}

	/**
	 * Returns the graph data for a single variation or grouped product.
	 * This is used when generating the graph data for a variable or grouped product.
	 * This function is called for each variation or grouped product.
	 *
	 * @since 4.6.8
	 *
	 * @param array            $variation The variation data.
	 * @param object           $graphData The graph data.
	 * @param \WC_Product|null $product   The WooCommerce product.
	 * @return array                      The graph data for a single variation or grouped product.
	 */
	private function getSingleVariationOrGroupedProductGraphData( $variation, $graphData, $product = null ) {
		if ( ! is_a( $product, 'WC_Product' ) ) {
			$product = $this->product;
		}

		$variationProduct = $this->getVariationOrGroupedProduct( $product, $variation );
		if ( ! is_a( $variationProduct, 'WC_Product' ) ) {
			return [];
		}

		$variationProductData = $this->getCommonProductSchema( $variationProduct, $graphData );

		if ( $product instanceof \WC_Product_Variable ) {
			$attributes                   = $variationProduct->get_attributes();
			$variationProductData['name'] = $variationProduct->get_title() . ' - ' . implode( ', ', array_values( $attributes ) );
		} elseif ( $product instanceof \WC_Product_Grouped ) {
			$variationProductData['name'] = $variationProduct->get_title();
		}

		$offerPrice = method_exists( $variationProduct, 'get_price' ) ? $variationProduct->get_price() : 0;

		$offer = [
			'@type'         => 'Offer',
			'url'           => ! empty( $this->graphData->properties->id )
				? aioseo()->schema->context['url'] . '#wooCommerceOffer-' . $this->graphData->id
				: aioseo()->schema->context['url'] . '#wooCommerceOffer',
			'price'         => $offerPrice,
			'priceCurrency' => $this->getPriceCurrency(),
			'category'      => $this->getCategory(),
			'availability'  => $this->getAvailability( $variationProduct ),
		];

		$offer['priceSpecification']    = $this->getPriceSpecification( (float) $offerPrice );
		$variationProductData['offers'] = $offer;

		return $variationProductData;
	}

	/**
	 * Determines if taxes should be calculated.
	 *
	 * @since 4.6.8
	 *
	 * @return bool True if taxes should be calculated, false otherwise.
	 */
	protected function shouldCalculateTaxes() {
		return 'yes' === get_option( 'woocommerce_calc_taxes', 'no' ) &&
			'yes' === get_option( 'woocommerce_prices_include_tax', 'no' );
	}

	/**
	 * Returns the price specification.
	 *
	 * @since 4.6.8
	 *
	 * @param float $offerPrice The offer price.
	 * @return array            The price specification.
	 */
	protected function getPriceSpecification( $offerPrice ) {
		if ( ! $this->shouldCalculateTaxes() && empty( $offerPrice ) ) {
			return [];
		}

		return [
			'@type'                 => 'PriceSpecification',
			'price'                 => (float) $offerPrice,
			'priceCurrency'         => $this->getPriceCurrency(),
			'valueAddedTaxIncluded' => 'true'
		];
	}

	/**
	 * Returns the variation or grouped product.
	 *
	 * @since 4.6.8
	 *
	 * @param \WC_Product $product   The WooCommerce product.
	 * @param array       $variation The variation data.
	 * @return \WC_Product|null       The variation or grouped product.
	 */
	private function getVariationOrGroupedProduct( $product, $variation ) {
		if ( $product instanceof \WC_Product_Variable ) {
			return new \WC_Product_Variation( $variation['variation_id'] );
		} elseif ( $product instanceof \WC_Product_Grouped ) {
			return wc_get_product( $variation );
		}

		return null;
	}

	/**
	 * Checks if schema reviews are enabled for the product.
	 *
	 * This method first determines if the product has reviews enabled by WooCommerce for the product.
	 * Additionally, it allows overriding via the `aioseo_schema_woocommerce_review_enabled` filter.
	 *
	 * @since 4.6.9
	 *
	 * @return bool Whether schema reviews are enabled for the product.
	 */
	protected function isSchemaReviewEnabled() {
		$reviewEnabled = method_exists( $this->product, 'get_reviews_allowed' ) && $this->product->get_reviews_allowed();

		return apply_filters( 'aioseo_schema_woocommerce_review_enabled', $reviewEnabled, $this->product );
	}

	/**
	 * Checks if the review data is valid.
	 *
	 * @since 4.6.9
	 *
	 * @param  object $reviewData The review data.
	 * @return bool               Whether the review data is valid.
	 */
	protected function isValidReview( $reviewData ) {
		return is_numeric( $reviewData->rating ) && ! empty( $reviewData->author );
	}
}