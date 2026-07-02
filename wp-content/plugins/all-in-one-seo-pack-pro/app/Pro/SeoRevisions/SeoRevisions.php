<?php
namespace AIOSEO\Plugin\Pro\SeoRevisions;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Common\SeoRevisions as CommonSeoRevisions;
use AIOSEO\Plugin\Pro\Models as ProModels;

/**
 * SEO Revisions container class.
 *
 * @since 4.4.0
 */
class SeoRevisions extends CommonSeoRevisions\SeoRevisions {
	/**
	 * The Helpers class instance.
	 *
	 * @since 4.4.0
	 *
	 * @var null|Helpers
	 */
	public $helpers = null;

	/**
	 * All SEO Revisions related options.
	 *
	 * @since 4.4.0
	 *
	 * @var array
	 */
	public $options = [];

	/**
	 * Class constructor.
	 *
	 * @since 4.4.0
	 *
	 * @return void
	 */
	public function __construct() {
		$this->setHelpers();
		$this->setOptions();
		$this->initHooks();
	}

	/**
	 * Fires immediately after a post is deleted from the database.
	 * Hooked into `deleted_post` action hook.
	 *
	 * @since 4.4.0
	 *
	 * @param  int  $postId Post ID.
	 * @return void
	 */
	public function deletedPostCallback( $postId ) {
		$objectRevisions = new ObjectRevisions( $postId );
		$objectRevisions->deleteRevisions();
	}

	/**
	 * Fires after a term is deleted from the database.
	 * Hooked into `delete_term` action hook.
	 *
	 * @since 4.4.0
	 *
	 * @param  int  $termId Term ID.
	 * @return void
	 */
	public function deleteTermCallback( $termId ) {
		$objectRevisions = new ObjectRevisions( $termId, 'term' );
		$objectRevisions->deleteRevisions();
	}

	/**
	 * Fires once an AIOSEO post has been saved.
	 * Hooked into `aioseo_insert_post` action hook.
	 *
	 * @since 4.4.0
	 *
	 * @param  int  $postId Post ID.
	 * @return void
	 */
	public function aioseoInsertPostCallback( $postId ) {
		// Bail if this callback is not being carried under certain action hooks or the request was not to the REST API,
		// and prevent unintended revisions from being created.
		if (
			! doing_action( 'add_attachment' ) &&
			! doing_action( 'edit_attachment' ) &&
			! doing_action( 'save_post' ) &&
			! aioseo()->helpers->isRestApiRequest()
		) {
			return;
		}

		$wpPost = get_post( $postId );
		if ( ! is_a( $wpPost, 'WP_Post' ) ) {
			return;
		}

		$this->maybeAddRevision( $wpPost->ID );
	}

	/**
	 * Fires once an AIOSEO term has been saved.
	 * Hooked into `aioseo_insert_term` action hook.
	 *
	 * @since 4.4.0
	 *
	 * @param  int  $termId Term ID.
	 * @return void
	 */
	public function aioseoInsertTermCallback( $termId ) {
		// Bail if this callback is not being carried under the `edit_term` action hook or the request was not to the REST API and prevent unintended revisions from being created.
		if (
			! doing_action( 'edit_term' ) &&
			! aioseo()->helpers->isRestApiRequest()
		) {
			return;
		}

		$wpTerm = get_term( $termId );
		if ( ! is_a( $wpTerm, 'WP_Term' ) ) {
			return;
		}

		$this->maybeAddRevision( $wpTerm->term_id, 'term' );
	}

	/**
	 * Retrieve the per-object database revisions limit (license level based).
	 *
	 * @since 4.4.0
	 *
	 * @return int The amount of revisions allowed for the activated license.
	 */
	public function getLicenseRevisionsLimit() {
		if ( ! aioseo()->license->hasCoreFeature( 'seo-revisions', 'revisions' ) ) {
			return 0;
		}

		$limit = (int) aioseo()->license->getCoreFeatureValue( 'seo-revisions', 'revisions' );
		if ( ! $limit ) {
			$limit = 0;
		}

		return $limit;
	}

	/**
	 * Returns the data for Vue.
	 *
	 * @since   4.4.0
	 * @version 4.8.3 Added params `$objectId` and `$objectType`.
	 *
	 * @param  int|null $objectId   The object ID.
	 * @param  string   $objectType The object type (post or term). Default: 'post'.
	 * @return array                The data.
	 */
	public function getVueDataEdit( $objectId = null, $objectType = 'post' ) {
		$objectId = $objectId ?: absint( get_the_ID() );

		if ( ! empty( $_GET['tag_ID'] ) ) { // phpcs:ignore HM.Security.NonceVerification.Recommended
			$objectId   = absint( $_GET['tag_ID'] ); // phpcs:ignore HM.Security.NonceVerification.Recommended
			$objectType = 'term';
		}

		$objectRevisions = new ObjectRevisions( $objectId, $objectType );

		return [
			'currentUser'     => $this->getVueDataCurrentUserMeta(),
			'items'           => $objectRevisions->getFormattedRevisions( [ 'limit' => aioseo()->seoRevisions->options['revisions']['per_page'] ] ),
			'itemsLimit'      => $this->getLicenseRevisionsLimit(),
			'itemsTotalCount' => $objectRevisions->getCount(),
			'noteMaxlength'   => aioseo()->seoRevisions->options['note']['maxlength']
		];
	}

	/**
	 * Returns the data for Vue.
	 *
	 * @since 4.4.0
	 *
	 * @return array The data.
	 */
	public function getVueDataCompare() {
		$data = [
			'currentUser' => $this->getVueDataCurrentUserMeta(),
			'error'       => ''
		];

		$itemToId = ! empty( $_GET['to'] ) ? absint( $_GET['to'] ) : 0; // phpcs:ignore HM.Security.NonceVerification.Recommended
		$itemTo   = new ProModels\SeoRevision( $itemToId );
		if ( ! $itemTo->exists() ) {
			// Translators: 1 - The requested revision ID.
			$data['error'] = sprintf( esc_html__( 'The revision %1$d does not exist.', 'aioseo-pro' ), $itemToId );

			return $data;
		}

		$itemFromId = ! empty( $_GET['from'] ) ? absint( $_GET['from'] ) : 0;  // phpcs:ignore HM.Security.NonceVerification.Recommended
		if ( $itemFromId ) {
			$itemFrom = new ProModels\SeoRevision( $itemFromId );

			if ( ! $itemFrom->exists() ) {
				// Translators: 1 - The requested revision ID.
				$data['error'] = sprintf( esc_html__( 'The revision %1$d does not exist.', 'aioseo-pro' ), $itemFromId );

				return $data;
			}
		}

		if ( $itemFromId >= $itemToId ) {
			// Translators: 1 - The requested revision ID (old), 2 - The requested revision ID (new).
			$data['error'] = sprintf( esc_html__( 'The revision %1$d is greater than or equal to the revision %2$d.', 'aioseo-pro' ), $itemFromId, $itemToId );

			return $data;
		}

		$objectRevisions = new ObjectRevisions( $itemTo->object_id, $itemTo->object_type );
		$itemFrom        = $itemFrom ?? $objectRevisions->getPreviousRevision( $itemTo->id );

		return array_merge( $data, [
			'items'         => $objectRevisions->getFormattedRevisions(),
			'itemFrom'      => $itemFrom->exists() ? $itemFrom->formatRevision() : null,
			'itemTo'        => $itemTo->formatRevision(),
			'itemContext'   => ! empty( $_GET['context'] ) ? sanitize_text_field( wp_unslash( $_GET['context'] ) ) : '', // phpcs:ignore HM.Security.NonceVerification.Recommended
			'noteMaxlength' => aioseo()->seoRevisions->options['note']['maxlength']
		] );
	}

	/**
	 * Maybe create a new revision.
	 *
	 * @since 4.4.0
	 *
	 * @param  int    $objectId   The object ID.
	 * @param  string $objectType The object type (post or term). Default: 'post'.
	 * @return void
	 */
	private function maybeAddRevision( $objectId, $objectType = 'post' ) {
		$objectRevisions = new ObjectRevisions( $objectId, $objectType );
		$aioseoObject    = $objectRevisions->getAioseoObject();
		if ( ! is_object( $aioseoObject ) ) {
			return;
		}

		$newRevisionData = [];
		foreach ( aioseo()->seoRevisions->getEligibleFields() as $key => $label ) {
			if ( ! property_exists( $aioseoObject, $key ) ) {
				continue;
			}

			$newRevisionData[ $key ] = $aioseoObject->$key;
		}

		$this->helpers->parseNewRevisionData( $newRevisionData );

		if (
			empty( $newRevisionData ) ||
			! $objectRevisions->canAddRevision( $newRevisionData )
		) {
			return;
		}

		$currentUserId = get_current_user_id();

		// If this creation was triggered by e.g. WP CLI a user ID might not have been set. In this case we bail.
		if ( ! $currentUserId ) {
			return;
		}

		$objectRevisions->addRevision( $newRevisionData, $currentUserId );
	}

	/**
	 * Register SEO Revisions related hooks.
	 *
	 * @since 4.4.0
	 *
	 * @return void
	 */
	private function initHooks() {
		add_action( 'deleted_post', [ $this, 'deletedPostCallback' ] );
		add_action( 'delete_term', [ $this, 'deleteTermCallback' ] );

		if ( ! aioseo()->license->hasCoreFeature( 'seo-revisions' ) ) {
			return;
		}

		add_action( 'aioseo_insert_post', [ $this, 'aioseoInsertPostCallback' ] );
		add_action( 'aioseo_insert_term', [ $this, 'aioseoInsertTermCallback' ] );
	}

	/**
	 * Set {@see self::$helpers}.
	 *
	 * @since 4.4.0
	 *
	 * @return void
	 */
	private function setHelpers() {
		$this->helpers = new Helpers();
	}

	/**
	 * Retrieves which post/term AIOSEO fields are to be watched and saved in the database.
	 *
	 * @since   4.4.0
	 * @version 4.7.7 Renamed from `setEligibleFields` to `getEligibleFields`.
	 *
	 * @return array The eligible fields.
	 */
	public function getEligibleFields() {
		$lateFields = $this->getLateEligibleFields();

		// Keys with no value serve to compare data later and make sure the column changed (and they have "special" columns under the comparison page).
		return [
			'title'                    => __( 'SEO Title', 'aioseo-pro' ),
			'description'              => __( 'SEO Description', 'aioseo-pro' ),
			'keyphrases'               => '',
			// 'focus' and 'additional' aren't AIOSEO DB columns.
			'focus'                    => __( 'Focus Keyword', 'aioseo-pro' ),
			'additional'               => __( 'Additional Keywords', 'aioseo-pro' ),
			'og_title'                 => __( 'Facebook Title', 'aioseo-pro' ),
			'og_description'           => __( 'Facebook Description', 'aioseo-pro' ),
			'og_object_type'           => __( 'Facebook Object Type', 'aioseo-pro' ),
			'og_video'                 => __( 'Facebook Video URL', 'aioseo-pro' ),
			'og_image_type'            => __( 'Facebook Image Source', 'aioseo-pro' ),
			'og_image_custom_url'      => __( 'Facebook Custom Image URL', 'aioseo-pro' ),
			'og_article_section'       => __( 'Facebook Article Section', 'aioseo-pro' ),
			'og_article_tags'          => __( 'Facebook Article Tags', 'aioseo-pro' ),
			'twitter_use_og'           => __( 'Use Data from Facebook Tab', 'aioseo-pro' ),
			'twitter_card'             => __( 'Twitter Card Type', 'aioseo-pro' ),
			'twitter_image_type'       => __( 'Twitter Image Source', 'aioseo-pro' ),
			'twitter_image_custom_url' => __( 'Twitter Custom Image URL', 'aioseo-pro' ),
			'twitter_title'            => __( 'Twitter Title', 'aioseo-pro' ),
			'twitter_description'      => __( 'Twitter Description', 'aioseo-pro' ),
			'canonical_url'            => __( 'Canonical URL', 'aioseo-pro' ),
			'schema'                   => __( 'Schema In Use', 'aioseo-pro' ),
			'pillar_content'           => $lateFields['pillar_content'],
			'keywords'                 => $lateFields['keywords'],
			'primary_term'             => $lateFields['primary_term'],
			'breadcrumb_settings'      => $lateFields['breadcrumb_settings'],
			'robots_default'           => '',
			'robots_noindex'           => '',
			'robots_noarchive'         => '',
			'robots_nosnippet'         => '',
			'robots_nofollow'          => '',
			'robots_noimageindex'      => '',
			'robots_noodp'             => '',
			'robots_notranslate'       => '',
			'robots_max_snippet'       => '',
			'robots_max_videopreview'  => '',
			'robots_max_imagepreview'  => '',
			// 'robots_all_settings' isn't an AIOSEO DB column.
			'robots_all_settings'      => __( 'Robots Setting', 'aioseo-pro' ),
			'priority'                 => __( 'Priority Score (value)', 'aioseo-pro' ),
			'frequency'                => __( 'Priority Score (frequency)', 'aioseo-pro' ),
		];
	}

	/**
	 * Retrieves which post/term fields started being watched after this feature was introduced.
	 *
	 * @since   4.5.3
	 * @version 4.7.7 Renamed from `setLateEligibleFields` to `getLateEligibleFields`.
	 *
	 * @return array The late eligible fields.
	 */
	public function getLateEligibleFields() {
		return [
			'pillar_content'      => __( 'Cornerstone Content', 'aioseo-pro' ),
			'keywords'            => __( 'Keywords', 'aioseo-pro' ),
			'primary_term'        => __( 'Primary Term(s)', 'aioseo-pro' ),
			'breadcrumb_settings' => __( 'Breadcrumbs', 'aioseo-pro' ),
		];
	}

	/**
	 * Set {@see self::$options}.
	 * Ideally set options only for Vue usage on the front-end.
	 *
	 * @since 4.4.0
	 *
	 * @return void
	 */
	private function setOptions() {
		$this->options = [
			'note'      => [
				'maxlength' => 90 // Max char length.
			],
			'revisions' => [
				'per_page' => 10 // Amount of items/rows shown when editing an object.
			]
		];
	}
}