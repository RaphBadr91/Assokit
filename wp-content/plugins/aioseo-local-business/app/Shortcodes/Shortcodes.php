<?php
namespace AIOSEO\Plugin\Addon\LocalBusiness\Shortcodes;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Shortcodes class.
 *
 * @since 1.1.0
 */
class Shortcodes {
	/**
	 * The shortcodes.
	 *
	 * @since 1.1.0
	 *
	 * @var string Array of shortcodes and callbacks.
	 */
	protected $shortcodes = [
		'aioseo_local_business_info' => 'businessInfo',
		'aioseo_local_locations'     => 'locations',
		'aioseo_local_opening_hours' => 'openingHours',
		'aioseo_local_map'           => 'map'
	];

	/**
	 * Class constructor.
	 *
	 * @since 1.1.0
	 */
	public function __construct() {
		foreach ( $this->shortcodes as $shortcode => $callback ) {
			add_shortcode( $shortcode, [ $this, $callback ] );
		}
	}

	/**
	 * Business info shortcode. Displays a location's business information.
	 *
	 * @since 1.1.0
	 *
	 * @param  array  $attributes The shortcode attributes.
	 * @return string             The rendered shortcode.
	 */
	public function businessInfo( $attributes = [] ) {
		$defaults = [
			'location_id'       => '',
			'show_labels'       => true,
			'show_icons'        => true,
			'show_name'         => true,
			'show_address'      => true,
			'show_phone'        => true,
			'show_fax'          => true,
			'show_country_code' => true,
			'show_email'        => true,
			'show_vat'          => true,
			'show_tax'          => true,
			'show_chamber_id'   => true,
			'address_label'     => __( 'Address:', 'aioseo-local-business' ),
			'vat_id_label'      => __( 'VAT ID:', 'aioseo-local-business' ),
			'tax_id_label'      => __( 'Tax ID:', 'aioseo-local-business' ),
			'phone_label'       => __( 'Phone:', 'aioseo-local-business' ),
			'fax_label'         => __( 'Fax:', 'aioseo-local-business' ),
			'email_label'       => __( 'Email:', 'aioseo-local-business' )
		];

		$attributes = $this->convertSnakeCaseAttributes( $attributes, $defaults );

		ob_start();

		aioseoLocalBusiness()->locations->outputBusinessInfo( absint( $attributes['locationId'] ), $attributes );

		return ob_get_clean();
	}

	/**
	 * Locations shortcode. Displays a list of locations based on a location category.
	 *
	 * @since 1.1.0
	 *
	 * @param  array  $attr The shortcode attributes.
	 * @return string       The rendered shortcode.
	 */
	public function locations( $attr = [] ) {
		$attr = shortcode_atts( [ 'category_id' => '' ], $attr, 'aioseo_locations' );

		$attributes = [ 'categoryId' => $attr['category_id'] ];

		ob_start();

		aioseoLocalBusiness()->locations->outputLocationCategory( absint( $attributes['categoryId'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		return ob_get_clean();
	}

	/**
	 * Opening hours shortcode. Displays a location's opening hours.
	 *
	 * @since 1.1.0
	 *
	 * @param  array  $attributes The shortcode attributes.
	 * @return string             The rendered shortcode.
	 */
	public function openingHours( $attributes = [] ) {
		$defaults = [
			'location_id'    => '',
			'show_title'     => true,
			'show_icons'     => true,
			'show_monday'    => true,
			'show_tuesday'   => true,
			'show_wednesday' => true,
			'show_thursday'  => true,
			'show_friday'    => true,
			'show_saturday'  => true,
			'show_sunday'    => true,
			'label'          => __( 'Our Opening Hours:', 'aioseo-local-business' )
		];

		$attributes = $this->convertSnakeCaseAttributes( $attributes, $defaults );

		ob_start();

		aioseoLocalBusiness()->locations->outputOpeningHours( absint( $attributes['locationId'] ), $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		return ob_get_clean();
	}

	/**
	 * Map shortcode. Displays a location's map.
	 *
	 * @since 1.1.3
	 *
	 * @param  array  $attributes The shortcode attributes.
	 * @return string             The rendered shortcode.
	 */
	public function map( $attributes = [] ) {
		$defaults = [
			'location_id' => '',
			'show_label'  => true,
			'show_icon'   => true,
			'width'       => '100%',
			'height'      => '450px',
			'label'       => __( 'Our location:', 'aioseo-local-business' )
		];

		$attributes = $this->convertSnakeCaseAttributes( $attributes, $defaults );

		ob_start();

		aioseoLocalBusiness()->locations->outputLocationMap( absint( $attributes['locationId'] ), $attributes );

		return ob_get_clean();
	}

	/**
	 * Convert snake case attributes to camel case.
	 *
	 * @since 4.1.5
	 *
	 * @param  array $attributes The attributes.
	 * @param  array $defaults   The defaults.
	 * @return array             Converted attributes.
	 */
	private function convertSnakeCaseAttributes( $attributes, $defaults = [] ) {
		$attributes = wp_parse_args( $attributes, $defaults );

		$convertedAttributes = [];
		foreach ( $attributes as $key => $value ) {
			if ( is_numeric( $key ) ) {
				continue;
			}

			$convertedAttributes[ aioseo()->helpers->toCamelCase( $key ) ] = is_bool( $defaults[ $key ] )
				? filter_var( $value, FILTER_VALIDATE_BOOLEAN )
				: $value;
		}

		return $convertedAttributes;
	}
}