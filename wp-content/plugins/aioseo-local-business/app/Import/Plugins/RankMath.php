<?php
namespace AIOSEO\Plugin\Addon\LocalBusiness\Import\Plugins;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Addon\LocalBusiness\Import;

/**
 * Migrates the Local Business settings.
 *
 * These are contained in the Title & Meta section of Rank Math.
 *
 * @since   4.0.0
 * @version 1.3.0 Moved from Pro.
 */
class RankMath extends Import\Importer {
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
			'name'     => 'Rank Math SEO',
			'version'  => '1.0',
			'basename' => 'seo-by-rank-math/rank-math.php',
			'slug'     => 'rank-math-seo'
		]
	];

	/**
	 * Import.
	 *
	 * @since   4.0.0
	 * @version 1.3.0 Moved from Pro.
	 */
	public function doImport() {
		$this->options = get_option( 'rank-math-options-titles' );
		if ( empty( $this->options ) ) {
			return;
		}

		if ( isset( $this->options['local_business_type'] ) ) {
			$this->importLocalBusinessType( $this->options['local_business_type'] );
		}

		if ( ! empty( $this->options['email'] ) ) {
			aioseo()->options->localBusiness->locations->business->contact->email = $this->options['email'];
		}

		$this->importLocalBusinessAddress();
		$this->importPhoneNumber();
		$this->importLocalBusinessPriceRange();
		$this->importOpeningHourSettings();
		$this->importMapSettings();
	}

	/**
	 * Migrates the Local Business price range.
	 *
	 * @since   4.0.0
	 * @version 1.3.0 Moved from Pro.
	 *
	 * @return void
	 */
	private function importLocalBusinessPriceRange() {
		if ( empty( $this->options['price_range'] ) ) {
			return;
		}

		$priceRange = $this->preparePriceRange( $this->options['price_range'] );
		if ( empty( $priceRange ) ) {
			return;
		}

		aioseo()->options->localBusiness->locations->business->payment->priceRange = $priceRange;
	}

	/**
	 * Migrates the Local Business address.
	 *
	 * @since   4.0.0
	 * @version 1.3.0 Moved from Pro.
	 *
	 * @return void
	 */
	private function importLocalBusinessAddress() {
		if ( empty( $this->options['local_address'] ) ) {
			return;
		}

		if ( ! empty( $this->options['local_address']['addressCountry'] ) ) {
			$this->importLocalBusinessCountry( $this->options['local_address']['addressCountry'] );
		}

		$settings = [
			'streetAddress'   => [
				'type'      => 'string',
				'newOption' => [ 'localBusiness', 'locations', 'business', 'address', 'streetLine1' ]
			],
			'addressLocality' => [
				'type'      => 'string',
				'newOption' => [ 'localBusiness', 'locations', 'business', 'address', 'city' ]
			],
			'addressRegion'   => [
				'type'      => 'string',
				'newOption' => [ 'localBusiness', 'locations', 'business', 'address', 'state' ]
			],
			'postalCode'      => [
				'type'      => 'string',
				'newOption' => [ 'localBusiness', 'locations', 'business', 'address', 'zipCode' ]
			],
		];

		aioseo()->importExport->rankMath->helpers->mapOldToNew( $settings, $this->options['local_address'] );
	}

	/**
	 * Migrates the Local Business opening hour settings.
	 *
	 * @since   4.0.0
	 * @version 1.3.0 Moved from Pro.
	 *
	 * @return void
	 */
	private function importOpeningHourSettings() {
		if ( ! empty( $this->options['opening_hours_format'] ) ) {
			aioseo()->options->localBusiness->openingHours->use24hFormat = 'off' === $this->options['opening_hours_format'];
		}

		if ( empty( $this->options['opening_hours'] ) ) {
			return;
		}

		$days = aioseo()->options->localBusiness->openingHours->days->all();

		foreach ( $days as $name => $values ) {
			aioseo()->options->localBusiness->openingHours->days->$name->closed = true;

			$importDay = array_filter( $this->options['opening_hours'], function ( $day ) use ( $name ) {
				return strtolower( $day['day'] ) === $name && ! empty( $day['time'] );
			} );

			$importDay = current( $importDay );
			if ( empty( $importDay ) ) {
				continue;
			}

			aioseo()->options->localBusiness->openingHours->days->$name->closed = false;

			preg_match( '#^(\d{1,2}:\d{2})-(\d{1,2}:\d{2})$#', (string) $importDay['time'], $matches );
			if ( ! empty( $matches[1] ) ) {
				aioseo()->options->localBusiness->openingHours->days->$name->openTime = str_pad( $matches[1], 5, '0', STR_PAD_LEFT );
			}

			if ( ! empty( $matches[2] ) ) {
				aioseo()->options->localBusiness->openingHours->days->$name->closeTime = str_pad( $matches[2], 5, '0', STR_PAD_LEFT );
			}

			if ( '00:00' === $matches[1] && '23:59' === $matches[2] ) {
				aioseo()->options->localBusiness->openingHours->days->$name->open24h = true;
			}
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
		if ( ! empty( $this->options['maps_api_key'] ) ) {
			aioseo()->options->localBusiness->maps->apiKey = $this->options['maps_api_key'];
			aioseo()->options->localBusiness->maps->apiKeyValid = true;
		}

		if ( ! empty( $this->options['geo'] ) ) {
			list( $lat, $lng ) = explode( ',', $this->options['geo'] );
			aioseo()->options->localBusiness->maps->mapOptions->center->lat = $lat;
			aioseo()->options->localBusiness->maps->mapOptions->center->lng = $lng;
		}
	}

	/**
	 * Imports the phone number.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	private function importPhoneNumber() {
		if ( ! is_array( $this->options['phone_numbers'] ) || empty( $this->options['phone_numbers'] ) ) {
			return;
		}

		$phoneNumber = current( $this->options['phone_numbers'] );
		if ( ! empty( $phoneNumber['number'] ) ) {
			$this->importLocalBusinessPhoneNumber( $phoneNumber['number'] );
		}
	}
}