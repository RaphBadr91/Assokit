<?php
namespace AIOSEO\Plugin\Addon\VideoSitemap\VideoSitemap;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the static sitemap.
 *
 * @since 1.0.0
 */
class File {
	/**
	 * Generates the static sitemap files.
	 *
	 * @since 1.0.0
	 *
	 * @param  boolean $force Whether or not to force it through.
	 * @return void
	 */
	public function generate( $force = false ) {
		if (
			! $force &&
			(
				! aioseo()->options->sitemap->video->enable ||
				! aioseo()->options->sitemap->video->advancedSettings->enable ||
				! in_array( 'staticVideoSitemap', aioseo()->internalOptions->internal->deprecatedOptions, true ) ||
				aioseo()->options->deprecated->sitemap->video->advancedSettings->dynamic
			)
		) {
			return;
		}

		$files = [];
		// We need to set these values here as determineContext() doesn't run.
		// Subsequently, we need to manually reset the index name below for each query we run.
		// Also, since we need to chunk the entries manually, we cannot limit any queries
		// and need to reset the amount of allowed URLs per index.
		aioseo()->sitemap->type          = 'video';
		$sitemapName                     = aioseo()->sitemap->helpers->filename();
		aioseo()->sitemap->indexes       = aioseo()->options->sitemap->video->indexes;
		aioseo()->sitemap->offset        = 0;
		aioseo()->sitemap->linksPerIndex = PHP_INT_MAX;

		$postTypes = aioseo()->sitemap->helpers->includedPostTypes();
		if ( $postTypes ) {
			foreach ( $postTypes as $postType ) {
				aioseo()->sitemap->indexName = $postType;

				$posts = aioseoVideoSitemap()->content->videoPosts( $postType );
				if ( ! $posts ) {
					continue;
				}

				// We need to temporarily reset the linksPerIndex count here so that we can properly chunk.
				aioseo()->sitemap->linksPerIndex = aioseo()->options->sitemap->video->linksPerIndex;
				$chunks = aioseo()->sitemap->helpers->chunkEntries( $posts );
				aioseo()->sitemap->linksPerIndex = PHP_INT_MAX;

				if ( 1 === count( $chunks ) ) {
					$filename           = "$postType-$sitemapName.xml";
					$files[ $filename ] = $chunks[0];
				} else {
					for ( $i = 1; $i <= count( $chunks ); $i++ ) {
						$filename           = "$postType-$sitemapName$i.xml";
						$files[ $filename ] = $chunks[ $i - 1 ];
					}
				}
			}
		}

		$taxonomies = aioseo()->sitemap->helpers->includedTaxonomies();
		if ( $taxonomies ) {
			foreach ( $taxonomies as $taxonomy ) {
				aioseo()->sitemap->indexName = $taxonomy;
				$terms = aioseoVideoSitemap()->content->videoTerms( $taxonomy );
				if ( ! $terms ) {
					continue;
				}

				// We need to temporarily reset the linksPerIndex count here so that we can properly chunk.
				aioseo()->sitemap->linksPerIndex = aioseo()->options->sitemap->video->linksPerIndex;
				$chunks = aioseo()->sitemap->helpers->chunkEntries( $terms );
				aioseo()->sitemap->linksPerIndex = PHP_INT_MAX;

				if ( 1 === count( $chunks ) ) {
					$filename           = "$taxonomy-$sitemapName.xml";
					$files[ $filename ] = $chunks[0];
				} else {
					for ( $i = 1; $i <= count( $chunks ); $i++ ) {
						$filename           = "$taxonomy-$sitemapName$i.xml";
						$files[ $filename ] = $chunks[ $i - 1 ];
					}
				}
			}
		}

		aioseo()->sitemap->file->writeSitemaps( $files );
	}
}