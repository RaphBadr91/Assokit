<?php
namespace AIOSEO\Plugin\Addon\LocalBusiness\Models;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Post
 *
 * @since 1.1.3
 */
class Post {
	/**
	 * Returns a JSON object with default local seo options.
	 *
	 * @since 1.1.3
	 *
	 * @param  string $existingOptions The existing options in JSON.
	 * @return string                  The existing options with defaults added in JSON.
	 */
	public static function parseLocalSeoOptions( $existingOptions = '' ) {
		$defaults = [
			'locations'    => [
				'business' => [
					'name'         => '',
					'businessType' => '',
					'image'        => '',
					'areaServed'   => '',
					'urls'         => [
						'website'     => '',
						'aboutPage'   => '',
						'contactPage' => ''
					],
					'address'      => [
						'streetLine1'   => '',
						'streetLine2'   => '',
						'zipCode'       => '',
						'city'          => '',
						'state'         => '',
						'country'       => '',
						'addressFormat' => "#streetLineOne\n#streetLineTwo\n#city, #state #zipCode"
					],
					'contact'      => [
						'email'          => '',
						'phone'          => '',
						'phoneFormatted' => '',
						'fax'            => '',
						'faxFormatted'   => ''
					],
					'ids'          => [
						'vat'               => '',
						'tax'               => '',
						'chamberOfCommerce' => ''
					],
					'payment'      => [
						'priceRange'         => '',
						'currenciesAccepted' => '',
						'methods'            => ''
					],
				],
			],
			'openingHours' => [
				'useDefaults'  => true,
				'show'         => true,
				'alwaysOpen'   => false,
				'use24hFormat' => false,
				'timezone'     => '',
				'labels'       => [
					'closed'     => '',
					'alwaysOpen' => ''
				],
				'days'         => [
					'monday'    => [
						'open24h'   => false,
						'closed'    => false,
						'openTime'  => '09:00',
						'closeTime' => '17:00'
					],
					'tuesday'   => [
						'open24h'   => false,
						'closed'    => false,
						'openTime'  => '09:00',
						'closeTime' => '17:00'
					],
					'wednesday' => [
						'open24h'   => false,
						'closed'    => false,
						'openTime'  => '09:00',
						'closeTime' => '17:00'
					],
					'thursday'  => [
						'open24h'   => false,
						'closed'    => false,
						'openTime'  => '09:00',
						'closeTime' => '17:00'
					],
					'friday'    => [
						'open24h'   => false,
						'closed'    => false,
						'openTime'  => '09:00',
						'closeTime' => '17:00'
					],
					'saturday'  => [
						'open24h'   => false,
						'closed'    => false,
						'openTime'  => '09:00',
						'closeTime' => '17:00'
					],
					'sunday'    => [
						'open24h'   => false,
						'closed'    => false,
						'openTime'  => '09:00',
						'closeTime' => '17:00'
					]
				]
			],
			'maps'         => [
				'mapOptions'   => [
					'center'    => [
						'lat' => 47.6205063, // Space Needle, Seattle - WA
						'lng' => - 122.3492774
					],
					'zoom'      => 16,
					'mapTypeId' => 'roadmap'
				],
				'customMarker' => '',
				'placeId'      => ''
			]
		];

		if ( empty( $existingOptions ) ) {
			return $defaults;
		}

		if ( ! aioseo()->helpers->isJsonString( $existingOptions ) ) {
			$existingOptions = wp_json_encode( $existingOptions );
		}

		// Decode as an array to merge with the defaults.
		$existingOptions = json_decode( $existingOptions, true );

		return array_replace_recursive( $defaults, $existingOptions );
	}
}