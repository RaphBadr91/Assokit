<?php
namespace AIOSEO\Plugin\Pro\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Api as CommonApi;
use AIOSEO\Plugin\Pro\Models;

/**
 * Route class for the API.
 *
 * @since 4.0.0
 */
class Settings extends CommonApi\Settings {
	/**
	 * Save options from the front end.
	 *
	 * @since 4.1.0
	 *
	 * @param  \WP_REST_Request  $request The REST Request
	 * @return \WP_REST_Response          The response.
	 */
	public static function saveChanges( $request ) {
		$response = parent::saveChanges( $request );

		Api::addonsApi( $request, null, '\\Api\\Settings', 'saveChanges' );

		return $response;
	}

	/**
	 * Import from other plugins.
	 *
	 * @since 4.2.5
	 *
	 * @param  \WP_REST_Request  $request The REST Request
	 * @return \WP_REST_Response          The response.
	 */
	public static function importPlugins( $request ) {
		$body   = $request->get_json_params();
		$siteId = ! empty( $body['siteId'] ) ? (int) $body['siteId'] : get_current_blog_id();

		aioseo()->helpers->switchToBlog( $siteId );

		return parent::importPlugins( $request );
	}

	/**
	 * Imports settings.
	 *
	 * @since 4.0.0
	 *
	 * @param  \WP_REST_Request  $request The REST Request
	 * @return \WP_REST_Response          The response.
	 */
	public static function importSettings( $request ) {
		$args   = $request->get_params();
		$siteId = ! empty( $args['siteId'] ) ? (int) $args['siteId'] : get_current_blog_id();

		aioseo()->helpers->switchToBlog( $siteId );

		$response = parent::importSettings( $request );
		if ( ! $response->data['success'] ) {
			return $response;
		}

		$contents = parent::$importFile;

		if ( ! empty( $contents['postOptions'] ) ) {
			$notAllowedFields = aioseo()->access->getNotAllowedPageFields();
			foreach ( $contents['postOptions'] as $postData ) {
				// Terms.
				if ( ! empty( $postData['terms'] ) ) {
					foreach ( $postData['terms'] as $term ) {
						unset( $term['id'] );
						// Clean up the array removing fields the user should not manage.
						$term = array_diff_key( $term, $notAllowedFields );

						$floatFields = [ 'priority' ];
						foreach ( $term as $key => $field ) {
							if ( in_array( $key, $floatFields, true ) ) {
								$field        = ! empty( $field ) ? trim( $field ) : $field;
								$term[ $key ] = ! empty( $field ) ? number_format( (float) $field, 1 ) : null;
							}
						}

						$theTerm = Models\Term::getTerm( $term['term_id'] );
						$theTerm->set( $term );
						$theTerm->save();
					}
				}
			}
		}

		$response->data['license'] = [
			'isActive'   => aioseo()->license->isActive(),
			'isExpired'  => aioseo()->license->isExpired(),
			'isDisabled' => aioseo()->license->isDisabled(),
			'isInvalid'  => aioseo()->license->isInvalid(),
			'expires'    => aioseo()->internalOptions->internal->license->expires
		];

		return Api::addonsApi( $request, $response, '\\Api\\Settings', 'importSettings' );
	}

	/**
	 * Export settings.
	 *
	 * @since 4.0.6
	 *
	 * @param  \WP_REST_Request  $request The REST Request
	 * @return \WP_REST_Response          The response.
	 */
	public static function exportSettings( $request ) {
		$body   = $request->get_json_params();
		$siteId = ! empty( $body['siteId'] ) ? (int) $body['siteId'] : get_current_blog_id();

		aioseo()->helpers->switchToBlog( $siteId );

		$response = parent::exportSettings( $request );

		return Api::addonsApi( $request, $response, '\\Api\\Settings', 'exportSettings' );
	}

	/**
	 * Export Post Types.
	 *
	 * @since 4.7.2
	 *
	 * @param  \WP_REST_Request  $request The REST Request.
	 * @return \WP_REST_Response          The response.
	 */
	public static function exportContent( $request ) {
		$body              = $request->get_json_params();
		$postTypes         = $body['postOptions'] ?? [];
		$taxonomies        = $body['taxonomiesOptions'] ?? [];
		$typeFile          = $body['typeFile'] ?? false;
		$siteId            = (int) ( $body['siteId'] ?? get_current_blog_id() );
		$contentTaxonomies = null;
		$return            = true;

		if ( ! empty( $postTypes ) ) {
			return parent::exportContent( $request );
		}

		try {
			aioseo()->helpers->switchToBlog( $siteId );

			if ( ! empty( $taxonomies ) ) {
				if ( in_array( 'product_attributes', $taxonomies, true ) ) {
					$allTaxonomies = get_taxonomies( [ 'public' => true ], 'objects' );
					foreach ( $allTaxonomies as $taxonomy ) {
						if ( aioseo()->helpers->isWooCommerceProductAttribute( $taxonomy->name ) ) {
							$taxonomies[] = $taxonomy->name;
						}
					}

					unset( $taxonomies[ array_search( 'product_attributes', $taxonomies, true ) ] );
				}

				$fieldsToExclude = [
					'images'          => '',
					'videos'          => '',
					'video_scan_date' => '',
					'local_seo'       => ''
				];

				$notAllowed = array_merge( aioseo()->access->getNotAllowedPageFields(), $fieldsToExclude );
				$terms      = self::getTermData( $taxonomies, $notAllowed );

				// Generate content to CSV or JSON.
				if ( ! empty( $terms ) ) {
					// Change the order of keys so the taxonomy shows up at the beginning.
					$data = [];
					foreach ( $terms as $t ) {
						$item = [
							'id'      => $t['id'],
							'term_id' => $t['term_id'],
							'name'    => $t['name'],
						];

						$data[] = array_merge( $item, $t );
					}

					if ( 'csv' === $typeFile ) {
						$contentTaxonomies = parent::dataToCsv( $data );
					}
					if ( 'json' === $typeFile ) {
						$contentTaxonomies['postOptions']['content']['terms'] = $data;
					}
				}
			}
		} catch ( \Throwable $th ) {
			$return = false;
		}

		return new \WP_REST_Response( [
			'success'        => $return,
			'postTypeData'   => null,
			'taxonomiesData' => $contentTaxonomies
		], 200 );
	}

	/**
	 * Returns the Terms for the specific Taxonomies Options.
	 *
	 * @since 4.7.2
	 *
	 * @param  array $taxonomies       The taxonomies.
	 * @param  array $notAllowedFields The fields not allowed.
	 * @return array                   The terms.
	 */
	private static function getTermData( $taxonomies, $notAllowedFields = [] ) {
		$terms = aioseo()->core->db->start( 'aioseo_terms as at' )
			->select( 'at.*, t.name' )
			->join( 'terms as t', 't.term_id = at.term_id' )
			->join( 'term_taxonomy as tt', 'tt.term_id = at.term_id' )
			->whereIn( 'tt.taxonomy', $taxonomies )
			->orderBy( 'tt.taxonomy' )
			->groupBy( 'at.id, at.term_id' )
			->run()
			->result();

		if ( ! empty( $notAllowedFields ) ) {
			foreach ( $terms as $key => &$term ) {
				$term = array_diff_key( (array) $term, $notAllowedFields );
				if ( count( $term ) <= 2 ) {
					unset( $terms[ $key ] );
				}
			}
		}

		return $terms;
	}

	/**
	 * Reset settings.
	 *
	 * @since 4.1.0
	 *
	 * @param  \WP_REST_Request  $request The REST Request.
	 * @return \WP_REST_Response          The response.
	 */
	public static function resetSettings( $request ) {
		$body   = $request->get_json_params();
		$siteId = ! empty( $body['siteId'] ) ? (int) $body['siteId'] : get_current_blog_id();

		aioseo()->helpers->switchToBlog( $siteId );

		$response = parent::resetSettings( $request );

		return Api::addonsApi( $request, $response, '\\Api\\Settings', 'resetSettings' );
	}

	/**
	 * Executes a given administrative task.
	 *
	 * @since 4.1.6
	 *
	 * @param  \WP_REST_Request  $request The REST Request.
	 * @return \WP_REST_Response          The response.
	 */
	public static function doTask( $request ) {
		$body          = $request->get_json_params();
		$action        = ! empty( $body['action'] ) ? $body['action'] : '';
		$siteId        = ! empty( $body['siteId'] ) ? intval( $body['siteId'] ) : false;
		$siteOrNetwork = empty( $siteId ) ? aioseo()->helpers->getNetworkId() : $siteId;

		// First, check if an addon registered action is found.
		$addonActionExecuted = array_filter( aioseo()->addons->doAddonFunction( 'helpers', 'doTask', [ $action ] ) );
		$addonActionExecuted = end( $addonActionExecuted );

		if ( $addonActionExecuted ) {
			return new \WP_REST_Response( [
				'success' => true
			], 200 );
		}

		aioseo()->helpers->switchToBlog( $siteOrNetwork );

		// Then, check our Pro actions.
		switch ( $action ) {
			case 'reset-data':
				aioseo()->uninstall->dropData( true );
				aioseo()->core->cache->delete( 'db_schema' );
				aioseo()->internalOptions->internal->lastActiveProVersion = '4.0.0';
				aioseo()->internalOptions->save( true );
				aioseo()->updates->addInitialCustomTablesForV4();
				break;
			case 'rerun-migrations':
				aioseo()->core->cache->delete( 'db_schema' );
				aioseo()->internalOptions->internal->lastActiveProVersion = '4.0.0';
				aioseo()->internalOptions->save( true );
				break;
		}

		aioseo()->helpers->restoreCurrentBlog();

		// We still want to run through the Common actions here since we still need to retrigger the common migrations as well.
		return parent::doTask( $request );
	}
}