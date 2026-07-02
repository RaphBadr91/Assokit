<?php
namespace AIOSEO\Plugin\Addon\LocalBusiness\Locations;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Models;

/**
 * The Locations class.
 *
 * @since 1.1.0
 */
class Locations {
	/**
	 * Returns all locations.
	 *
	 * @since 1.1.0
	 *
	 * @param  array $args Query args to be passed down to WP_Query.
	 * @return array       An array of WP_Post.
	 */
	public function getLocations( $args = [] ) {
		$args = wp_parse_args( $args, [
			'post_type'              => aioseoLocalBusiness()->postType->getName(),
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'posts_per_page'         => 100,
			'post_status'            => 'publish'
		] );

		$args = apply_filters( 'aioseo_local_business_get_locations_args', $args );

		$posts = new \WP_Query( $args );

		return apply_filters( 'aioseo_local_business_get_locations_posts', $posts->posts, $args );
	}

	/**
	 * Returns the JSON data for the given location.
	 *
	 * @since 1.1.0
	 *
	 * @param  integer      $postId The post id.
	 * @return false|object         The decoded JSON data.
	 */
	public function getLocation( $postId ) {
		if ( ! get_post( $postId ) ) {
			return false;
		}

		$post = Models\Post::getPost( $postId );

		return apply_filters( 'aioseo_local_business_get_location', $post->local_seo, $postId );
	}

	/**
	 * Returns the locations categories.
	 *
	 * @since 1.1.0
	 *
	 * @return array List of category objects.
	 */
	public function getLocationCategories() {
		$args = apply_filters( 'aioseo_local_business_get_location_category_args', [
			'taxonomy' => aioseoLocalBusiness()->taxonomy->getName(),
			'orderby'  => 'name'
		] );

		return apply_filters( 'aioseo_local_business_get_location_categories', get_categories( $args ), $args );
	}

	/**
	 * Returns locations by category.
	 *
	 * @since 1.1.0
	 *
	 * @param  integer $termId The term id.
	 * @param  array   $args   Query args to be passed down to WP_Query.
	 * @return array           An array of WP_Post.
	 */
	public function getLocationsByCategory( $termId, $args = [] ) {
		$args = wp_parse_args( $args, [
			'post_type'      => aioseoLocalBusiness()->postType->getName(),
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				[
					'taxonomy' => aioseoLocalBusiness()->taxonomy->getName(),
					'field'    => 'term_id',
					'terms'    => $termId
				],
			]
		] );

		$args = apply_filters( 'aioseo_local_business_get_locations_by_category_args', $args, $termId );

		$posts = new \WP_Query( $args );

		return apply_filters( 'aioseo_local_business_get_locations_by_category_posts', $posts->posts, $args, $termId );
	}

	/**
	 * Outputs the business info.
	 *
	 * @since 1.1.0
	 *
	 * @param  integer|string $postId   The location ID or 'global' keyword.
	 * @param  array          $instance An array of attributes used by the view.
	 * @return void
	 */
	public function outputBusinessInfo( $postId, $instance = [] ) {
		$postId = $this->maybeGlobalLocationId( $postId );

		if ( ! $this->canLocationIdRender( $postId ) ) {
			return;
		}

		$locationData = $this->getLocation( $postId );
		if ( 'global' === $postId ) {
			$locationData = aioseoLocalBusiness()->helpers->getLocalBusinessOptions();
		}

		$locationData = ! empty( $locationData->locations->business ) ? $locationData->locations->business : null;

		$locationData = ! empty( $instance['dataObject'] ) ? json_decode( $instance['dataObject'] ) : $locationData;

		if ( empty( $locationData ) ) {
			return;
		}

		// Parse defaults.
		$instance = wp_parse_args( $instance, [
			'class'           => '',
			'showLabels'      => true,
			'showIcons'       => true,
			'showAddress'     => true,
			'showName'        => true,
			'showVat'         => true,
			'showTax'         => true,
			'showChamberId'   => true,
			'showPhone'       => true,
			'showFax'         => true,
			'showCountryCode' => true,
			'showEmail'       => true,
			'addressFormat'   => '#streetLine1, #streetLine2 #newLine #zipCode - #city - #state #newLine #country',
			'addressLabel'    => __( 'Address:', 'aioseo-local-business' ),
			'emailLabel'      => __( 'Email:', 'aioseo-local-business' ),
			'phoneLabel'      => __( 'Phone:', 'aioseo-local-business' ),
			'faxLabel'        => __( 'Fax:', 'aioseo-local-business' ),
			'vatIdLabel'      => __( 'VAT ID:', 'aioseo-local-business' ),
			'taxIdLabel'      => __( 'Tax ID:', 'aioseo-local-business' )
		] );

		$instance['class'] .= $instance['showLabels'] ? '' : ' hide-label ';
		$instance['class'] .= $instance['showIcons'] ? '' : ' hide-icon ';

		$instance     = apply_filters( 'aioseo_local_business_output_business_info_instance', $instance, $postId, $locationData );
		$locationData = apply_filters( 'aioseo_local_business_output_business_info_location_data', $locationData, $instance, $postId );

		aioseoLocalBusiness()->assets->enqueueCss( 'src/assets/scss/business-info.scss' );

		$template = aioseoLocalBusiness()->templates->locateTemplate( 'BusinessInfo.php' );

		require $template;
	}

	/**
	 * Outputs a list of locations by category.
	 *
	 * @since 1.1.0
	 *
	 * @param  integer $termId   The term ID.
	 * @param  array   $instance An array of attributes used by the view.
	 * @return void
	 */
	public function outputLocationCategory( $termId, $instance = [] ) {
		if ( ! $this->canLocationIdRender( $termId ) ) {
			return;
		}

		$locations = $this->getLocationsByCategory( $termId );
		if ( empty( $locations ) ) {
			return sprintf(
				// Translators: 1 - The post type plural label.
				__( 'No %1$s found', 'aioseo-local-business' ),
				aioseoLocalBusiness()->postType->getPluralLabel()
			);
		}

		$instance = wp_parse_args( $instance, [
			'class' => '',
		] );

		$instance  = apply_filters( 'aioseo_local_business_output_location_category_instance', $instance, $termId, $locations );
		$locations = apply_filters( 'aioseo_local_business_output_location_category_location_data', $locations, $instance, $termId );

		$template = aioseoLocalBusiness()->templates->locateTemplate( 'Locations.php' );

		require $template;
	}

	/**
	 * Outputs the opening hours.
	 *
	 * @since 1.1.0
	 *
	 * @param  integer|string $postId   The location ID or 'global' keyword.
	 * @param  array          $instance An array of attributes used by the view.
	 * @return void
	 */
	public function outputOpeningHours( $postId, $instance = [] ) {
		$postId = $this->maybeGlobalLocationId( $postId );

		if ( ! $this->canLocationIdRender( $postId ) ) {
			return;
		}

		$locationData = $this->getLocation( $postId );

		$openingHoursData = ! empty( $instance['dataObject'] ) ?
			json_decode( $instance['dataObject'] ) :
			( ! empty( $locationData->openingHours ) ? $locationData->openingHours : null );

		if ( 'global' === $postId || ! empty( $openingHoursData->useDefaults ) ) {
			$locationData = aioseoLocalBusiness()->helpers->getLocalBusinessOptions();
			$openingHoursData = $locationData->openingHours;
		}

		if ( empty( $openingHoursData->show ) ) {
			if ( aioseo()->blocks->isRenderingBlockInEditor() ) {
				return __( 'Your Opening Hours settings are disabled. Please enable them to use the Opening Hours block.', 'aioseo-local-business' );
			}

			return;
		}

		// Parse defaults.
		$instance = wp_parse_args( $instance, [ // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
			'class'         => '',
			'showTitle'     => true,
			'showIcons'     => true,
			'showMonday'    => true,
			'showTuesday'   => true,
			'showWednesday' => true,
			'showThursday'  => true,
			'showFriday'    => true,
			'showSaturday'  => true,
			'showSunday'    => true
		] );

		$instance         = apply_filters( 'aioseo_local_business_output_opening_hours_instance', $instance, $postId, $openingHoursData );
		$openingHoursData = apply_filters( 'aioseo_local_business_output_opening_hours_data', $openingHoursData, $instance, $postId );

		aioseoLocalBusiness()->assets->enqueueCss( 'src/assets/scss/opening-hours.scss' );

		$template = aioseoLocalBusiness()->templates->locateTemplate( 'OpeningHours.php' );

		require $template;
	}

	/**
	 * Outputs a list of categories.
	 *
	 * @since 1.1.1
	 *
	 * @param  array $instance An array of attributes used by the view.
	 * @return void
	 */
	public function outputLocationCategories( $instance = [] ) {
		$categories = $this->getLocationCategories();

		if ( empty( $categories ) ) {
			return sprintf(
				// Translators: 1 - The post type plural label.
				__( 'No %1$s found', 'aioseo-local-business' ),
				aioseoLocalBusiness()->taxonomy->getPluralLabel()
			);
		}

		$instance = wp_parse_args( $instance, [
			'class' => '',
		] );

		$instance   = apply_filters( 'aioseo_local_business_output_location_categories_instance', $instance );
		$categories = apply_filters( 'aioseo_local_business_output_location_categories', $categories, $instance );

		$template = aioseoLocalBusiness()->templates->locateTemplate( 'LocationCategories.php' );

		require $template;
	}

	/**
	 * Outputs a map.
	 *
	 * @since 1.1.3
	 *
	 * @param  array $instance An array of attributes used by the view.
	 * @return void
	 */
	public function outputLocationMap( $postId, $instance = [] ) {
		$postId = $this->maybeGlobalLocationId( $postId );
		if ( ! $this->canLocationIdRender( $postId ) ) {
			return;
		}

		$locationData = $this->getLocation( $postId );
		if ( 'global' === $postId ) {
			$locationData = aioseoLocalBusiness()->helpers->getLocalBusinessOptions();
		}

		$locationMapData = ! empty( $locationData->maps ) ? $locationData->maps : null;

		$locationMapData = ! empty( $instance['dataObject'] ) ? json_decode( $instance['dataObject'] ) : $locationMapData;

		if ( empty( $locationMapData ) ) {
			return;
		}

		// Parse defaults.
		$instance = wp_parse_args( $instance, [
			'class'      => '',
			'showLabels' => true,
			'showIcons'  => true,
			'width'      => '100%',
			'height'     => '450px'
		] );

		$instance['class'] .= $instance['showLabels'] ? '' : ' hide-label ';
		$instance['class'] .= $instance['showIcons'] ? '' : ' hide-icon ';

		$instance        = apply_filters( 'aioseo_local_business_output_location_map_instance', $instance, $postId, $locationData );
		$locationMapData = apply_filters( 'aioseo_local_business_output_location_map_data', $locationMapData, $instance, $postId );

		$instance['mapId'] = uniqid( 'aioseo-local-map-' );

		$customMarker = ! empty( $instance['customMarker'] ) ? $instance['customMarker'] : $locationMapData->customMarker;

		aioseoLocalBusiness()->maps->enqueues();

		aioseoLocalBusiness()->maps->mapStartEvent( [
			'element'           => '#' . $instance['mapId'],
			'mapOptions'        => $locationMapData->mapOptions,
			'customMarker'      => ! empty( $customMarker ) ? $customMarker : aioseo()->options->localBusiness->maps->customMarker,
			'placeId'           => aioseo()->options->localBusiness->maps->mapsEmbedApiEnabled && isset( $locationMapData->placeId ) ? $locationMapData->placeId : null,
			'instance'          => $instance,
			'infoWindowContent' => aioseoLocalBusiness()->maps->getMarkerInfoWindow( $locationData )
		] );

		$template = aioseoLocalBusiness()->templates->locateTemplate( 'Map.php' );

		require $template;
	}

	/**
	 * Determine if we should be loading our global information.
	 *
	 * @since 1.1.0
	 *
	 * @param  void|string|integer $locationId The post ID.
	 * @return mixed|string                    The post ID or 'global'.
	 */
	private function maybeGlobalLocationId( $locationId ) {
		if ( empty( $locationId ) && ! aioseo()->options->localBusiness->locations->general->multiple ) {
			$locationId = 'global';
		}

		return $locationId;
	}

	/**
	 * Returns if a given location ID is allowed to render.
	 *
	 * @since 1.1.0
	 *
	 * @param  string|integer|null $locationId The postId.
	 * @return bool                            Should the location be rendered.
	 */
	private function canLocationIdRender( $locationId = null ) {
		if (
			( aioseo()->options->localBusiness->locations->general->multiple && 'global' === $locationId ) ||
			! aioseo()->options->localBusiness->locations->general->multiple && 'global' !== $locationId
		) {
			return false;
		}

		return true;
	}

	/**
	 * Formats a phone number with the country code.
	 *
	 * @since 1.1.0
	 *
	 * @param  string $phoneNumber     The phone number.
	 * @param  bool   $keepCountryCode Keep the country code.
	 * @return string                  Formatted phone number.
	 */
	public function formatPhone( $phoneNumber, $keepCountryCode = true ) {
		return $keepCountryCode ? $phoneNumber : preg_replace( '/^\+[0-9]+\s/', '', (string) $phoneNumber );
	}

	/**
	 * Finds a location based on the name.
	 *
	 * @since 1.3.0
	 *
	 * @param  string $locationName The location name.
	 * @return array                The location found.
	 */
	public function getLocationByName( $locationName, $status = 'any' ) {
		$foundLocations = $this->getLocations( [
			'name'        => sanitize_title( $locationName ),
			'post_status' => $status
		] );

		return is_array( $foundLocations ) ? current( $foundLocations ) : [];
	}
}