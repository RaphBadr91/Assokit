<?php
namespace AIOSEO\Plugin\Pro\Models;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\Models as CommonModels;

/**
 * The Term DB Model.
 *
 * @since 4.0.0
 */
class Term extends CommonModels\Model {
	/**
	 * The name of the table in the database, without the prefix.
	 *
	 * @since 4.0.0
	 *
	 * @var string
	 */
	protected $table = 'aioseo_terms';

	/**
	 * Fields that should be json encoded on save and decoded on get.
	 *
	 * @since 4.0.0
	 *
	 * @var array
	 */
	protected $jsonFields = [
		'keywords',
		'og_article_tags',
		'videos',
		'breadcrumb_settings'
	];

	/**
	 * Fields that should be boolean values.
	 *
	 * @since 4.0.0
	 *
	 * @var array
	 */
	protected $booleanFields = [
		'twitter_use_og',
		'robots_default',
		'robots_noindex',
		'robots_noarchive',
		'robots_nosnippet',
		'robots_nofollow',
		'robots_noimageindex',
		'robots_noodp',
		'robots_notranslate'
	];

	/**
	 * Fields that can be null when saved.
	 *
	 * @since 4.5.7
	 *
	 * @var array
	 */
	protected $nullFields = [
		'priority'
	];

	/**
	 * Fields that should be float values.
	 *
	 * @since 4.7.3
	 *
	 * @var array
	 */
	protected $floatFields = [
		'priority'
	];

	/**
	 * Fields that should be hidden when serialized.
	 *
	 * @since 4.0.0
	 *
	 * @var array
	 */
	protected $hidden = [ 'id' ];

	/**
	 * Returns a Term with a given ID.
	 *
	 * @since 4.0.0
	 *
	 * @param  int  $termId The term ID.
	 * @return Term         The Term object.
	 */
	public static function getTerm( $termId ) {
		$term = aioseo()->core->db
			->start( 'aioseo_terms' )
			->where( 'term_id', $termId )
			->run()
			->model( 'AIOSEO\\Plugin\\Pro\\Models\\Term' );

		if ( ! $term->exists() ) {
			$term->term_id = $termId;
		}

		return apply_filters( 'aioseo_get_term', $term );
	}

	/**
	 * Saves the Term object.
	 *
	 * @since 4.0.3
	 *
	 * @param  int              $termId The term ID.
	 * @param  string           $data   The term data to save.
	 * @return bool|void|string         Whether the term data was saved or a DB error message.
	 */
	public static function saveTerm( $termId, $data ) {
		if ( empty( $data ) ) {
			return false;
		}

		$theTerm = self::getTerm( $termId );
		$data    = apply_filters( 'aioseo_save_term', $data, $theTerm );

		// Before setting the data, we check if the title/description are the same as the defaults and clear them if so.
		$data    = self::checkForDefaultFormat( $termId, $theTerm, $data );
		$theTerm = self::sanitizeAndSetDefaults( $termId, $theTerm, $data );

		// Update traditional term meta so that it can be used by multilingual plugins.
		self::updateTermMeta( $termId, $data );

		$theTerm->save();
		$theTerm->reset();

		$lastError = aioseo()->core->db->lastError();
		if ( ! empty( $lastError ) ) {
			return $lastError;
		}

		// Fires once an AIOSEO term has been saved.
		do_action( 'aioseo_insert_term', $termId );

		return true;
	}

	/**
	 * Checks if the title/description is the same as their default format in Search Appearance and nulls it if this is the case.
	 * Doing this ensures that updates to the default title/description format also propogate to the term.
	 *
	 * @since 4.1.5
	 *
	 * @param  int   $termId  The term ID.
	 * @param  Term  $theTerm The Term object.
	 * @param  array $data    The data.
	 * @return array          The data.
	 */
	private static function checkForDefaultFormat( $termId, $theTerm, $data ) {
		$data['title']       = trim( $data['title'] );
		$data['description'] = trim( $data['description'] );

		$term                     = aioseo()->helpers->getTerm( $termId );
		$defaultTitleFormat       = trim( aioseo()->meta->title->getTaxonomyTitle( $term->taxonomy ) );
		$defaultDescriptionFormat = trim( aioseo()->meta->description->getTaxonomyDescription( $term->taxonomy ) );
		if ( ! empty( $data['title'] ) && $data['title'] === $defaultTitleFormat ) {
			$data['title'] = null;
		}

		if ( ! empty( $data['description'] ) && $data['description'] === $defaultDescriptionFormat ) {
			$data['description'] = null;
		}

		return $data;
	}

	/**
	 * Sanitizes the term data and sets it (or the default value) to the Term object.
	 *
	 * @since 4.1.5
	 *
	 * @param  int   $termId  The term ID.
	 * @param  Term  $theTerm The Term object.
	 * @param  array $data    The data.
	 * @return Term           The Term object with data set.
	 */
	private static function sanitizeAndSetDefaults( $termId, $theTerm, $data ) {
		// General
		$theTerm->term_id                     = $termId;
		$theTerm->title                       = ! empty( $data['title'] ) ? sanitize_text_field( $data['title'] ) : null;
		$theTerm->description                 = ! empty( $data['description'] ) ? sanitize_text_field( $data['description'] ) : null;
		$theTerm->canonical_url               = ! empty( $data['canonicalUrl'] ) ? esc_url_raw( $data['canonicalUrl'] ) : null;
		$theTerm->keywords                    = ! empty( $data['keywords'] ) ? aioseo()->helpers->sanitize( $data['keywords'] ) : null;
		// Sitemap
		$theTerm->priority                    = isset( $data['priority'] ) ? ( 'default' === sanitize_text_field( $data['priority'] ) ? null : (float) $data['priority'] ) : null;
		$theTerm->frequency                   = ! empty( $data['frequency'] ) ? sanitize_text_field( $data['frequency'] ) : null;
		// Robots Meta
		$theTerm->robots_default              = isset( $data['default'] ) ? rest_sanitize_boolean( $data['default'] ) : 1;
		$theTerm->robots_noindex              = isset( $data['noindex'] ) ? rest_sanitize_boolean( $data['noindex'] ) : 0;
		$theTerm->robots_nofollow             = isset( $data['nofollow'] ) ? rest_sanitize_boolean( $data['nofollow'] ) : 0;
		$theTerm->robots_noarchive            = isset( $data['noarchive'] ) ? rest_sanitize_boolean( $data['noarchive'] ) : 0;
		$theTerm->robots_notranslate          = isset( $data['notranslate'] ) ? rest_sanitize_boolean( $data['notranslate'] ) : 0;
		$theTerm->robots_noimageindex         = isset( $data['noimageindex'] ) ? rest_sanitize_boolean( $data['noimageindex'] ) : 0;
		$theTerm->robots_nosnippet            = isset( $data['nosnippet'] ) ? rest_sanitize_boolean( $data['nosnippet'] ) : 0;
		$theTerm->robots_noodp                = isset( $data['noodp'] ) ? rest_sanitize_boolean( $data['noodp'] ) : 0;
		$theTerm->robots_max_snippet          = isset( $data['maxSnippet'] ) && is_numeric( $data['maxSnippet'] ) ? (int) sanitize_text_field( $data['maxSnippet'] ) : -1;
		$theTerm->robots_max_videopreview     = isset( $data['maxVideoPreview'] ) && is_numeric( $data['maxVideoPreview'] ) ? (int) sanitize_text_field( $data['maxVideoPreview'] ) : -1;
		$theTerm->robots_max_imagepreview     = ! empty( $data['maxImagePreview'] ) ? sanitize_text_field( $data['maxImagePreview'] ) : 'large';
		// Open Graph Meta
		$theTerm->og_title                    = ! empty( $data['og_title'] ) ? sanitize_text_field( $data['og_title'] ) : null;
		$theTerm->og_description              = ! empty( $data['og_description'] ) ? sanitize_text_field( $data['og_description'] ) : null;
		$theTerm->og_object_type              = ! empty( $data['og_object_type'] ) ? sanitize_text_field( $data['og_object_type'] ) : 'default';
		$theTerm->og_image_custom_url         = ! empty( $data['og_image_custom_url'] ) ? esc_url_raw( $data['og_image_custom_url'] ) : null;
		$theTerm->og_image_custom_fields      = ! empty( $data['og_image_custom_fields'] ) ? sanitize_text_field( $data['og_image_custom_fields'] ) : null;
		$theTerm->og_image_type               = ! empty( $data['og_image_type'] ) ? sanitize_text_field( $data['og_image_type'] ) : 'default';
		$theTerm->og_video                    = ! empty( $data['og_video'] ) ? sanitize_text_field( $data['og_video'] ) : '';
		$theTerm->og_article_section          = ! empty( $data['og_article_section'] ) ? sanitize_text_field( $data['og_article_section'] ) : null;
		$theTerm->og_article_tags             = ! empty( $data['og_article_tags'] ) ? aioseo()->helpers->sanitize( $data['og_article_tags'] ) : null;
		// Twitter Meta
		$theTerm->twitter_title               = ! empty( $data['twitter_title'] ) ? sanitize_text_field( $data['twitter_title'] ) : null;
		$theTerm->twitter_description         = ! empty( $data['twitter_description'] ) ? sanitize_text_field( $data['twitter_description'] ) : null;
		$theTerm->twitter_card                = ! empty( $data['twitter_card'] ) ? sanitize_text_field( $data['twitter_card'] ) : 'default';
		$theTerm->twitter_use_og              = isset( $data['twitter_use_og'] ) ? rest_sanitize_boolean( $data['twitter_use_og'] ) : 0;
		$theTerm->twitter_image               = ! empty( $data['twitter_image'] ) ? sanitize_text_field( $data['twitter_image'] ) : null;
		$theTerm->twitter_image_custom_url    = ! empty( $data['twitter_image_custom_url'] ) ? esc_url_raw( $data['twitter_image_custom_url'] ) : null;
		$theTerm->twitter_image_custom_fields = ! empty( $data['twitter_image_custom_fields'] ) ? sanitize_text_field( $data['twitter_image_custom_fields'] ) : null;
		$theTerm->twitter_image_type          = ! empty( $data['twitter_image_type'] ) ? sanitize_text_field( $data['twitter_image_type'] ) : 'default';
		// Miscellaneous
		$theTerm->updated                     = gmdate( 'Y-m-d H:i:s' ); // phpcs:ignore Generic.Formatting.MultipleStatementAlignment.IncorrectWarning
		$theTerm->breadcrumb_settings         = isset( $data['breadcrumb_settings']['default'] ) && false === $data['breadcrumb_settings']['default'] ? $data['breadcrumb_settings'] : null;

		// Before we determine the OG/Twitter image, we need to set the meta data cache manually because the changes haven't been saved yet.
		aioseo()->meta->metaData->bustTermCache( $theTerm->term_id, $theTerm );

		// Set the OG/Twitter image data.
		$theTerm = self::setOgTwitterImageData( $theTerm );

		if ( ! $theTerm->exists() ) {
			$theTerm->created = gmdate( 'Y-m-d H:i:s' );
		}

		return $theTerm;
	}

	/**
	 * Set the OG/Twitter image data on the term object.
	 *
	 * @since 4.1.6
	 *
	 * @param  Term $theTerm The Term object to modify.
	 * @return Term          The modified Term object.
	 */
	public static function setOgTwitterImageData( $theTerm ) {
		// Set the OG image.
		if (
			in_array( $theTerm->og_image_type, [
				'custom',
				'custom_image'
			], true )
		) {
			// Disable the cache.
			aioseo()->social->image->useCache = false;

			// Set the image details.
			$ogImage                  = aioseo()->social->facebook->getImage();
			$theTerm->og_image_url    = is_array( $ogImage ) ? $ogImage[0] : $ogImage;
			$theTerm->og_image_width  = aioseo()->social->facebook->getImageWidth();
			$theTerm->og_image_height = aioseo()->social->facebook->getImageHeight();

			// Reset the cache property.
			aioseo()->social->image->useCache = true;
		}

		// Set the Twitter image.
		if (
			! $theTerm->twitter_use_og &&
			in_array( $theTerm->twitter_image_type, [
				'custom',
				'custom_image'
			], true )
		) {
			// Disable the cache.
			aioseo()->social->image->useCache = false;

			// Set the image details.
			$ogImage                    = aioseo()->social->twitter->getImage();
			$theTerm->twitter_image_url = is_array( $ogImage ) ? $ogImage[0] : $ogImage;

			// Reset the cache property.
			aioseo()->social->image->useCache = true;
		}

		return $theTerm;
	}

	/**
	 * Saves some of the data as term meta so that it can be used for localization.
	 *
	 * @since 4.1.5
	 *
	 * @param  int   $termId The term ID.
	 * @param  array $data   The data.
	 * @return void
	 */
	public static function updateTermMeta( $termId, $data ) {
		$keywords      = ! empty( $data['keywords'] ) ? aioseo()->helpers->jsonTagsToCommaSeparatedList( $data['keywords'] ) : [];
		$ogArticleTags = ! empty( $data['og_article_tags'] ) ? aioseo()->helpers->jsonTagsToCommaSeparatedList( $data['og_article_tags'] ) : [];

		update_term_meta( $termId, '_aioseo_title', $data['title'] );
		update_term_meta( $termId, '_aioseo_description', $data['description'] );
		update_term_meta( $termId, '_aioseo_keywords', $keywords );
		update_term_meta( $termId, '_aioseo_og_title', $data['og_title'] );
		update_term_meta( $termId, '_aioseo_og_description', $data['og_description'] );
		update_term_meta( $termId, '_aioseo_og_article_section', $data['og_article_section'] );
		update_term_meta( $termId, '_aioseo_og_article_tags', $ogArticleTags );
		update_term_meta( $termId, '_aioseo_twitter_title', $data['twitter_title'] );
		update_term_meta( $termId, '_aioseo_twitter_description', $data['twitter_description'] );
	}

	/**
	 * Returns the default breadcrumb settings options.
	 *
	 * @since 4.8.3
	 *
	 * @param  string  $taxonomy        The taxonomy name.
	 * @param  array   $existingOptions The existing options.
	 * @return object                   The default options.
	 */
	public static function getDefaultBreadcrumbSettingsOptions( $taxonomy, $existingOptions = [] ) {
		$default       = aioseo()->dynamicOptions->breadcrumbs->taxonomies->$taxonomy->useDefaultTemplate;
		$showHomeCrumb = $default ? aioseo()->options->breadcrumbs->homepageLink : aioseo()->dynamicOptions->breadcrumbs->taxonomies->$taxonomy->showHomeCrumb;

		$defaults = [
			'default'            => true,
			'separator'          => aioseo()->options->breadcrumbs->separator,
			'breadcrumbPrefix'   => aioseo()->options->breadcrumbs->breadcrumbPrefix ?? false,
			'showHomeCrumb'      => $showHomeCrumb,
			'showTaxonomyCrumbs' => aioseo()->dynamicOptions->breadcrumbs->taxonomies->$taxonomy->showTaxonomyCrumbs ?? false,
			'showParentCrumbs'   => aioseo()->dynamicOptions->breadcrumbs->taxonomies->$taxonomy->showParentCrumbs ?? false,
			'template'           => aioseo()->helpers->encodeOutputHtml( aioseo()->breadcrumbs->frontend->getDefaultTemplate( 'taxonomy' ) ),
			'parentTemplate'     => aioseo()->helpers->encodeOutputHtml( aioseo()->breadcrumbs->frontend->getDefaultTemplate( 'taxonomy' ) )
		];

		if ( empty( $existingOptions ) ) {
			return json_decode( wp_json_encode( $defaults ) );
		}

		$existingOptions = json_decode( wp_json_encode( $existingOptions ), true );
		$existingOptions = array_replace_recursive( $defaults, $existingOptions );

		return json_decode( wp_json_encode( $existingOptions ) );
	}
}