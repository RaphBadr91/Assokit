<?php
namespace AIOSEO\Plugin\Addon\LocalBusiness\Import\Plugins;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Addon\LocalBusiness\Import;

/**
 * Imports the Local Business settings.
 *
 * @since   4.1.4
 * @version 1.3.0 Moved from Pro.
 */
class SeoPress extends Import\Importer {
	/**
	 * List of options.
	 *
	 * @since   4.1.4
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
			'name'     => 'SEOPress PRO',
			'version'  => '4.0',
			'basename' => 'wp-seopress-pro/seopress-pro.php',
			'slug'     => 'seopress-pro'
		]
	];

	/**
	 * Import.
	 *
	 * @since   4.1.4
	 * @version 1.3.0 Moved from Pro.
	 *
	 */
	public function doImport() {
		$this->options = get_option( 'seopress_pro_option_name' );
		if ( empty( $this->options ) ) {
			return;
		}

		if ( ! empty( $this->options['seopress_local_business_type'] ) ) {
			$this->importLocalBusinessType( $this->options['seopress_local_business_type'] );
		}

		$this->importLocalBusinessAddress();
		$this->importLocalBusinessPriceRange();
		$this->importOpeningHourSettings();
		$this->importMapSettings();
	}

	/**
	 * Imports the Local Business price range.
	 *
	 * @since   4.1.4
	 * @version 1.3.0 Moved from Pro.
	 *
	 * @return void
	 */
	private function importLocalBusinessPriceRange() {
		if ( empty( $this->options['seopress_local_business_price_range'] ) ) {
			return;
		}

		$priceRange = $this->preparePriceRange( $this->options['seopress_local_business_price_range'] );
		if ( empty( $priceRange ) ) {
			return;
		}

		aioseo()->options->localBusiness->locations->business->payment->priceRange = $priceRange;
	}

	/**
	 * Imports the Local Business address.
	 *
	 * @since   4.1.4
	 * @version 1.3.0 Moved from Pro.
	 *
	 * @return void
	 */
	private function importLocalBusinessAddress() {
		if ( ! empty( $this->options['seopress_local_business_address_country'] ) ) {
			$this->importLocalBusinessCountry( $this->options['seopress_local_business_address_country'] );
		}

		$settings = [
			'seopress_local_business_street_address'   => [
				'type'      => 'string',
				'newOption' => [ 'localBusiness', 'locations', 'business', 'address', 'streetLine1' ]
			],
			'seopress_local_business_address_locality' => [
				'type'      => 'string',
				'newOption' => [ 'localBusiness', 'locations', 'business', 'address', 'city' ]
			],
			'seopress_local_business_address_region'   => [
				'type'      => 'string',
				'newOption' => [ 'localBusiness', 'locations', 'business', 'address', 'state' ]
			],
			'seopress_local_business_postal_code'      => [
				'type'      => 'string',
				'newOption' => [ 'localBusiness', 'locations', 'business', 'address', 'zipCode' ]
			],
			'seopress_local_business_phone'            => [
				'type'      => 'string',
				'newOption' => [ 'localBusiness', 'locations', 'business', 'contact', 'phone' ]
			],
		];

		$this->mapOldToNew( $settings, $this->options );
	}

	/**
	 * Imports the Local Business Opening Hours settings.
	 *
	 * @since   4.1.4
	 * @version 1.3.0 Moved from Pro.
	 *
	 * @return void
	 */
	private function importOpeningHourSettings() {
		$openingHours = $this->options['seopress_local_business_opening_hours'];
		if ( empty( $openingHours ) ) {
			return;
		}

		aioseo()->options->localBusiness->openingHours->use24hFormat = true;

		$days = [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ];

		foreach ( $openingHours as $key => $settings ) {
			if ( ! aioseo()->options->localBusiness->openingHours->days->has( $days[ $key ] ) ) {
				continue;
			}

			// Reset some defaults.
			aioseo()->options->localBusiness->openingHours->days->{$days[ $key ]}->closed  = false;
			aioseo()->options->localBusiness->openingHours->days->{$days[ $key ]}->open24h = false;

			// In SEOPress the option is called 'open' but being true means the business is closed.
			// That can be seen in the options where the label for the field is "Closed all the day?".
			// Also, can be seen in the schema output where it checks if the key exists, which means closed.
			if ( ! empty( $settings['open'] ) ) {
				aioseo()->options->localBusiness->openingHours->days->{$days[ $key ]}->closed = true;
			}

			// Closed this day.
			if ( empty( $settings['am']['open'] ) && empty( $settings['pm']['open'] ) ) {
				aioseo()->options->localBusiness->openingHours->days->{$days[ $key ]}->closed = true;
			}

			$meridiem = 'am';
			$openTime = $settings[ $meridiem ]['start']['hours'] . ':' . $settings[ $meridiem ]['start']['mins'];

			// Morning from 00:00 to 23:59 means open 24 hours.
			if ( '00:00' === $openTime && '23:59' === $settings[ $meridiem ]['end']['hours'] . ':' . $settings[ $meridiem ]['end']['mins'] ) {
				aioseo()->options->localBusiness->openingHours->days->{$days[ $key ]}->open24h = true;
			}

			if ( ! empty( $settings['pm']['open'] ) ) {
				$meridiem = 'pm';

				if ( empty( $settings['am']['open'] ) ) {
					$openTime = $settings[ $meridiem ]['start']['hours'] . ':' . $settings[ $meridiem ]['start']['mins'];
				}
			}

			$closeTime = $settings[ $meridiem ]['end']['hours'] . ':' . $settings[ $meridiem ]['end']['mins'];

			aioseo()->options->localBusiness->openingHours->days->{ $days[ $key ] }->openTime  = $openTime;
			aioseo()->options->localBusiness->openingHours->days->{ $days[ $key ] }->closeTime = $closeTime;
		}
	}

	/**
	 * Imports the Local Business map settings.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	private function importMapSettings() {
		if ( ! empty( $this->options['seopress_local_business_lat'] ) && ! empty( $this->options['seopress_local_business_lon'] ) ) {
			aioseo()->options->localBusiness->maps->mapOptions->center->lat = $this->options['seopress_local_business_lat'];
			aioseo()->options->localBusiness->maps->mapOptions->center->lng = $this->options['seopress_local_business_lon'];
		}

		if ( ! empty( $this->options['seopress_local_business_place_id'] ) ) {
			aioseo()->options->localBusiness->maps->placeId = $this->options['seopress_local_business_place_id'];
		}
	}
}