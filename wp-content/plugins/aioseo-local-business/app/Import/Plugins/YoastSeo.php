<?php
namespace AIOSEO\Plugin\Addon\LocalBusiness\Import\Plugins;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Models;
use AIOSEO\Plugin\Addon\LocalBusiness\Import;

/**
 * The YoastSEO importer class.
 *
 * @since   4.0.0
 * @version 1.3.0 Moved from Pro.
 */
class YoastSeo extends Import\Importer {
	/**
	 * List of options.
	 *
	 * @since   4.2.7
	 * @version 1.3.0 Moved from Pro.
	 *
	 * @var array
	 */
	private $options = [];

	/**
	 * A list of plugins to look for to import.
	 *
	 * @since 1.3.0
	 *
	 * @var array
	 */
	public $plugins = [
		[
			'name'     => 'Yoast SEO: Local',
			'version'  => '14.9',
			'basename' => 'wpseo-local/local-seo.php',
			'slug'     => 'yoast-local-seo'
		]
	];

	/**
	 * Import.
	 *
	 * @since   4.0.0
	 * @version 1.3.0 Moved from Pro.
	 *
	 * @return void
	 */
	public function doImport() {
		$this->options = get_option( 'wpseo_local' );
		if ( empty( $this->options ) ) {
			return;
		}

		// Yoast SEO doesn't have a setting for this, so we'll use the Organization Name.
		aioseo()->options->localBusiness->locations->business->name = aioseo()->options->searchAppearance->global->schema->organizationName;

		if ( ! empty( $this->options['business_type'] ) ) {
			$this->importLocalBusinessType( $this->options['business_type'] );
		}

		if ( ! empty( $this->options['location_country'] ) ) {
			$this->importLocalBusinessCountry( $this->options['location_country'] );
		}

		if ( ! empty( $this->options['location_phone'] ) ) {
			$this->importLocalBusinessPhoneNumber( $this->options['location_phone'] );
		}

		if ( ! empty( $this->options['location_fax'] ) ) {
			$this->importLocalBusinessFaxNumber( $this->options['location_fax'] );
		}

		if ( ! empty( $this->options['location_currencies_accepted'] ) ) {
			$currencies = array_filter( explode( ',', $this->options['location_currencies_accepted'] ) );
			$this->importCurrencies( $currencies );
		}

		$settings = [
			'location_email'            => [
				'type'      => 'string',
				'newOption' => [ 'localBusiness', 'locations', 'business', 'contact', 'email' ]
			],
			'location_address'          => [
				'type'      => 'string',
				'newOption' => [ 'localBusiness', 'locations', 'business', 'address', 'streetLine1' ]
			],
			'location_address_2'        => [
				'type'      => 'string',
				'newOption' => [ 'localBusiness', 'locations', 'business', 'address', 'streetLine2' ]
			],
			'location_city'             => [
				'type'      => 'string',
				'newOption' => [ 'localBusiness', 'locations', 'business', 'address', 'city' ]
			],
			'location_state'            => [
				'type'      => 'string',
				'newOption' => [ 'localBusiness', 'locations', 'business', 'address', 'state' ]
			],
			'location_zipcode'          => [
				'type'      => 'string',
				'newOption' => [ 'localBusiness', 'locations', 'business', 'address', 'zipCode' ]
			],
			'location_vat_id'           => [
				'type'      => 'string',
				'newOption' => [ 'localBusiness', 'locations', 'business', 'ids', 'vat' ]
			],
			'location_tax_id'           => [
				'type'      => 'string',
				'newOption' => [ 'localBusiness', 'locations', 'business', 'ids', 'tax' ]
			],
			'location_coc_id'           => [
				'type'      => 'string',
				'newOption' => [ 'localBusiness', 'locations', 'business', 'ids', 'chamberOfCommerce' ]
			],
			'location_price_range'      => [
				'type'      => 'string',
				'newOption' => [ 'localBusiness', 'locations', 'business', 'payment', 'priceRange' ]
			],
			'location_payment_accepted' => [
				'type'      => 'string',
				'newOption' => [ 'localBusiness', 'locations', 'business', 'payment', 'methods' ]
			],
			'location_area_served'      => [
				'type'      => 'string',
				'newOption' => [ 'localBusiness', 'locations', 'business', 'areaServed' ]
			],
		];

		$this->mapOldToNew( $settings, $this->options );

		$this->importAddressFormat();
		$this->importOpeningHourSettings();
		$this->importMapSettings();
		$this->importMultipleLocations();
	}

	/**
	 * Imports the Local Business opening hour settings.
	 *
	 * @since   4.0.0
	 * @version 1.3.0 Moved from Pro.
	 *
	 * @return void
	 */
	private function importOpeningHourSettings() {
		if ( ! empty( $this->options['hide_opening_hours'] ) ) {
			aioseo()->options->localBusiness->openingHours->show = 'off' === $this->options['hide_opening_hours'];
		}

		if ( ! empty( $this->options['open_247'] ) ) {
			aioseo()->options->localBusiness->openingHours->alwaysOpen = 'on' === $this->options['open_247'];
		}

		aioseo()->options->localBusiness->openingHours->labels->closed = '';
		if ( ! empty( $this->options['closed_label'] ) ) {
			aioseo()->options->localBusiness->openingHours->labels->closed = $this->options['closed_label'];
		}

		aioseo()->options->localBusiness->openingHours->labels->alwaysOpen = '';
		if ( ! empty( $this->options['open_24h_label'] ) ) {
			aioseo()->options->localBusiness->openingHours->labels->alwaysOpen = $this->options['open_24h_label'];
		}

		if ( ! empty( $this->options['opening_hours_24h'] ) ) {
			aioseo()->options->localBusiness->openingHours->use24hFormat = 'on' === $this->options['opening_hours_24h'];
		}

		$days = $this->formatOpeningHoursDays( $this->options );
		foreach ( $days as $day => $dayOptions ) {
			foreach ( $dayOptions as $option => $value ) {
				aioseo()->options->localBusiness->openingHours->days->$day->$option = $value;
			}
		}
	}

	/**
	 * Formats the opening hours.
	 *
	 * @since 1.3.0
	 *
	 * @param  array $options             The options array from Yoast.
	 * @param  array $inheritOpeningHours The options array from Yoast, to inherit from glboal settings.
	 * @return array                      The formatted opening hours.
	 */
	private function formatOpeningHoursDays( $options, $inheritOpeningHours = [] ) {
		$openingHoursDays = [];
		$days             = aioseo()->options->localBusiness->openingHours->days->all();

		foreach ( $days as $name => $values ) {
			$open24h = isset( $options[ "opening_hours_{$name}_24h" ] ) ? $options[ "opening_hours_{$name}_24h" ] : '';
			// Check if the value should be inherited and if it's not overridden.
			if ( isset( $inheritOpeningHours[ "opening_hours_{$name}_24h" ] ) ) {
				if ( ! isset( $options[ "opening_hours_{$name}_override" ] ) || 'on' !== $options[ "opening_hours_{$name}_override" ] ) {
					$open24h = $inheritOpeningHours[ "opening_hours_{$name}_24h" ];
				}
			}

			$openTime  = isset( $options[ "opening_hours_{$name}_from" ] ) ? $options[ "opening_hours_{$name}_from" ] : '';
			$closeTime = isset( $options[ "opening_hours_{$name}_to" ] ) ? $options[ "opening_hours_{$name}_to" ] : '';
			// Check if the value should be inherited and if it's not overridden.
			if ( isset( $inheritOpeningHours[ "opening_hours_{$name}_from" ] ) ) {
				if ( ! isset( $options[ "opening_hours_{$name}_override" ] ) || 'on' !== $options[ "opening_hours_{$name}_override" ] ) {
					$openTime  = $inheritOpeningHours[ "opening_hours_{$name}_from" ];
					$closeTime = $inheritOpeningHours[ "opening_hours_{$name}_to" ];
				}
			}

			if ( ! empty( $open24h ) ) {
				$openingHoursDays[ $name ]['open24h'] = 'on' === $open24h;
			}

			if ( ! empty( $openTime ) ) {
				if ( 'closed' === $openTime ) {
					$openingHoursDays[ $name ]['closed'] = true;
					continue;
				}

				$openingHoursDays[ $name ]['closed'] = false;
				$openingHoursDays[ $name ]['openTime'] = $openTime;
			}

			if ( ! empty( $closeTime ) ) {
				if ( 'closed' === $closeTime ) {
					$openingHoursDays[ $name ]['closed'] = true;
					continue;
				}

				$openingHoursDays[ $name ]['closed'] = false;
				$openingHoursDays[ $name ]['closeTime'] = $closeTime;
			}
		}

		return $openingHoursDays;
	}

	/**
	 * Imports the Address format.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	private function importAddressFormat() {
		if ( empty( $this->options['address_format'] ) ) {
			return;
		}

		$formatMap = [
			'address-state-postal'       => '#streetLineOne #streetLineTwo #city, #state #zipCode',
			'address-state-postal-comma' => '#streetLineOne #streetLineTwo #city, #state, #zipCode',
			'address-postal-city-state'  => '#streetLineOne #streetLineTwo #zipCode #city, #state',
			'address-postal'             => '#streetLineOne #streetLineTwo #city #zipCode',
			'address-postal-comma'       => '#streetLineOne #streetLineTwo #city, #zipCode',
			'address-city'               => '#streetLineOne #streetLineTwo #city',
			'postal-address'             => '#zipCode #state #city #streetLineOne #streetLineTwo'
		];

		if ( empty( $formatMap[ $this->options['address_format'] ] ) ) {
			return;
		}

		aioseo()->options->localBusiness->locations->business->address->addressFormat = $formatMap[ $this->options['address_format'] ];
	}

	/**
	 * Imports multiple locations.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	private function importMultipleLocations() {
		aioseo()->options->localBusiness->locations->general->multiple = ! empty( $this->options['use_multiple_locations'] ) && 'on' === $this->options['use_multiple_locations'];

		if ( ! empty( $this->options['locations_label_singular'] ) ) {
			aioseo()->options->localBusiness->locations->general->singleLabel = $this->options['locations_label_singular'];
		}

		if ( ! empty( $this->options['locations_label_plural'] ) ) {
			aioseo()->options->localBusiness->locations->general->pluralLabel = $this->options['locations_label_plural'];
		}

		if ( ! empty( $this->options['locations_slug'] ) ) {
			aioseo()->options->localBusiness->locations->general->useCustomSlug = true;
			aioseo()->options->localBusiness->locations->general->customSlug    = $this->options['locations_slug'];
		}

		if ( ! empty( $this->options['locations_taxo_slug'] ) ) {
			aioseo()->options->localBusiness->locations->general->useCustomCategorySlug = true;
			aioseo()->options->localBusiness->locations->general->customCategorySlug    = $this->options['locations_taxo_slug'];
		}

		if ( ! empty( $this->options['local_enhanced_search'] ) && 'on' === $this->options['local_enhanced_search'] ) {
			aioseo()->options->localBusiness->locations->general->enhancedSearch = true;
		}

		if ( aioseo()->options->localBusiness->locations->general->multiple ) {
			// Query wpseo-local categories.
			$importedCategories = $this->importTaxonomyTerms( 'wpseo_locations_category' );

			// Query wpseo-local locations.
			$locations = get_posts( [
				'post_type'      => 'wpseo_locations',
				'posts_per_page' => -1,
				'post_status'    => 'any'
			] );

			foreach ( $locations as $location ) {
				$this->importLocation( $location, $importedCategories );
			}
		}
	}

	/**
	 * Imports a Location.
	 *
	 * @since 1.3.0
	 *
	 * @param  \WP_Post $yoastLocation      The location to import.
	 * @param  array    $importedCategories The imported categories.
	 * @return void
	 */
	private function importLocation( $yoastLocation, $importedCategories ) {
		// Get the Yoast meta.
		$yoastMetaRaw = get_post_meta( $yoastLocation->ID );

		// Filter only needed data.
		$yoastMetaRaw = array_filter( $yoastMetaRaw, function ( $key ) {
			return 0 === strpos( $key, '_wpseo_' );
		}, ARRAY_FILTER_USE_KEY );

		// Flatten the Yoast meta.
		$yoastMetaRaw = array_map( function ( $n ) {
			return $n[0];
		}, $yoastMetaRaw );

		// Normalize meta.
		$yoastMeta = [];
		foreach ( $yoastMetaRaw as $key => $value ) {
			$yoastMeta[ preg_replace( '%^_wpseo_%', '', (string) $key ) ] = $value;
		}

		$inheritBusinessInfo = [];

		if ( isset( $this->options['multiple_locations_shared_business_info'] ) && 'on' === $this->options['multiple_locations_shared_business_info'] ) {
			$inheritBusinessInfo = [
				'business_type'                => $this->options['business_type'],
				'business_phone'               => $this->options['location_phone'],
				'business_fax'                 => $this->options['location_fax'],
				'business_email'               => $this->options['location_email'],
				'business_vat_id'              => $this->options['location_vat_id'],
				'business_tax_id'              => $this->options['location_tax_id'],
				'business_coc_id'              => $this->options['location_coc_id'],
				'business_price_range'         => $this->options['location_price_range'],
				'business_currencies_accepted' => $this->options['location_currencies_accepted'],
				'business_payment_accepted'    => $this->options['location_payment_accepted'],
				'business_area_served'         => $this->options['location_area_served'],
			];
		}

		// Location and business data.
		$locationData = [
			'business_name'                => [ 'locations', 'business', 'name' ],
			'business_type'                => [ 'locations', 'business', 'businessType' ],
			'business_location_logo'       => [ 'locations', 'business', 'image' ],
			'business_address'             => [ 'locations', 'business', 'address', 'streetLine1' ],
			'business_address_2'           => [ 'locations', 'business', 'address', 'streetLine2' ],
			'business_city'                => [ 'locations', 'business', 'address', 'city' ],
			'business_state'               => [ 'locations', 'business', 'address', 'state' ],
			'business_zipcode'             => [ 'locations', 'business', 'address', 'zipCode' ],
			'business_country'             => [ 'locations', 'business', 'address', 'country' ],
			'business_phone'               => [ 'locations', 'business', 'contact', 'phone' ],
			'business_fax'                 => [ 'locations', 'business', 'contact', 'fax' ],
			'business_email'               => [ 'locations', 'business', 'contact', 'email' ],
			'business_vat_id'              => [ 'locations', 'business', 'ids', 'vat' ],
			'business_tax_id'              => [ 'locations', 'business', 'ids', 'tax' ],
			'business_coc_id'              => [ 'locations', 'business', 'ids', 'chamberOfCommerce' ],
			'business_price_range'         => [ 'locations', 'business', 'payment', 'priceRange' ],
			'business_currencies_accepted' => [ 'locations', 'business', 'payment', 'currenciesAccepted' ],
			'business_payment_accepted'    => [ 'locations', 'business', 'payment', 'methods' ],
			'business_area_served'         => [ 'locations', 'business', 'areaServed' ]
		];

		// Create the data array.
		$dataArray = [];

		foreach ( $locationData as $metaKey => $locationKeys ) {
			$metaValue = ! empty( $yoastMeta[ $metaKey ] ) ? $yoastMeta[ $metaKey ] : null;

			// Check if the value should be inherited and if it's not overridden.
			if ( ! empty( $inheritBusinessInfo ) && isset( $inheritBusinessInfo[ $metaKey ] ) ) {
				if ( ! isset( $yoastMeta[ 'is_overridden_' . $metaKey ] ) || 'on' !== $yoastMeta[ 'is_overridden_' . $metaKey ] ) {
					$metaValue = $inheritBusinessInfo[ $metaKey ];
				}
			}

			if ( ! empty( $metaValue ) ) {
				// Treat currencies.
				if ( 'business_currencies_accepted' === $metaKey ) {
					$metaValue = array_filter( explode( ',', $metaValue ) );
					$metaValue = $this->formatCurrencies( $metaValue );
					if ( ! empty( $metaValue['supported'] ) ) {
						$metaValue = wp_json_encode( $metaValue['supported'] );
					}
				}

				$metaValue = aioseo()->helpers->createMultidimensionalArray( $locationKeys, $metaValue );
				$dataArray = array_merge_recursive( $dataArray, $metaValue );
			}
		}

		// Opening hours.
		$inheritOpeningHours = [];

		if ( isset( $this->options['multiple_locations_shared_opening_hours'] ) && 'on' === $this->options['multiple_locations_shared_opening_hours'] ) {
			$inheritOpeningHours = [
				'opening_hours_monday_from'        => $this->options['opening_hours_monday_from'],
				'opening_hours_monday_to'          => $this->options['opening_hours_monday_to'],
				'opening_hours_tuesday_from'       => $this->options['opening_hours_tuesday_from'],
				'opening_hours_tuesday_to'         => $this->options['opening_hours_tuesday_to'],
				'opening_hours_wednesday_from'     => $this->options['opening_hours_wednesday_from'],
				'opening_hours_wednesday_to'       => $this->options['opening_hours_wednesday_to'],
				'opening_hours_thursday_from'      => $this->options['opening_hours_thursday_from'],
				'opening_hours_thursday_to'        => $this->options['opening_hours_thursday_to'],
				'opening_hours_friday_from'        => $this->options['opening_hours_friday_from'],
				'opening_hours_friday_to'          => $this->options['opening_hours_friday_to'],
				'opening_hours_saturday_from'      => $this->options['opening_hours_saturday_from'],
				'opening_hours_saturday_to'        => $this->options['opening_hours_saturday_to'],
				'opening_hours_sunday_from'        => $this->options['opening_hours_sunday_from'],
				'opening_hours_sunday_to'          => $this->options['opening_hours_sunday_to'],
				'opening_hours_sunday_second_from' => $this->options['opening_hours_sunday_second_from'],
				'opening_hours_sunday_second_to'   => $this->options['opening_hours_sunday_second_to'],
				'opening_hours_monday_24h'         => $this->options['opening_hours_monday_24h'],
				'opening_hours_tuesday_24h'        => $this->options['opening_hours_tuesday_24h'],
				'opening_hours_wednesday_24h'      => $this->options['opening_hours_wednesday_24h'],
				'opening_hours_thursday_24h'       => $this->options['opening_hours_thursday_24h'],
				'opening_hours_friday_24h'         => $this->options['opening_hours_friday_24h'],
				'opening_hours_saturday_24h'       => $this->options['opening_hours_saturday_24h'],
				'opening_hours_sunday_24h'         => $this->options['opening_hours_sunday_24h'],
			];
		}

		$use24hFormat = 'on' === ( isset( $yoastMeta['format_24h'] ) ? $yoastMeta['format_24h'] : '' );
		// Check if the value should be inherited and if it's not overridden.
		if ( isset( $this->options['opening_hours_24h'] ) && ( ! isset( $yoastMeta['format_24h_override'] ) || 'on' !== $yoastMeta['format_24h_override'] ) ) {
			$use24hFormat = 'on' === $this->options['opening_hours_24h'];
		}

		$alwaysOpen = 'on' === ( isset( $yoastMeta['open_247'] ) ? $yoastMeta['open_247'] : '' );
		// Check if the value should be inherited and if it's not overridden.
		if ( isset( $this->options['open_247'] ) && ( ! isset( $yoastMeta['open_247_override'] ) || 'on' !== $yoastMeta['open_247_override'] ) ) {
			$alwaysOpen = 'on' === $this->options['open_247'];
		}

		$dataArray['openingHours'] = [
			'useDefaults'  => false,
			'show'         => true,
			'use24hFormat' => $use24hFormat,
			'alwaysOpen'   => $alwaysOpen,
			'days'         => $this->formatOpeningHoursDays( $yoastMeta, $inheritOpeningHours ),
			'labels'       => [
				'closed'     => ! empty( $this->options['closed_label'] ) ? $this->options['closed_label'] : '',
				'alwaysOpen' => ! empty( $this->options['open_24h_label'] ) ? $this->options['open_24h_label'] : ''
			]
		];

		// Maps.
		if ( ! empty( $yoastMeta['coordinates_lat'] ) && ! empty( $yoastMeta['coordinates_long'] ) ) {
			$dataArray['maps'] = [
				'mapOptions'   => [
					'center' => [
						'lat' => $yoastMeta['coordinates_lat'],
						'lng' => $yoastMeta['coordinates_long']
					]
				],
				'customMarker' => ! empty( $yoastMeta['business_location_custom_marker'] ) ? $yoastMeta['business_location_custom_marker'] : ''
			];
		}

		// Check if the location already exists.
		$wpPost = aioseoLocalBusiness()->locations->getLocationByName( $yoastLocation->post_name );

		// Create the location if it doesn't exist.
		if ( empty( $wpPost ) ) {
			$wpPost            = clone $yoastLocation;
			$wpPost->ID        = null;
			$wpPost->post_type = aioseoLocalBusiness()->postType->getName();
			$wpPost            = wp_insert_post( $wpPost );
			$wpPost            = get_post( $wpPost );
		}

		// Set the location categories.
		$categories       = wp_get_post_terms( $yoastLocation->ID, 'wpseo_locations_category' );
		$mappedCategories = [];
		foreach ( $categories as $category ) {
			if ( ! is_object( $category ) ) {
				$category = (object) $category;
			}

			if ( empty( $category->term_id ) ) {
				continue;
			}

			$importedCategory = ! empty( $importedCategories[ $category->term_id ] ) ? $importedCategories[ $category->term_id ] : null;
			if ( $importedCategory ) {
				$mappedCategories[] = $importedCategory->term_id;
			}
		}

		wp_set_post_terms( $wpPost->ID, $mappedCategories, aioseoLocalBusiness()->taxonomy->getName() );

		// Set AIOSEO's location data.
		$aioseoLocation = Models\Post::getPost( $wpPost->ID );
		$aioseoLocation->local_seo = $dataArray;
		$aioseoLocation->save();
	}

	/**
	 * Imports the Local Business map settings.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	private function importMapSettings() {
		if ( ! empty( $this->options['googlemaps_api_key'] ) ) {
			aioseo()->options->localBusiness->maps->apiKey = $this->options['googlemaps_api_key'];
			aioseo()->options->localBusiness->maps->apiKeyValid = true;
		}

		if ( ! empty( $this->options['map_view_style'] ) ) {
			aioseo()->options->localBusiness->maps->mapOptions->mapTypeId = strtolower( $this->options['map_view_style'] );
		}

		if ( ! empty( $this->options['location_coords_lat'] ) && ! empty( $this->options['location_coords_long'] ) ) {
			aioseo()->options->localBusiness->maps->mapOptions->center->lat = $this->options['location_coords_lat'];
			aioseo()->options->localBusiness->maps->mapOptions->center->lng = $this->options['location_coords_long'];
		}

		if ( ! empty( $this->options['local_custom_marker'] ) ) {
			aioseo()->options->localBusiness->maps->customMarker = wp_get_attachment_image_url( $this->options['local_custom_marker'], 'full' );
		}
	}
}