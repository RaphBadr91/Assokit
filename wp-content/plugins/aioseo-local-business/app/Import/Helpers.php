<?php
namespace AIOSEO\Plugin\Addon\LocalBusiness\Import;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Migrates the Local Business settings from other plugins.
 *
 * @since   4.0.0
 * @version 1.3.0 Moved from Pro.
 */
class Helpers extends \AIOSEO\Plugin\Common\ImportExport\Helpers {
	/**
	 * The error notifications class.
	 *
	 * @since 1.3.0
	 *
	 * @var Notifications
	 */
	protected $notifications = null;

	/**
	 * Constructor.
	 *
	 * @since 1.3.0
	 */
	public function __construct() {
		$this->notifications = new Notifications();
	}

	/**
	 * Migrates the Local Business type.
	 *
	 * @since   4.0.0
	 * @version 1.3.0 Moved from Pro.
	 *
	 * @param  string $businessType The business type.
	 * @return bool                Whether the business type was imported.
	 */
	protected function importLocalBusinessType( $businessType ) {
		if ( ! $this->validateLocalBusinessType( $businessType ) ) {
			return false;
		}

		aioseo()->options->localBusiness->locations->business->businessType = $businessType;

		return true;
	}

	/**
	 * Validates the Local Business type.
	 *
	 * @since 1.3.0
	 *
	 * @param  string $businessType The business type.
	 * @return bool                 Whether the business type is valid.
	 */
	protected function validateLocalBusinessType( $businessType ) {
		if ( ! $businessType ) {
			return false;
		}

		if ( ! in_array( $businessType, $this->getLocalBusinessTypes(), true ) ) {
			$this->notifications->businessTypeNotSupported( $businessType );

			return false;
		}

		return true;
	}

	/**
	 * Migrates the Local Business country.
	 *
	 * @since   4.0.0
	 * @version 1.3.0 Moved from Pro.
	 *
	 * @param  string $country The country.
	 * @return void
	 */
	protected function importLocalBusinessCountry( $country ) {
		if ( empty( $country ) ) {
			return;
		}

		$found   = false;
		$country = strtoupper( $country );
		foreach ( \AIOSEO\Plugin\Pro\Migration\LocalBusiness::getSupportedCountries() as $countryCode => $countryName ) {
			if ( $countryCode === $country || strtoupper( $countryName ) === $country ) {
				$found   = true;
				$country = $countryCode;
				break;
			}
		}

		if ( ! $found ) {
			$this->notifications->countryNotSupported( $country );

			return;
		}

		aioseo()->options->localBusiness->locations->business->address->country = $country;
	}

	/**
	 * Imports the Local Business phone number.
	 *
	 * @since   4.0.0
	 * @version 1.3.0 Moved from Pro.
	 *
	 * @param  string $phoneNumber The phone number.
	 * @return void
	 */
	protected function importLocalBusinessPhoneNumber( $phoneNumber ) {
		if ( empty( $phoneNumber ) ) {
			return;
		}

		// Format the phone number.
		$phoneNumber = preg_replace( '/[^0-9+]/', '', (string) $phoneNumber );

		if ( ! preg_match( '#^\+\d+#', (string) $phoneNumber ) ) {
			$this->notifications->phoneNumberNotSupported( $phoneNumber );

			return;
		}

		aioseo()->options->localBusiness->locations->business->contact->phone = $phoneNumber;
	}

	/**
	 * Imports the Local Business fax number.
	 *
	 * @since   4.0.0
	 * @version 1.3.0 Moved from Pro.
	 *
	 * @return void
	 */
	protected function importLocalBusinessFaxNumber( $faxNumber ) {
		if ( empty( $faxNumber ) ) {
			return;
		}

		// Format the fax number.
		$faxNumber = preg_replace( '/[^0-9+]/', '', (string) $faxNumber );

		if ( ! preg_match( '#^\+\d+#', (string) $faxNumber ) ) {
			$this->notifications->faxNumberNotSupported( $faxNumber );

			return;
		}

		aioseo()->options->localBusiness->locations->business->contact->fax = $faxNumber;
	}

	/**
	 * Imports the Local Business accepted currencies.
	 *
	 * @since   4.0.0
	 * @version 1.3.0 Moved from Pro.
	 *
	 * @param  array $currencies The currencies.
	 * @return void
	 */
	protected function importCurrencies( $currencies ) {
		$currencies = $this->formatCurrencies( $currencies );

		if ( empty( $currencies ) ) {
			return;
		}

		aioseo()->options->localBusiness->locations->business->payment->currenciesAccepted = wp_json_encode( $currencies['supported'] );

		if ( count( $currencies['unsupported'] ) ) {
			$this->notifications->currenciesNotSupported( $currencies['unsupported'] );
		}
	}

	/**
	 * Formats Yoast currencies to AIOSEO currencies.
	 *
	 * @since 1.3.0
	 *
	 * @param  array $currencies The currencies array.
	 * @return array             The formatted currencies.
	 */
	protected function formatCurrencies( $currencies ) {
		$supported = [];
		if ( empty( $currencies ) ) {
			return $supported;
		}

		$dropdownCurrencies = json_decode( $this->getLocalBusinessCurrencies(), true );
		$unsupported        = [];
		foreach ( $currencies as $currency ) {
			$currency = strtoupper( trim( $currency ) );
			$found    = false;
			foreach ( $dropdownCurrencies as $dropdownCurrency ) {
				if ( $currency === $dropdownCurrency['value'] ) {
					$supported[] = $dropdownCurrency;
					$found       = true;
					break;
				}
			}

			if ( ! $found ) {
				$unsupported[] = $currency;
			}
		}

		return [
			'supported'   => $supported,
			'unsupported' => $unsupported
		];
	}

	/**
	 * Returns our supported Local Business types.
	 *
	 * @since   4.0.0
	 * @version 1.3.0 Moved from Pro.
	 *
	 * @return array The list of supported business types.
	 */
	protected function getLocalBusinessTypes() {
		return [
			'LocalBusiness',
			'AnimalShelter',
			'ArchiveOrganization',
			'AutomotiveBusiness',
			'ChildCare',
			'Dentist',
			'DryCleaningOrLaundry',
			'EmergencyService',
			'EmploymentAgency',
			'EntertainmentBusiness',
			'FinancialService',
			'FoodEstablishment',
			'GovernmentOffice',
			'HealthAndBeautyBusiness',
			'HomeAndConstructionBusiness',
			'InternetCafe',
			'LegalService',
			'Library',
			'LodgingBusiness',
			'MedicalBusiness',
			'RadioStation',
			'RealEstateAgent',
			'RecyclingCenter',
			'SelfStorage',
			'ShoppingCenter',
			'SportsActivityLocation',
			'Store',
			'TelevisionStation',
			'TouristInformationCenter',
			'TravelAgency'
		];
	}

	/**
	 * Returns our supported Local Business currencies.
	 *
	 * @since   4.0.0
	 * @version 1.3.0 Moved from Pro.
	 *
	 * @return string The list of supported currencies in JSON.
	 */
	protected function getLocalBusinessCurrencies() {
		return '[{ "symbol": "$", "label": "US Dollar", "value": "USD" },
		{ "symbol": "CA$", "label": "Canadian Dollar", "value": "CAD" },
		{ "symbol": "€", "label": "Euro", "value": "EUR" },
		{ "symbol": "Ƀ", "label": "Bitcoin", "value": "BTC" },
		{ "symbol": "AED", "label": "United Arab Emirates Dirham", "value": "AED" },
		{ "symbol": "Af", "label": "Afghan Afghani", "value": "AFN" },
		{ "symbol": "ALL", "label": "Albanian Lek", "value": "ALL" },
		{ "symbol": "AMD", "label": "Armenian Dram", "value": "AMD" },
		{ "symbol": "AR$", "label": "Argentine Peso", "value": "ARS" },
		{ "symbol": "AU$", "label": "Australian Dollar", "value": "AUD" },
		{ "symbol": "man.", "label": "Azerbaijani Manat", "value": "AZN" },
		{ "symbol": "KM", "label": "Bosnia-Herzegovina Convertible Mark", "value": "BAM" },
		{ "symbol": "Tk", "label": "Bangladeshi Taka", "value": "BDT" },
		{ "symbol": "BGN", "label": "Bulgarian Lev", "value": "BGN" },
		{ "symbol": "BD", "label": "Bahraini Dinar", "value": "BHD" },
		{ "symbol": "FBu", "label": "Burundian Franc", "value": "BIF" },
		{ "symbol": "BN$", "label": "Brunei Dollar", "value": "BND" },
		{ "symbol": "Bs", "label": "Bolivian Boliviano", "value": "BOB" },
		{ "symbol": "R$", "label": "Brazilian Real", "value": "BRL" },
		{ "symbol": "BWP", "label": "Botswanan Pula", "value": "BWP" },
		{ "symbol": "Br", "label": "Belarusian Ruble", "value": "BYN" },
		{ "symbol": "BZ$", "label": "Belize Dollar", "value": "BZD" },
		{ "symbol": "CDF", "label": "Congolese Franc", "value": "CDF" },
		{ "symbol": "CHF", "label": "Swiss Franc", "value": "CHF" },
		{ "symbol": "CL$", "label": "Chilean Peso", "value": "CLP" },
		{ "symbol": "CN¥", "label": "Chinese Yuan", "value": "CNY" },
		{ "symbol": "CO$", "label": "Colombian Peso", "value": "COP" },
		{ "symbol": "₡", "label": "Costa Rican Colón", "value": "CRC" },
		{ "symbol": "CV$", "label": "Cape Verdean Escudo", "value": "CVE" },
		{ "symbol": "Kč", "label": "Czech Republic Koruna", "value": "CZK" },
		{ "symbol": "Fdj", "label": "Djiboutian Franc", "value": "DJF" },
		{ "symbol": "Dkr", "label": "Danish Krone", "value": "DKK" },
		{ "symbol": "RD$", "label": "Dominican Peso", "value": "DOP" },
		{ "symbol": "DA", "label": "Algerian Dinar", "value": "DZD" },
		{ "symbol": "Ekr", "label": "Estonian Kroon", "value": "EEK" },
		{ "symbol": "EGP", "label": "Egyptian Pound", "value": "EGP" },
		{ "symbol": "Nfk", "label": "Eritrean Nakfa", "value": "ERN" },
		{ "symbol": "Br", "label": "Ethiopian Birr", "value": "ETB" },
		{ "symbol": "£", "label": "British Pound Sterling", "value": "GBP" },
		{ "symbol": "GEL", "label": "Georgian Lari", "value": "GEL" },
		{ "symbol": "GH₵", "label": "Ghanaian Cedi", "value": "GHS" },
		{ "symbol": "FG", "label": "Guinean Franc", "value": "GNF" },
		{ "symbol": "GTQ", "label": "Guatemalan Quetzal", "value": "GTQ" },
		{ "symbol": "HK$", "label": "Hong Kong Dollar", "value": "HKD" },
		{ "symbol": "HNL", "label": "Honduran Lempira", "value": "HNL" },
		{ "symbol": "kn", "label": "Croatian Kuna", "value": "HRK" },
		{ "symbol": "Ft", "label": "Hungarian Forint", "value": "HUF" },
		{ "symbol": "Rp", "label": "Indonesian Rupiah", "value": "IDR" },
		{ "symbol": "₪", "label": "Israeli New Sheqel", "value": "ILS" },
		{ "symbol": "Rs", "label": "Indian Rupee", "value": "INR" },
		{ "symbol": "IQD", "label": "Iraqi Dinar", "value": "IQD" },
		{ "symbol": "IRR", "label": "Iranian Rial", "value": "IRR" },
		{ "symbol": "Ikr", "label": "Icelandic Króna", "value": "ISK" },
		{ "symbol": "J$", "label": "Jamaican Dollar", "value": "JMD" },
		{ "symbol": "JD", "label": "Jordanian Dinar", "value": "JOD" },
		{ "symbol": "¥", "label": "Japanese Yen", "value": "JPY" },
		{ "symbol": "Ksh", "label": "Kenyan Shilling", "value": "KES" },
		{ "symbol": "KHR", "label": "Cambodian Riel", "value": "KHR" },
		{ "symbol": "CF", "label": "Comorian Franc", "value": "KMF" },
		{ "symbol": "₩", "label": "South Korean Won", "value": "KRW" },
		{ "symbol": "KD", "label": "Kuwaiti Dinar", "value": "KWD" },
		{ "symbol": "KZT", "label": "Kazakhstani Tenge", "value": "KZT" },
		{ "symbol": "LB£", "label": "Lebanese Pound", "value": "LBP" },
		{ "symbol": "SLRs", "label": "Sri Lankan Rupee", "value": "LKR" },
		{ "symbol": "Lt", "label": "Lithuanian Litas", "value": "LTL" },
		{ "symbol": "Ls", "label": "Latvian Lats", "value": "LVL" },
		{ "symbol": "LD", "label": "Libyan Dinar", "value": "LYD" },
		{ "symbol": "MAD", "label": "Moroccan Dirham", "value": "MAD" },
		{ "symbol": "MDL", "label": "Moldovan Leu", "value": "MDL" },
		{ "symbol": "MGA", "label": "Malagasy Ariary", "value": "MGA" },
		{ "symbol": "MKD", "label": "Macedonian Denar", "value": "MKD" },
		{ "symbol": "MMK", "label": "Myanma Kyat", "value": "MMK" },
		{ "symbol": "MOP$", "label": "Macanese Pataca", "value": "MOP" },
		{ "symbol": "MURs", "label": "Mauritian Rupee", "value": "MUR" },
		{ "symbol": "MX$", "label": "Mexican Peso", "value": "MXN" },
		{ "symbol": "RM", "label": "Malaysian Ringgit", "value": "MYR" },
		{ "symbol": "MTn", "label": "Mozambican Metical", "value": "MZN" },
		{ "symbol": "N$", "label": "Namibian Dollar", "value": "NAD" },
		{ "symbol": "₦", "label": "Nigerian Naira", "value": "NGN" },
		{ "symbol": "C$", "label": "Nicaraguan Córdoba", "value": "NIO" },
		{ "symbol": "Nkr", "label": "Norwegian Krone", "value": "NOK" },
		{ "symbol": "NPRs", "label": "Nepalese Rupee", "value": "NPR" },
		{ "symbol": "NZ$", "label": "New Zealand Dollar", "value": "NZD" },
		{ "symbol": "OMR", "label": "Omani Rial", "value": "OMR" },
		{ "symbol": "B/.", "label": "Panamanian Balboa", "value": "PAB" },
		{ "symbol": "S/.", "label": "Peruvian Nuevo Sol", "value": "PEN" },
		{ "symbol": "₱", "label": "Philippine Peso", "value": "PHP" },
		{ "symbol": "PKRs", "label": "Pakistani Rupee", "value": "PKR" },
		{ "symbol": "zł", "label": "Polish Zloty", "value": "PLN" },
		{ "symbol": "₲", "label": "Paraguayan Guarani", "value": "PYG" },
		{ "symbol": "QR", "label": "Qatari Rial", "value": "QAR" },
		{ "symbol": "RON", "label": "Romanian Leu", "value": "RON" },
		{ "symbol": "din.", "label": "Serbian Dinar", "value": "RSD" },
		{ "symbol": "RUB", "label": "Russian Ruble", "value": "RUB" },
		{ "symbol": "RWF", "label": "Rwandan Franc", "value": "RWF" },
		{ "symbol": "SR", "label": "Saudi Riyal", "value": "SAR" },
		{ "symbol": "SDG", "label": "Sudanese Pound", "value": "SDG" },
		{ "symbol": "Skr", "label": "Swedish Krona", "value": "SEK" },
		{ "symbol": "S$", "label": "Singapore Dollar", "value": "SGD" },
		{ "symbol": "Ssh", "label": "Somali Shilling", "value": "SOS" },
		{ "symbol": "SY£", "label": "Syrian Pound", "value": "SYP" },
		{ "symbol": "฿", "label": "Thai Baht", "value": "THB" },
		{ "symbol": "DT", "label": "Tunisian Dinar", "value": "TND" },
		{ "symbol": "T$", "label": "Tongan Paʻanga", "value": "TOP" },
		{ "symbol": "TL", "label": "Turkish Lira", "value": "TRY" },
		{ "symbol": "TT$", "label": "Trinidad and Tobago Dollar", "value": "TTD" },
		{ "symbol": "NT$", "label": "New Taiwan Dollar", "value": "TWD" },
		{ "symbol": "TSh", "label": "Tanzanian Shilling", "value": "TZS" },
		{ "symbol": "₴", "label": "Ukrainian Hryvnia", "value": "UAH" },
		{ "symbol": "USh", "label": "Ugandan Shilling", "value": "UGX" },
		{ "symbol": "$U", "label": "Uruguayan Peso", "value": "UYU" },
		{ "symbol": "UZS", "label": "Uzbekistan Som", "value": "UZS" },
		{ "symbol": "Bs.F.", "label": "Venezuelan Bolívar", "value": "VEF" },
		{ "symbol": "₫", "label": "Vietnamese Dong", "value": "VND" },
		{ "symbol": "FCFA", "label": "CFA Franc BEAC", "value": "XAF" },
		{ "symbol": "CFA", "label": "CFA Franc BCEAO", "value": "XOF" },
		{ "symbol": "YR", "label": "Yemeni Rial", "value": "YER" },
		{ "symbol": "R", "label": "South African Rand", "value": "ZAR" },
		{ "symbol": "ZK", "label": "Zambian Kwacha", "value": "ZMK" },
		{ "symbol": "ZWL$", "label": "Zimbabwean Dollar", "value": "ZWL" }]';
	}

	/**
	 * Prepare Price Range value.
	 *
	 * @since   4.1.3
	 * @version 1.3.0 Moved from Pro.
	 *
	 * @param  string $priceRange The price range to prepare.
	 * @return string             The prepared price range.
	 */
	protected function preparePriceRange( $priceRange ) {
		$count = strlen( trim( $priceRange ) );
		if ( 0 === $count ) {
			return '';
		}

		if ( 5 < $count ) {
			$count = 5;
		}

		return str_repeat( '$', $count );
	}

	/**
	 * Placeholder method.
	 *
	 * @since 1.3.0
	 *
	 * @param  string $value
	 * @return string
	 */
	public function macrosToSmartTags( $value ) {
		return $value;
	}

	/**
	 * Import all the terms of a custom location taxonomy to AIOSEO's taxonomy.
	 *
	 * @since 1.3.0
	 *
	 * @param  string $taxonomy The taxonomy name.
	 * @return array            The imported terms.
	 */
	protected function importTaxonomyTerms( $taxonomy ) {
		$locationCategories = get_terms( [
			'taxonomy'   => $taxonomy,
			'hide_empty' => false
		] );

		// The taxonomy probably doesn't exist.
		if ( is_wp_error( $locationCategories ) ) {
			return [];
		}

		$importedCategories = [];
		foreach ( $locationCategories as $locationCategory ) {
			$importedCategories[ $locationCategory->term_id ] = $this->importLocationCategory( $locationCategory );
		}

		// Fix parent reference.
		foreach ( $importedCategories as &$importedCategory ) {
			if ( ! empty( $importedCategory->oldParentId ) ) {
				$newParentId = $importedCategories[ $importedCategory->oldParentId ]->term_id;
				if ( $newParentId !== $importedCategory->parent ) {
					$importedCategory->parent = $newParentId;
					wp_update_term( $importedCategory->term_id, $importedCategory->taxonomy, [ 'parent' => $importedCategory->parent ] );
				}
			}
		}

		return $importedCategories;
	}

	/**
	 * Import a location category.
	 *
	 * @since 1.3.0
	 *
	 * @param  \WP_Term $locationCategory The location category to import.
	 * @return \WP_Term                   The imported location category.
	 */
	private function importLocationCategory( $locationCategory ) {
		$term = get_term_by( 'slug', $locationCategory->slug, aioseoLocalBusiness()->taxonomy->getName() );
		if ( ! $term ) {
			$term = wp_insert_term( $locationCategory->name, aioseoLocalBusiness()->taxonomy->getName(), [
				'description' => $locationCategory->description,
				'slug'        => $locationCategory->slug
			] );

			// Return new \WP_Term object.
			$term = get_term( $term['term_id'], aioseoLocalBusiness()->taxonomy->getName() );
		}

		// Reference old id to fix parent-child relationships.
		$term->oldParentId = $locationCategory->parent;  /** @phpstan-ignore-line */

		return $term;
	}
}