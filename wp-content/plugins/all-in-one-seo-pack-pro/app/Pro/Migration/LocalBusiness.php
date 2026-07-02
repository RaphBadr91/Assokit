<?php
namespace AIOSEO\Plugin\Pro\Migration;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Models;

// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

/**
 * Migrates the Local Business SEO settings from V3.
 *
 * @since 4.0.0
 */
class LocalBusiness {
	/**
	 * The old V3 options.
	 *
	 * @since 4.0.0
	 *
	 * @var array
	 */
	protected $oldOptions = [];

	/**
	 * Class constructor.
	 *
	 * @since 4.0.0
	 */
	public function __construct() {
		$this->oldOptions = aioseo()->migration->oldOptions;

		if ( empty( $this->oldOptions['modules']['aiosp_schema_local_business_options'] ) ) {
			return;
		}

		$this->migrateLocalBusinessAddress();
		$this->migrateLocalBusinessPhoneNumber();
		$this->migrateLocalBusinessOpeningHours();
		$this->migrateLocalBusinessPriceRange();

		$settings = [
			'aiosp_schema_local_business_aioseo_business_name'  => [ 'type' => 'string', 'newOption' => [ 'localBusiness', 'locations', 'business', 'name' ] ],
			'aiosp_schema_local_business_aioseo_business_type'  => [ 'type' => 'string', 'newOption' => [ 'localBusiness', 'locations', 'business', 'businessType' ] ],
			'aiosp_schema_local_business_aioseo_business_image' => [ 'type' => 'string', 'newOption' => [ 'localBusiness', 'locations', 'business', 'image' ] ],
		];

		aioseo()->migration->helpers->mapOldToNew( $settings, $this->oldOptions['modules']['aiosp_schema_local_business_options'] );
	}

	/**
	 * Migrates the Local Business address.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	private function migrateLocalBusinessAddress() {
		if ( empty( $this->oldOptions['modules']['aiosp_schema_local_business_options']['aiosp_schema_local_business_aioseo_postal_address'] ) ) {
			return;
		}

		$settings = [
			'street_address'   => [ 'type' => 'string', 'newOption' => [ 'localBusiness', 'locations', 'business', 'address', 'streetLine1' ] ],
			'address_locality' => [ 'type' => 'string', 'newOption' => [ 'localBusiness', 'locations', 'business', 'address', 'city' ] ],
			'address_region'   => [ 'type' => 'string', 'newOption' => [ 'localBusiness', 'locations', 'business', 'address', 'state' ] ],
			'postal_code'      => [ 'type' => 'string', 'newOption' => [ 'localBusiness', 'locations', 'business', 'address', 'zipCode' ] ],
		];

		aioseo()->migration->helpers->mapOldToNew( $settings, $this->oldOptions['modules']['aiosp_schema_local_business_options']['aiosp_schema_local_business_aioseo_postal_address'] );

		if ( ! empty( $this->oldOptions['modules']['aiosp_schema_local_business_options']['aiosp_schema_local_business_aioseo_postal_address']['address_country'] ) ) {
			$country = $this->oldOptions['modules']['aiosp_schema_local_business_options']['aiosp_schema_local_business_aioseo_postal_address']['address_country'];

			foreach ( self::getSupportedCountries() as $value => $label ) {
				if ( strtoupper( $country ) === strtoupper( $label ) || strtoupper( $country ) === $value ) {
					aioseo()->options->localBusiness->locations->business->address->country = $value;

					return;
				}
			}

			$notification = Models\Notification::getNotificationByName( 'v3-migration-local-business-country' );
			if ( $notification->notification_name ) {
				return;
			}

			Models\Notification::addNotification( [
				'slug'              => uniqid(),
				'notification_name' => 'v3-migration-local-business-country',
				'title'             => __( 'Re-Enter Country in Local Business', 'aioseo-pro' ),
				'content'           => sprintf(
					// Translators: 1 - The country.
					__( 'For technical reasons, we were unable to migrate the country you entered for your Local Business schema markup. Please enter it (%1$s) again by using the dropdown menu.', 'aioseo-pro' ), // phpcs:ignore Generic.Files.LineLength.MaxExceeded
					"<strong>$country</strong>"
				),
				'type'              => 'warning',
				'level'             => [ 'all' ],
				'button1_label'     => __( 'Fix Now', 'aioseo-pro' ),
				'button1_action'    => 'http://route#aioseo-local-seo&aioseo-scroll=info-business-address-row&aioseo-highlight=info-business-address-row:locations',
				'button2_label'     => __( 'Remind Me Later', 'aioseo-pro' ),
				'button2_action'    => 'http://action#notification/v3-migration-local-business-country-reminder',
				'start'             => gmdate( 'Y-m-d H:i:s' )
			] );
		}
	}

	/**
	 * Migrates the Local Business phone number.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	private function migrateLocalBusinessPhoneNumber() {
		if ( empty( $this->oldOptions['modules']['aiosp_schema_local_business_options']['aiosp_schema_local_business_aioseo_telephone'] ) ) {
			return;
		}

		$phoneNumber = $this->oldOptions['modules']['aiosp_schema_local_business_options']['aiosp_schema_local_business_aioseo_telephone'];
		if ( ! preg_match( '#\+\d+#', (string) $phoneNumber ) ) {
			$notification = Models\Notification::getNotificationByName( 'v3-migration-local-business-number' );
			if ( $notification->notification_name ) {
				return;
			}

			Models\Notification::addNotification( [
				'slug'              => uniqid(),
				'notification_name' => 'v3-migration-local-business-number',
				'title'             => __( 'Invalid Phone Number for Local SEO', 'aioseo-pro' ),
				'content'           => sprintf(
					// Translators: 1 - The phone number.
					__( 'The phone number that you previously entered for your Local Business schema markup is invalid. As it needs to be internationally formatted, please enter it (%1$s) again with the country code, e.g. +1 (555) 555-1234.', 'aioseo-pro' ), // phpcs:ignore Generic.Files.LineLength.MaxExceeded
					"<strong>$phoneNumber</strong>"
				),
				'type'              => 'warning',
				'level'             => [ 'all' ],
				'button1_label'     => __( 'Fix Now', 'aioseo-pro' ),
				'button1_action'    => 'http://route#aioseo-local-seo&aioseo-scroll=info-business-contact-row&aioseo-highlight=info-business-contact-row:global-settings',
				'button2_label'     => __( 'Remind Me Later', 'aioseo-pro' ),
				'button2_action'    => 'http://action#notification/v3-migration-local-business-number-reminder',
				'start'             => gmdate( 'Y-m-d H:i:s' )
			] );

			return;
		}
		aioseo()->options->localBusiness->locations->business->contact->phone = $phoneNumber;
	}

	/**
	 * Migrates the Local Business opening hours.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	private function migrateLocalBusinessOpeningHours() {
		if ( empty( $this->oldOptions['modules']['aiosp_schema_local_business_options']['aiosp_schema_local_business_aioseo_time_0_opening_days'] ) ) {
			return;
		}

		$openedDays = array_map( function( $day ) {
			return lcfirst( $day );
		}, $this->oldOptions['modules']['aiosp_schema_local_business_options']['aiosp_schema_local_business_aioseo_time_0_opening_days'] );

		$days = aioseo()->options->localBusiness->openingHours->days->all();
		foreach ( $days as $day => $values ) {
			if ( ! in_array( $day, $openedDays, true ) ) {
				aioseo()->options->localBusiness->openingHours->days->$day->closed = true;
			}

			aioseo()->options->localBusiness->openingHours->days->$day->openTime  = $this->oldOptions['modules']['aiosp_schema_local_business_options']['aiosp_schema_local_business_aioseo_time_0_opens']; // phpcs:ignore Generic.Files.LineLength.MaxExceeded
			aioseo()->options->localBusiness->openingHours->days->$day->closeTime = $this->oldOptions['modules']['aiosp_schema_local_business_options']['aiosp_schema_local_business_aioseo_time_0_closes']; // phpcs:ignore Generic.Files.LineLength.MaxExceeded
		}
	}

	/**
	 * Migrates the Local Business price range.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	private function migrateLocalBusinessPriceRange() {
		if ( empty( $this->oldOptions['modules']['aiosp_schema_local_business_options']['aiosp_schema_local_business_aioseo_price_range'] ) ) {
			return;
		}

		$value = intval( $this->oldOptions['modules']['aiosp_schema_local_business_options']['aiosp_schema_local_business_aioseo_price_range'] );
		if ( ! $value ) {
			return;
		}

		$priceRange = '';
		for ( $i = 1; $i <= $value; $i++ ) {
			$priceRange .= '$';
		}
		aioseo()->options->localBusiness->locations->business->payment->priceRange = $priceRange;
	}

	/**
	 * Returns the countries from our dropdown in V4.
	 *
	 * @since 4.0.0
	 *
	 * @return array The list of supported countries.
	 */
	public static function getSupportedCountries() {
		return [
			'AD' => 'Andorra',
			'AE' => 'United Arab Emirates',
			'AF' => 'Afghanistan',
			'AG' => 'Antigua and Barbuda',
			'AI' => 'Anguilla',
			'AL' => 'Albania',
			'AM' => 'Armenia',
			'AO' => 'Angola',
			'AQ' => 'Antarctica',
			'AR' => 'Argentina',
			'AS' => 'American Samoa',
			'AT' => 'Austria',
			'AU' => 'Australia',
			'AW' => 'Aruba',
			'AX' => 'Åland Islands',
			'AZ' => 'Azerbaijan',
			'BA' => 'Bosnia and Herzegovina',
			'BB' => 'Barbados',
			'BD' => 'Bangladesh',
			'BE' => 'Belgium',
			'BF' => 'Burkina Faso',
			'BG' => 'Bulgaria',
			'BH' => 'Bahrain',
			'BI' => 'Burundi',
			'BJ' => 'Benin',
			'BL' => 'Saint Barthélemy',
			'BM' => 'Bermuda',
			'BN' => 'Brunei Darussalam',
			'BO' => 'Bolivia',
			'BQ' => 'Bonaire',
			'BR' => 'Brazil',
			'BS' => 'Bahamas',
			'BT' => 'Bhutan',
			'BV' => 'Bouvet Island',
			'BW' => 'Botswana',
			'BY' => 'Belarus',
			'BZ' => 'Belize',
			'CA' => 'Canada',
			'CC' => 'Cocos (Keeling) Islands',
			'CD' => 'Democratic Republic of the Congo',
			'CF' => 'Central African Republic',
			'CG' => 'Congo',
			'CH' => 'Switzerland',
			'CI' => 'Côte d\'Ivoire',
			'CK' => 'Cook Islands',
			'CL' => 'Chile',
			'CM' => 'Cameroon',
			'CN' => 'China',
			'CO' => 'Colombia',
			'CR' => 'Costa Rica',
			'CU' => 'Cuba',
			'CV' => 'Cabo Verde',
			'CW' => 'Curaçao',
			'CX' => 'Christmas Island',
			'CY' => 'Cyprus',
			'CZ' => 'Czechia',
			'DE' => 'Germany',
			'DJ' => 'Djibouti',
			'DK' => 'Denmark',
			'DM' => 'Dominica',
			'DO' => 'Dominican Republic',
			'DZ' => 'Algeria',
			'EC' => 'Ecuador',
			'EE' => 'Estonia',
			'EG' => 'Egypt',
			'EH' => 'Western Sahara',
			'ER' => 'Eritrea',
			'ES' => 'Spain',
			'ET' => 'Ethiopia',
			'FI' => 'Finland',
			'FJ' => 'Fiji',
			'FK' => 'Falkland Islands',
			'FM' => 'Micronesia',
			'FO' => 'Faroe Islands',
			'FR' => 'France',
			'GA' => 'Gabon',
			'GB' => 'United Kingdom of Great Britain and Northern Ireland',
			'GD' => 'Grenada',
			'GE' => 'Georgia',
			'GF' => 'French Guiana',
			'GG' => 'Guernsey',
			'GH' => 'Ghana',
			'GI' => 'Gibraltar',
			'GL' => 'Greenland',
			'GM' => 'Gambia',
			'GN' => 'Guinea',
			'GP' => 'Guadeloupe',
			'GQ' => 'Equatorial Guinea',
			'GR' => 'Greece',
			'GS' => 'South Georgia and the South Sandwich Islands',
			'GT' => 'Guatemala',
			'GU' => 'Guam',
			'GW' => 'Guinea-Bissau',
			'GY' => 'Guyana',
			'HK' => 'Hong Kong',
			'HM' => 'Heard Island and McDonald Islands',
			'HN' => 'Honduras',
			'HR' => 'Croatia',
			'HT' => 'Haiti',
			'HU' => 'Hungary',
			'ID' => 'Indonesia',
			'IE' => 'Ireland',
			'IL' => 'Israel',
			'IM' => 'Isle of Man',
			'IN' => 'India',
			'IO' => 'British Indian Ocean Territory',
			'IQ' => 'Iraq',
			'IR' => 'Iran',
			'IS' => 'Iceland',
			'IT' => 'Italy',
			'JE' => 'Jersey',
			'JM' => 'Jamaica',
			'JO' => 'Jordan',
			'JP' => 'Japan',
			'KE' => 'Kenya',
			'KG' => 'Kyrgyzstan',
			'KH' => 'Cambodia',
			'KI' => 'Kiribati',
			'KM' => 'Comoros',
			'KN' => 'Saint Kitts and Nevis',
			'KR' => 'South Korea',
			'KW' => 'Kuwait',
			'KY' => 'Cayman Islands',
			'KZ' => 'Kazakhstan',
			'LA' => 'Lao People\'s Democratic Republic',
			'LB' => 'Lebanon',
			'LC' => 'Saint Lucia',
			'LI' => 'Liechtenstein',
			'LK' => 'Sri Lanka',
			'LR' => 'Liberia',
			'LS' => 'Lesotho',
			'LT' => 'Lithuania',
			'LU' => 'Luxembourg',
			'LV' => 'Latvia',
			'LY' => 'Libya',
			'MA' => 'Morocco',
			'MC' => 'Monaco',
			'MD' => 'Moldova',
			'ME' => 'Montenegro',
			'MF' => 'Saint Martin',
			'MG' => 'Madagascar',
			'MH' => 'Marshall Islands',
			'MK' => 'Republic of North Macedonia',
			'ML' => 'Mali',
			'MM' => 'Myanmar',
			'MN' => 'Mongolia',
			'MO' => 'Macao',
			'MP' => 'Northern Mariana Islands',
			'MQ' => 'Martinique',
			'MR' => 'Mauritania',
			'MS' => 'Montserrat',
			'MT' => 'Malta',
			'MU' => 'Mauritius',
			'MV' => 'Maldives',
			'MW' => 'Malawi',
			'MX' => 'Mexico',
			'MY' => 'Malaysia',
			'MZ' => 'Mozambique',
			'NA' => 'Namibia',
			'NC' => 'New Caledonia',
			'NE' => 'Niger',
			'NF' => 'Norfolk Island',
			'NG' => 'Nigeria',
			'NI' => 'Nicaragua',
			'NL' => 'Netherlands',
			'NO' => 'Norway',
			'NP' => 'Nepal',
			'NR' => 'Nauru',
			'NU' => 'Niue',
			'NZ' => 'New Zealand',
			'OM' => 'Oman',
			'PA' => 'Panama',
			'PE' => 'Peru',
			'PF' => 'French Polynesia',
			'PG' => 'Papua New Guinea',
			'PH' => 'Philippines',
			'PK' => 'Pakistan',
			'PL' => 'Poland',
			'PM' => 'Saint Pierre and Miquelon',
			'PN' => 'Pitcairn',
			'PR' => 'Puerto Rico',
			'PS' => 'Palestine, State of',
			'PT' => 'Portugal',
			'PW' => 'Palau',
			'PY' => 'Paraguay',
			'QA' => 'Qatar',
			'RE' => 'Réunion',
			'RO' => 'Romania',
			'RS' => 'Serbia',
			'RU' => 'Russian Federation',
			'RW' => 'Rwanda',
			'SA' => 'Saudi Arabia',
			'SB' => 'Solomon Islands',
			'SC' => 'Seychelles',
			'SD' => 'Sudan',
			'SE' => 'Sweden',
			'SG' => 'Singapore',
			'SH' => 'Saint Helena, Ascension and Tristan da Cunha',
			'SI' => 'Slovenia',
			'SJ' => 'Svalbard and Jan Mayen',
			'SK' => 'Slovakia',
			'SL' => 'Sierra Leone',
			'SM' => 'San Marino',
			'SN' => 'Senegal',
			'SO' => 'Somalia',
			'SR' => 'Suriname',
			'SS' => 'South Sudan',
			'ST' => 'Sao Tome and Principe',
			'SV' => 'El Salvador',
			'SX' => 'Sint Maarten',
			'SY' => 'Syrian Arab Republic',
			'SZ' => 'Eswatini',
			'TC' => 'Turks and Caicos Islands',
			'TD' => 'Chad',
			'TF' => 'French Southern Territories',
			'TG' => 'Togo',
			'TH' => 'Thailand',
			'TJ' => 'Tajikistan',
			'TK' => 'Tokelau',
			'TL' => 'Timor-Leste',
			'TM' => 'Turkmenistan',
			'TN' => 'Tunisia',
			'TO' => 'Tonga',
			'TR' => 'Turkey',
			'TT' => 'Trinidad and Tobago',
			'TV' => 'Tuvalu',
			'TW' => 'Taiwan',
			'TZ' => 'Tanzania, United Republic of',
			'UA' => 'Ukraine',
			'UG' => 'Uganda',
			'UM' => 'United States Minor Outlying Islands',
			'US' => 'United States of America',
			'UY' => 'Uruguay',
			'UZ' => 'Uzbekistan',
			'VA' => 'Holy See',
			'VC' => 'Saint Vincent and the Grenadines',
			'VE' => 'Venezuela',
			'VG' => 'Virgin Islands (British)',
			'VI' => 'Virgin Islands (U.S.)',
			'VN' => 'Vietnam',
			'VU' => 'Vanuatu',
			'WF' => 'Wallis and Futuna',
			'WS' => 'Samoa',
			'YE' => 'Yemen',
			'YT' => 'Mayotte',
			'ZA' => 'South Africa',
			'ZM' => 'Zambia',
			'ZW' => 'Zimbabwe'
		];
	}
}