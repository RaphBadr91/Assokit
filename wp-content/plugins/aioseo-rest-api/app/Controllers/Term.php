<?php
namespace AIOSEO\Plugin\Addon\RestApi\Controllers;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Pro\Models;
use AIOSEO\Plugin\Pro\Meta;

/**
 * Handles all term routes.
 *
 * @since 1.0.0
 */
class Term extends Base {
	/**
	 * Registers the fields dynamically.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register() {
		$taxonomies = aioseo()->helpers->getPublicTaxonomies( true );
		$taxonomies = apply_filters( 'aioseo_rest_api_taxonomies', $taxonomies );

		$supportedTaxonomies = [];
		foreach ( $taxonomies as $taxonomy ) {
			$taxonomyObject = get_taxonomy( $taxonomy );

			if (
				! is_a( $taxonomyObject, 'WP_Taxonomy' ) ||
				! $taxonomyObject->show_in_rest
			) {
				continue;
			}

			$supportedTaxonomies[] = $taxonomy;
		}

		foreach ( $supportedTaxonomies as $taxonomy ) {
			if ( 'post_tag' === $taxonomy ) {
				$taxonomy = 'tag';
			}

			$this->registerHeadFields( $taxonomy );
			$this->registerMetaDataField( $taxonomy );
			$this->registerBreadcrumbFields( $taxonomy );
			$this->registerDeprecatedUpdateFields( $taxonomy );
		}
	}

	/**
	 * Registers the meta data field.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $taxonomy The taxonomy name.
	 * @return void
	 */
	private function registerMetaDataField( $taxonomy ) {
		$callbacks = [
			'get_callback' => [ $this, 'getMetaData' ]
		];

		if ( $this->isAllowedToUpdate( $taxonomy ) ) {
			$callbacks['update_callback'] = [ $this, 'updateMetaData' ];
		}

		register_rest_field( $taxonomy, 'aioseo_meta_data', $callbacks );
	}

	/**
	 * Registers the deprecated single value fields.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $taxonomy The taxonomy name.
	 * @return void
	 */
	private function registerDeprecatedUpdateFields( $taxonomy ) {
		if ( ! $this->isAllowedToUpdate( $taxonomy ) ) {
			return;
		}

		foreach ( $this->deprecatedFields as $oldKey => $newKey ) {
			register_rest_field( $taxonomy, $oldKey, [
				'update_callback' => [ $this, 'updateMetaData' ]
			] );
		}
	}

	/**
	 * Checks whether the user is allowed to update meta data for the given taxonomy.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $taxonomy The taxonomy name.
	 * @return bool             Whether the user is allowed to update meta data for the taxonomy.
	 */
	private function isAllowedToUpdate( $taxonomy ) {
		if ( 'tag' === $taxonomy ) {
			$taxonomy = 'post_tag';
		}

		return apply_filters( 'aioseo_rest_api_allow_update', true, $taxonomy ) &&
			aioseo()->helpers->canEditTaxonomy( $taxonomy ) &&
			$this->canEditMetaData();
	}

	/**
	 * Returns the meta data for the given term.
	 *
	 * @since 1.0.0
	 *
	 * @param  array $term The term object.
	 * @return array       The meta data.
	 */
	public function getMetaData( $term ) {
		$aioseoTerm = Models\Term::getTerm( $term['id'] );
		$aioseoTerm = $aioseoTerm->exists()
			? json_decode( wp_json_encode( $aioseoTerm ), true )
			: [];

		return $this->removeInternalFields( $aioseoTerm );
	}

	/**
	 * Allows users to update the meta data of the given term.
	 *
	 * @since 1.0.0
	 *
	 * @param  array    $metaData  The new meta data.
	 * @param  \WP_Term $term      The term object.
	 * @param  string   $fieldName The field name.
	 * @return void
	 */
	public function updateMetaData( $metaData, $term, $fieldName ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		if ( ! current_user_can( 'edit_term', $term->term_id ) || ! $this->canEditMetaData() ) {
			return;
		}

		if ( 'aioseo_meta_data' !== $fieldName && isset( $this->deprecatedFields[ $fieldName ] ) ) {
			$metaData = [
				$this->deprecatedFields[ $fieldName ] => $metaData
			];
		}

		// Prevent the user from overriding the post ID.
		unset( $metaData['post_id'] );

		if ( empty( $metaData ) ) {
			return;
		}

		$aioseoTerm = json_decode( wp_json_encode( Models\Term::getTerm( $term->term_id ) ), true );
		$aioseoTerm = array_replace( $aioseoTerm, $metaData );

		// We'll just pass the data into saveTerm() so that the main plugin can handle all sanitization.
		Models\Term::saveTerm( $term->term_id, $aioseoTerm );
	}

	/**
	 * Sets the given term as the queried object of the main query.
	 *
	 * @since 1.0.0
	 *
	 * @param  array $termArr The term object.
	 * @return void
	 */
	protected function setWpQuery( $termArr ) {
		// phpcs:disable Squiz.NamingConventions.ValidVariableName
		global $wp_query;
		$this->originalQuery = clone $wp_query;

		$term = aioseo()->helpers->getTerm( $termArr['id'] );

		$wp_query->get_queried_object_id = (int) $term->term_id;
		$wp_query->queried_object        = $term;
		$wp_query->is_tax                = true;

		switch ( $term->taxonomy ) {
			case 'category':
				$wp_query->is_category = true;
				break;
			case 'post_tag':
				$wp_query->is_tag = true;
				break;
			default:
				break;
		}
		// phpcs:enable Squiz.NamingConventions.ValidVariableName
	}
}