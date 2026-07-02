<?php
namespace AIOSEO\Plugin\Addon\LocalBusiness\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Location class.
 *
 * @since 1.1.0
 */
class LocationCategory {
	/**
	 * Location constructor.
	 *
	 * @since 1.1.0
	 */
	public function __construct() {
		add_action( 'init', [ $this, 'register' ] );
	}

	/**
	 * Returns the post type name.
	 *
	 * @since 1.1.0
	 *
	 * @return string The taxonomy name.
	 */
	public function getName() {
		return apply_filters( 'aioseo_local_business_taxonomy_name', 'aioseo-location-category' );
	}

	/**
	 * Returns the current slug for the taxonomy.
	 *
	 * @since 1.1.0
	 *
	 * @return string The taxonomy slug.
	 */
	public function getSlug() {
		$useCustomSlug    = aioseo()->options->localBusiness->locations->general->useCustomCategorySlug;
		$customSlugOption = aioseo()->options->localBusiness->locations->general->customCategorySlug ?: '';

		$customSlug = ( true === $useCustomSlug && 0 < strlen( $customSlugOption ) )
			? $customSlugOption
			: $this->getDefaultSlug();

		$customSlug = apply_filters( 'aioseo_local_business_taxonomy_slug', $customSlug );

		return $customSlug;
	}

	/**
	 * Returns the default slug for the taxonomy.
	 *
	 * @since 1.1.0
	 *
	 * @return string The taxonomy default slug.
	 */
	public function getDefaultSlug() {
		return 'locations-category';
	}

	/**
	 * Returns current permalink structure for this taxonomy.
	 *
	 * @since 1.1.0
	 *
	 * @return string The loaded permalink structure or a default if not enabled yet.
	 */
	public function getPermaStructure() {
		return aioseoLocalBusiness()->helpers->getPermaStructure( $this->getName(), $this->getSlug() );
	}

	/**
	 * Returns the single label for the taxonomy.
	 *
	 * @since 1.1.0
	 *
	 * @return string The taxonomy label.
	 */
	public function getSingleLabel() {
		return __( 'Location Category', 'aioseo-local-business' );
	}

	/**
	 * Returns the plural label for taxonomy.
	 *
	 * @since 1.1.0
	 *
	 * @return string The taxonomy plural label.
	 */
	public function getPluralLabel() {
		return __( 'Location Categories', 'aioseo-local-business' );
	}

	/**
	 * Returns the post type capabilites.
	 *
	 * @since 1.1.0
	 *
	 * @return array An array of mapped capabilities.
	 */
	public function getCapabilities() {
		return [
			'manage_terms' => 'manage_location_categories',
			'edit_terms'   => 'edit_location_categories',
			'delete_terms' => 'delete_location_categories',
			'assign_terms' => 'assign_location_categories'
		];
	}

	/**
	 * Registers the taxonomy.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function register() {
		if ( ! aioseo()->options->localBusiness->locations->general->multiple ) {
			return;
		}

		$labels = [
			'name'                  => $this->getPluralLabel(),
			'singular_name'         => $this->getSingleLabel(),
			// Translators: 1 - Plural post type name.
			'all_items'             => sprintf( _x( 'All %1$s', 'Taxonomy', 'aioseo-local-business' ), $this->getPluralLabel() ),
			// Translators: 1 - Singular post type name.
			'parent_item'           => sprintf( _x( 'Parent %1$s', 'Taxonomy', 'aioseo-local-business' ), $this->getSingleLabel() ),
			// Translators: 1 - Singular post type name.
			'parent_item_colon'     => sprintf( _x( 'Parent %1$s:', 'Taxonomy', 'aioseo-local-business' ), $this->getSingleLabel() ),
			// Translators: 1 - Singular post type name.
			'add_new_item'          => sprintf( _x( 'Add New %1$s', 'Taxonomy', 'aioseo-local-business' ), $this->getSingleLabel() ),
			// Translators: 1 - Singular post type name.
			'edit_item'             => sprintf( _x( 'Edit %1$s', 'Taxonomy', 'aioseo-local-business' ), $this->getSingleLabel() ),
			// Translators: 1 - Singular post type name.
			'update_item'           => sprintf( _x( 'Update %1$s', 'Taxonomy', 'aioseo-local-business' ), $this->getSingleLabel() ),
			// Translators: 1 - Singular or plural post type name.
			'view_item'             => sprintf( _x( 'View %1$s', 'Taxonomy', 'aioseo-local-business' ), $this->getSingleLabel() ),
			// Translators: 1 - Singular or plural post type name.
			'view_items'            => sprintf( _x( 'View %1$s', 'Taxonomy', 'aioseo-local-business' ), $this->getPluralLabel() ),
			// Translators: 1 - Plural post type name.
			'search_items'          => sprintf( _x( 'Search %1$s', 'Taxonomy', 'aioseo-local-business' ), $this->getPluralLabel() ),
			// Translators: 1 - Plural post type name.
			'no_terms'              => sprintf( _x( 'No %1$s', 'Taxonomy', 'aioseo-local-business' ), $this->getPluralLabel() ),
			// Translators: 1 - Plural post type name.
			'items_list_navigation' => sprintf( _x( '%1$s list navigation', 'Taxonomy', 'aioseo-local-business' ), $this->getPluralLabel() ),
			// Translators: 1 - Plural post type name.
			'filter_items_list'     => sprintf( _x( 'Filter %1$s list', 'Taxonomy', 'aioseo-local-business' ), $this->getPluralLabel() )
		];

		$taxonomy = [
			'labels'       => $labels,
			'public'       => true,
			'rewrite'      => [
				'slug'       => $this->getSlug(),
				'with_front' => false
			],
			'show_in_rest' => true,
			'capabilities' => $this->getCapabilities(),
			'hierarchical' => true
		];

		$taxonomy = apply_filters( 'aioseo_local_business_taxonomy', $taxonomy );

		register_taxonomy( $this->getName(), aioseoLocalBusiness()->postType->getName(), $taxonomy );
	}
}