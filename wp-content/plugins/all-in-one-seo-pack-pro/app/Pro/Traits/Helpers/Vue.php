<?php
namespace AIOSEO\Plugin\Pro\Traits\Helpers;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\Plugin\Pro\Models;
use AIOSEO\Plugin\Common\Models as CommonModels;

/**
 * Contains all Vue related helper methods.
 *
 * @since 4.1.4
 */
trait Vue {
	/**
	 * Returns the data for Vue.
	 *
	 * @since   4.0.0
	 * @version 4.4.9
	 *
	 * @param  string $page         The current page.
	 * @param  int    $staticPostId Data for a specific post.
	 * @param  string $integration  Data for integration (builder).
	 * @return array                The data.
	 */
	public function getVueData( $page = null, $staticPostId = null, $integration = null ) {
		$this->args = compact( 'page', 'staticPostId', 'integration' );
		$hash       = md5( implode( '', array_map( 'strval', $this->args ) ) );
		if ( isset( $this->cache[ $hash ] ) ) {
			return $this->cache[ $hash ];
		}

		$this->data = parent::getVueData( $page, $staticPostId, $integration );

		$this->setInitialData();
		$this->setLicenseData();
		$this->setTermData();
		$this->setSearchStatisticsData();
		$this->setSeoRevisionsData();
		$this->setPostData();
		$this->setProductData();
		$this->setToolsOrSettingsData();
		$this->maybeCheckForPluginUpdates();
		$this->setBreadcrumbsData();

		$this->data = aioseo()->addons->getVueData( $this->data, $page );

		$this->cleanSensitiveData();

		$this->cache[ $hash ] = $this->data;

		return $this->cache[ $hash ];
	}

	/**
	 * Set Vue initial data.
	 *
	 * @since 4.4.9
	 *
	 * @return void
	 */
	private function setInitialData() {
		$videoSitemapFilename = aioseo()->sitemap->helpers->filename( 'video' );
		$videoSitemapFilename = $videoSitemapFilename ?: 'video-sitemap';
		$newsIndex            = apply_filters( 'aioseo_news_sitemap_index_name', 'news' );

		$this->data['data']['isMemberMouseCoursesActive'] = aioseo()->helpers->isMemberMouseCoursesActive();
		$this->data['data']['isMemberPressCoursesActive'] = aioseo()->helpers->isMemberPressCoursesActive();
		$this->data['data']['isWishListCourseCureActive'] = aioseo()->helpers->isWishListCourseCureActive();

		$this->data['urls']['videoSitemapUrl'] = home_url( "/$videoSitemapFilename.xml" );
		$this->data['urls']['newsSitemapUrl']  = home_url( "/$newsIndex-sitemap.xml" );
		$this->data['urls']['aiGeneratorUrl']  = aioseo()->ai->getAiGeneratorApiUrl();
		$this->data['urls']['llmsFullUrl']     = aioseo()->helpers->getLlmsUrl( true );

		$this->data['translationsPro'] = $this->getJedLocaleData( 'aioseo-pro' );
	}

	/**
	 * Set Vue license data.
	 *
	 * @since 4.4.9
	 *
	 * @return void
	 */
	private function setLicenseData() {
		$license         = is_network_admin() ? aioseo()->networkLicense : aioseo()->license;
		$internalOptions = is_network_admin() ? aioseo()->internalNetworkOptions : aioseo()->internalOptions;

		$this->data['license'] = [
			'isActive'   => $license->isActive(),
			'isExpired'  => $license->isExpired(),
			'isDisabled' => $license->isDisabled(),
			'isInvalid'  => $license->isInvalid(),
			'expires'    => $internalOptions->internal->license->expires
		];

		// Check if this site is network licensed.
		$this->data['data']['isNetworkLicensed'] = aioseo()->license->isNetworkLicensed();
	}

	/**
	 * Set Vue term data.
	 *
	 * @since 4.4.9
	 *
	 * @return void
	 */
	private function setTermData() {
		if ( ! aioseo()->helpers->isScreenBase( 'term' ) ) {
			return;
		}

		// phpcs:disable  HM.Security.ValidatedSanitizedInput.InputNotSanitized, HM.Security.NonceVerification.Recommended
		$termId = isset( $_GET['tag_ID'] ) ? absint( wp_unslash( $_GET['tag_ID'] ) ) : 0;
		// phpcs:enable

		$aioseoTerm = Models\Term::getTerm( $termId );
		$term       = aioseo()->helpers->getTerm( $termId );
		$screen     = aioseo()->helpers->getCurrentScreen();

		$this->data['currentPost'] = [
			'context'                     => 'term',
			'tags'                        => aioseo()->tags->getDefaultTermTags( $termId ),
			'id'                          => $termId,
			'priority'                    => isset( $aioseoTerm->priority ) && null !== $aioseoTerm->priority ? (float) $aioseoTerm->priority : 'default',
			'frequency'                   => ! empty( $aioseoTerm->frequency ) ? $aioseoTerm->frequency : 'default',
			'permalink'                   => get_term_link( $termId ),
			'title'                       => ! empty( $aioseoTerm->title ) ? $aioseoTerm->title : aioseo()->meta->title->getTaxonomyTitle( $term->taxonomy ),
			'description'                 => ! empty( $aioseoTerm->description ) ? $aioseoTerm->description : aioseo()->meta->description->getTaxonomyDescription( $term->taxonomy ),
			'keywords'                    => ! empty( $aioseoTerm->keywords ) ? $aioseoTerm->keywords : [],
			'type'                        => get_taxonomy( $screen->taxonomy )->labels->singular_name,
			'termType'                    => 'type' === $term->taxonomy ? '_aioseo_type' : $term->taxonomy,
			'canonicalUrl'                => $aioseoTerm->canonical_url,
			'default'                     => ( (int) $aioseoTerm->robots_default ) === 0 ? false : true,
			'noindex'                     => ( (int) $aioseoTerm->robots_noindex ) === 0 ? false : true,
			'noarchive'                   => ( (int) $aioseoTerm->robots_noarchive ) === 0 ? false : true,
			'nosnippet'                   => ( (int) $aioseoTerm->robots_nosnippet ) === 0 ? false : true,
			'nofollow'                    => ( (int) $aioseoTerm->robots_nofollow ) === 0 ? false : true,
			'noimageindex'                => ( (int) $aioseoTerm->robots_noimageindex ) === 0 ? false : true,
			'noodp'                       => ( (int) $aioseoTerm->robots_noodp ) === 0 ? false : true,
			'notranslate'                 => ( (int) $aioseoTerm->robots_notranslate ) === 0 ? false : true,
			'maxSnippet'                  => null === $aioseoTerm->robots_max_snippet ? -1 : (int) $aioseoTerm->robots_max_snippet,
			'maxVideoPreview'             => null === $aioseoTerm->robots_max_videopreview ? -1 : (int) $aioseoTerm->robots_max_videopreview,
			'maxImagePreview'             => $aioseoTerm->robots_max_imagepreview,
			'modalOpen'                   => false,
			'generalMobilePrev'           => false,
			'og_object_type'              => ! empty( $aioseoTerm->og_object_type ) ? $aioseoTerm->og_object_type : 'default',
			'og_title'                    => $aioseoTerm->og_title,
			'og_description'              => $aioseoTerm->og_description,
			'og_image_custom_url'         => $aioseoTerm->og_image_custom_url,
			'og_image_custom_fields'      => $aioseoTerm->og_image_custom_fields,
			'og_image_type'               => ! empty( $aioseoTerm->og_image_type ) ? $aioseoTerm->og_image_type : 'default',
			'og_video'                    => ! empty( $aioseoTerm->og_video ) ? $aioseoTerm->og_video : '',
			'og_article_section'          => ! empty( $aioseoTerm->og_article_section ) ? $aioseoTerm->og_article_section : '',
			'og_article_tags'             => ! empty( $aioseoTerm->og_article_tags ) ? $aioseoTerm->og_article_tags : [],
			'twitter_use_og'              => ( (int) $aioseoTerm->twitter_use_og ) === 0 ? false : true,
			'twitter_card'                => $aioseoTerm->twitter_card,
			'twitter_image_custom_url'    => $aioseoTerm->twitter_image_custom_url,
			'twitter_image_custom_fields' => $aioseoTerm->twitter_image_custom_fields,
			'twitter_image_type'          => $aioseoTerm->twitter_image_type,
			'twitter_title'               => $aioseoTerm->twitter_title,
			'twitter_description'         => $aioseoTerm->twitter_description,
			'redirects'                   => [
				'modalOpen' => false
			]
		];

		$this->data['currentPost']['breadcrumb_settings'] = Models\Term::getDefaultBreadcrumbSettingsOptions( $term->taxonomy, $aioseoTerm->breadcrumb_settings ?? [] );

		if ( ! $aioseoTerm->exists() ) {
			$this->data['currentPost'] = array_merge(
				$this->data['currentPost'],
				aioseo()->migration->meta->getMigratedTermMeta( $termId )
			);
		}
	}

	/**
	 * Set Vue search statistics data.
	 *
	 * @since 4.7.0
	 *
	 * @return void
	 */
	private function setSearchStatisticsData() {
		if (
			'post' === $this->args['page'] &&
			empty( array_filter( aioseo()->searchStatistics->stats->getDateRange() ) )
		) {
			aioseo()->searchStatistics->stats->setDefaultDateRange();
		}

		$this->data['searchStatistics'] = array_merge( $this->data['searchStatistics'], [ 'range' => aioseo()->searchStatistics->stats->getDateRange() ] );
	}

	/**
	 * Set Vue seo revisions data.
	 *
	 * @since 4.4.9
	 *
	 * @return void
	 */
	private function setSeoRevisionsData() {
		if ( aioseo()->helpers->isScreenBase( 'term' ) ) {
			$this->data['seoRevisions'] = aioseo()->seoRevisions->getVueDataEdit();
		}
	}

	/**
	 * Set Vue post data.
	 *
	 * @since 4.4.9
	 *
	 * @return void
	 */
	private function setPostData() {
		if ( 'post' !== $this->args['page'] ) {
			return;
		}

		$postId     = $this->args['staticPostId'] ?: get_the_ID();
		$aioseoPost = CommonModels\Post::getPost( $postId );
		$wpPost     = get_post( $postId );
		if ( is_object( $wpPost ) ) {
			$this->data['currentPost']['defaultSchemaType']  = '';
			$this->data['currentPost']['defaultWebPageType'] = '';

			$dynamicOptions = aioseo()->dynamicOptions->noConflict();
			if ( $dynamicOptions->searchAppearance->postTypes->has( $wpPost->post_type ) ) {
				$this->data['currentPost']['defaultSchemaType']  = $dynamicOptions->searchAppearance->postTypes->{$wpPost->post_type}->schemaType;
				$this->data['currentPost']['defaultWebPageType'] = $dynamicOptions->searchAppearance->postTypes->{$wpPost->post_type}->webPageType;
			}
		}

		$clonedSchema = json_decode( wp_json_encode( $this->data['currentPost']['schema'] ) );

		$this->data['schema']['output'] = aioseo()->schema->getValidatorOutput(
			$postId,
			$clonedSchema->graphs,
			$clonedSchema->blockGraphs,
			$clonedSchema->default,
			$clonedSchema->blockGraphs
		);

		$this->data['currentPost']['primary_term']            = ! empty( $aioseoPost->primary_term ) ? $aioseoPost->primary_term : [];
		$this->data['currentPost']['breadcrumb_settings']     = CommonModels\Post::getDefaultBreadcrumbSettingsOptions( get_post_type( $postId ), $aioseoPost->breadcrumb_settings ?? [] );
		$this->data['currentPost']['maxAdditionalKeyphrases'] = $this->getPostAdditionalKeywordsLimit();
	}

	/**
	 * Set Vue product/download data.
	 *
	 * @since 4.4.9
	 *
	 * @return void
	 */
	private function setProductData() {
		$wpPost = $this->getPost();
		if (
			! $wpPost ||
			! in_array( $wpPost->post_type, [ 'product', 'download' ], true )
		) {
			return;
		}

		$isWooCommerceActive = $this->isWooCommerceActive();
		$isEddActive         = $this->isEddActive();
		$this->data['data']  += [
			'isWooCommerceActive' => $isWooCommerceActive,
			'isEddActive'         => $isEddActive
		];

		if ( $isWooCommerceActive ) {
			$product = wc_get_product( $wpPost->ID );
			if ( ! is_a( $product, 'WC_Product' ) ) {
				return;
			}

			$this->data['data']['wooCommerce'] = [
				'currencySymbol'                => function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$',
				'isPerfectBrandsActive'         => $this->isPerfectBrandsActive(),
				'isWooCommerceBrandsActive'     => $this->isWooCommerceBrandsActive(),
				'isWooCommerceUpcEanIsbnActive' => $this->isWooCommerceUpcEanIsbnActive(),
				'reviewsEnabled'                => $product->get_reviews_allowed()
			];
		}

		if ( $isEddActive ) {
			$this->data['data']['edd']['isEddReviewsActive'] = $this->isEddReviewsActive();
		}
	}

	/**
	 * Set Vue tools or settings data.
	 *
	 * @since 4.4.9
	 *
	 * @return void
	 */
	private function setToolsOrSettingsData() {
		if (
			'tools' !== $this->args['page'] &&
			'settings' !== $this->args['page']
		) {
			return;
		}

		if (
			is_multisite() &&
			is_network_admin() &&
			aioseo()->license->hasCoreFeature( 'tools', 'network-tools-import-export' )
		) {
			foreach ( aioseo()->helpers->getSites()['sites'] as $site ) {
				aioseo()->helpers->switchToBlog( $site->blog_id );
				$this->data['data']['network']['backups'][ $site->blog_id ] = array_reverse( aioseo()->backup->all() );
			}

			aioseo()->helpers->restoreCurrentBlog();
		}
	}

	/**
	 * We may need to force a check for plugin updates.
	 *
	 * @since 4.1.6
	 *
	 * @return void
	 */
	private function maybeCheckForPluginUpdates() {
		// If we aren't on one of the addon pages, return early.
		if ( ! in_array( $this->args['page'], [
			'feature-manager',
			'link-assistant',
			'local-seo',
			'redirects',
			'search-appearance',
			'sitemaps'
		], true ) ) {
			return;
		}

		$shouldCheckForUpdates = false;

		// Loop through all addons and see if the addon needing an update matches the current page.
		foreach ( aioseo()->addons->getAddons() as $addon ) {
			if ( $addon->hasMinimumVersion ) {
				continue;
			}

			if ( 'feature-manager' === $this->args['page'] ) {
				$shouldCheckForUpdates = true;
				continue;
			}

			if ( 'aioseo-' . $this->args['page'] === $addon->sku ) {
				$shouldCheckForUpdates = true;
				continue;
			}

			if (
				'sitemaps' === $this->args['page'] &&
				in_array( $addon->sku, [ 'aioseo-video-sitemap', 'aioseo-news-sitemap' ], true )
			) {
				$shouldCheckForUpdates = true;
			}
		}

		// We want to force checks for updates, so let's go ahead and do that now.
		if ( $shouldCheckForUpdates ) {
			delete_site_transient( 'update_plugins' );
		}
	}

	/**
	 * Clean sensitive data.
	 *
	 * @since 4.5.3
	 *
	 * @return void
	 */
	protected function cleanSensitiveData() {
		parent::cleanSensitiveData();

		$this->cleanNetworkOptions();

		// If the user is an admin, don't hide the following data.
		if ( aioseo()->access->isAdmin() ) {
			return;
		}

		if ( ! empty( $this->data['options']['general']['licenseKey'] ) ) {
			$this->data['options']['general']['licenseKey'] = '*****************';
		}
	}

	/**
	 * Clean network options.
	 *
	 * @since 4.8.4
	 *
	 * @return void
	 */
	private function cleanNetworkOptions() {
		if ( ! is_network_admin() ) {
			return;
		}

		if ( ! empty( $this->data['networkOptions']['general']['licenseKey'] ) ) {
			$this->data['networkOptions']['general']['licenseKey'] = '*****************';
		}
	}

	/**
	 * Retrieves the amount of additional keywords allowed for a post.
	 *
	 * @since 4.7.8
	 *
	 * @return int The amount of additional keywords allowed for a post.
	 */
	private function getPostAdditionalKeywordsLimit() {
		$limit = apply_filters( 'aioseo_post_additional_keywords_limit', 10 );

		// Ensure the limit is a scalar value between 1 and 100.
		return min( max( intval( $limit ), 1 ), 100 );
	}

	/**
	 * Set Vue breadcrumbs data.
	 *
	 * @since 4.8.3
	 *
	 * @return void
	 */
	private function setBreadcrumbsData() {
		$isPostOrTermPage              = aioseo()->helpers->isScreenBase( 'post' ) || aioseo()->helpers->isScreenBase( 'term' );
		$isCurrentPageUsingPageBuilder = 'post' === $this->args['page'] && ! empty( $this->args['integration'] );
		$isSettingsPage                = ! empty( $this->args['page'] ) && 'settings' === $this->args['page'];
		if ( ! $isSettingsPage && ! $isCurrentPageUsingPageBuilder && ! $isPostOrTermPage ) {
			return;
		}

		$this->data['breadcrumbs']['defaultTemplates'] = [];

		$postTypes = aioseo()->helpers->getPublicPostTypes();
		foreach ( $postTypes as $postType ) {
			if ( 'type' === $postType['name'] ) {
				$postType['name'] = '_aioseo_type';
			}

			// phpcs:ignore Generic.Files.LineLength.MaxExceeded
			$this->data['breadcrumbs']['defaultTemplates']['postTypes'][ $postType['name'] ] = aioseo()->helpers->encodeOutputHtml( aioseo()->breadcrumbs->frontend->getDefaultTemplate( 'single', $postType ) );
		}

		$taxonomies = aioseo()->helpers->getPublicTaxonomies();
		foreach ( $taxonomies as $taxonomy ) {
			if ( 'type' === $taxonomy['name'] ) {
				$taxonomy['name'] = '_aioseo_type';
			}

			// phpcs:ignore Generic.Files.LineLength.MaxExceeded
			$this->data['breadcrumbs']['defaultTemplates']['taxonomies'][ $taxonomy['name'] ] = aioseo()->helpers->encodeOutputHtml( aioseo()->breadcrumbs->frontend->getDefaultTemplate( 'taxonomy', $taxonomy ) );
		}

		$this->data['breadcrumbs']['defaultTemplates']['archives'] = [
			'blog'     => aioseo()->helpers->encodeOutputHtml( aioseo()->breadcrumbs->frontend->getDefaultTemplate( 'blog' ) ),
			'author'   => aioseo()->helpers->encodeOutputHtml( aioseo()->breadcrumbs->frontend->getDefaultTemplate( 'author' ) ),
			'search'   => aioseo()->helpers->encodeOutputHtml( aioseo()->breadcrumbs->frontend->getDefaultTemplate( 'search' ) ),
			'notFound' => aioseo()->helpers->encodeOutputHtml( aioseo()->breadcrumbs->frontend->getDefaultTemplate( 'notFound' ) ),
			'date'     => [
				'year'  => aioseo()->helpers->encodeOutputHtml( aioseo()->breadcrumbs->frontend->getDefaultTemplate( 'year' ) ),
				'month' => aioseo()->helpers->encodeOutputHtml( aioseo()->breadcrumbs->frontend->getDefaultTemplate( 'month' ) ),
				'day'   => aioseo()->helpers->encodeOutputHtml( aioseo()->breadcrumbs->frontend->getDefaultTemplate( 'day' ) )
			]
		];

		$archives = aioseo()->helpers->getPublicPostTypes( false, true, true );
		foreach ( $archives as $archive ) {
			// phpcs:ignore Generic.Files.LineLength.MaxExceeded
			$this->data['breadcrumbs']['defaultTemplates']['archives']['postTypes'][ $archive['name'] ] = aioseo()->helpers->encodeOutputHtml( aioseo()->breadcrumbs->frontend->getDefaultTemplate( 'postTypeArchive', $archive ) );
		}
	}
}