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
class Location {
	/**
	 * Class constructor.
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
	 * @return string The post name.
	 */
	public function getName() {
		return apply_filters( 'aioseo_local_business_post_type_name', 'aioseo-location' );
	}

	/**
	 * Returns the current slug for the post type.
	 *
	 * @since 1.1.0
	 *
	 * @return string The post rewrite slug.
	 */
	public function getSlug() {
		$useCustomSlug    = aioseo()->options->localBusiness->locations->general->useCustomSlug;
		$customSlugOption = aioseo()->options->localBusiness->locations->general->customSlug ?: '';

		$customSlug = ( true === $useCustomSlug && 0 < strlen( $customSlugOption ) )
			? $customSlugOption
			: $this->getDefaultSlug();

		$customSlug = apply_filters( 'aioseo_local_business_post_type_slug', $customSlug );

		return $customSlug;
	}

	/**
	 * Returns the default slug for the post type.
	 *
	 * @since 1.1.0
	 *
	 * @return string The default rewrite slug.
	 */
	public function getDefaultSlug() {
		return 'location';
	}

	/**
	 * Returns the single label for the post type.
	 *
	 * @since 1.1.0
	 *
	 * @return string The post single label.
	 */
	public function getSingleLabel() {
		$singleLabelOption = aioseo()->options->localBusiness->locations->general->singleLabel ?: '';

		$singleLabel = ( 0 < strlen( $singleLabelOption ) ) ? $singleLabelOption : __( 'Location', 'aioseo-local-business' );
		$singleLabel = apply_filters( 'aioseo_local_business_post_type_single_label', $singleLabel );

		return $singleLabel;
	}

	/**
	 * Returns the plural label for the post type.
	 *
	 * @since 1.1.0
	 *
	 * @return string The post plural label.
	 */
	public function getPluralLabel() {
		$pluralLabelOption = aioseo()->options->localBusiness->locations->general->pluralLabel ?: '';

		$pluralLabel = ( 0 < strlen( $pluralLabelOption ) ) ? $pluralLabelOption : __( 'Locations', 'aioseo-local-business' );
		$pluralLabel = apply_filters( 'aioseo_local_business_post_type_plural_label', $pluralLabel );

		return $pluralLabel;
	}

	/**
	 * Returns current permalink structure for this post type.
	 *
	 * @since 1.1.0
	 *
	 * @return string The loaded permastruct or a default if not enabled yet.
	 */
	public function getPermaStructure() {
		return aioseoLocalBusiness()->helpers->getPermaStructure( $this->getName(), $this->getSlug() );
	}

	/**
	 * Returns edit link for this post type.
	 *
	 * @since 1.1.0
	 *
	 * @return false|string The main post type edit link.
	 */
	public function getEditLink() {
		return admin_url( 'edit.php?post_type=' . $this->getName() );
	}

	/**
	 * Returns this post type capabilites.
	 *
	 * @since 1.1.0
	 *
	 * @return array An array of mapped capabilities.
	 */
	public function getCapabilities() {
		return [
			'edit_post'          => 'edit_aioseo_location',
			'edit_posts'         => 'edit_aioseo_locations',
			'edit_others_posts'  => 'edit_other_aioseo_locations',
			'publish_posts'      => 'publish_aioseo_locations',
			'read_post'          => 'read_aioseo_location',
			'read_private_posts' => 'read_private_aioseo_locations',
			'delete_post'        => 'delete_aioseo_location'
		];
	}

	/**
	 * Register the post type.
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
			// Translators: 1 - Singular post type name.
			'archives'              => sprintf( _x( '%1$s Archives', 'Post Type', 'aioseo-local-business' ), $this->getSingleLabel() ),
			// Translators: 1 - Singular post type name.
			'attributes'            => sprintf( _x( '%1$s Attributes', 'Post Type', 'aioseo-local-business' ), $this->getSingleLabel() ),
			// Translators: 1 - Singular post type name.
			'parent_item_colon'     => sprintf( _x( 'Parent %1$s:', 'Post Type', 'aioseo-local-business' ), $this->getSingleLabel() ),
			// Translators: 1 - Plural post type name.
			'all_items'             => sprintf( _x( 'All %1$s', 'Post Type', 'aioseo-local-business' ), $this->getPluralLabel() ),
			// Translators: 1 - Singular post type name.
			'add_new'               => sprintf( _x( 'Add New %1$s', 'Post Type', 'aioseo-local-business' ), $this->getSingleLabel() ),
			// Translators: 1 - Singular post type name.
			'add_new_item'          => sprintf( _x( 'Add New %1$s', 'Post Type', 'aioseo-local-business' ), $this->getSingleLabel() ),
			// Translators: 1 - Singular post type name.
			'new_item'              => sprintf( _x( 'New %1$s', 'Post Type', 'aioseo-local-business' ), $this->getSingleLabel() ),
			// Translators: 1 - Singular post type name.
			'edit_item'             => sprintf( _x( 'Edit %1$s', 'Post Type', 'aioseo-local-business' ), $this->getSingleLabel() ),
			// Translators: 1 - Singular post type name.
			'update_item'           => sprintf( _x( 'Update %1$s', 'Post Type', 'aioseo-local-business' ), $this->getSingleLabel() ),
			// Translators: 1 - Singular or plural post type name.
			'view_item'             => sprintf( _x( 'View %1$s', 'Post Type', 'aioseo-local-business' ), $this->getSingleLabel() ),
			// Translators: 1 - Singular or plural post type name.
			'view_items'            => sprintf( _x( 'View %1$s', 'Post Type', 'aioseo-local-business' ), $this->getPluralLabel() ),
			// Translators: 1 - Singular post type name.
			'search_items'          => sprintf( _x( 'Search %1$s', 'Post Type', 'aioseo-local-business' ), $this->getSingleLabel() ),
			// Translators: 1 - Singular post type name.
			'insert_into_item'      => sprintf( _x( 'Insert into %1$s', 'Post Type', 'aioseo-local-business' ), $this->getSingleLabel() ),
			// Translators: 1 - Singular post type name.
			'uploaded_to_this_item' => sprintf( _x( 'Uploaded to this %1$s', 'Post Type', 'aioseo-local-business' ), $this->getSingleLabel() ),
			// Translators: 1 - Plural post type name.
			'items_list'            => sprintf( _x( '%1$s list', 'Post Type', 'aioseo-local-business' ), $this->getPluralLabel() ),
			// Translators: 1 - Plural post type name.
			'items_list_navigation' => sprintf( _x( '%1$s list navigation', 'Post Type', 'aioseo-local-business' ), $this->getPluralLabel() ),
			// Translators: 1 - Plural post type name.
			'filter_items_list'     => sprintf( _x( 'Filter %1$s list', 'Post Type', 'aioseo-local-business' ), $this->getPluralLabel() ),
		];

		$postType = [
			'labels'       => $labels,
			'description'  => sprintf(
				// Translators: 1 - The plugin name ("All in One SEO").
				__( '%1$s uses these locations to generate Local Business schema markup.', 'aioseo-local-business' ),
				AIOSEO_PLUGIN_NAME
			),
			'public'       => true,
			'rewrite'      => [
				'slug'       => $this->getSlug(),
				'with_front' => false
			],
			'pages'        => false,
			'menu_icon'    => 'dashicons-location',
			'show_in_rest' => true,
			'capabilities' => $this->getCapabilities(),
			'map_meta_cap' => true,
			'supports'     => [ 'title', 'editor', 'thumbnail' ]
		];

		$postType = apply_filters( 'aioseo_local_business_post_type', $postType );

		if ( ! in_array( $this->getName(), get_post_types(), true ) ) {
			aioseo()->options->flushRewriteRules();
		}

		register_post_type(
			$this->getName(),
			$postType
		);

		register_rest_field( $this->getName(), 'maps', [
			'get_callback' => [ aioseoLocalBusiness()->maps, 'restMapInfo' ]
		] );
	}
}