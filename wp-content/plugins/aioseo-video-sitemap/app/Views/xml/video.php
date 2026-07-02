<?php
/**
 * XML template for our video sitemap index pages.
 *
 * @since 4.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable
?>
<urlset
	xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
	xmlns:video="http://www.google.com/schemas/sitemap-video/1.1"
>
<?php foreach ( $entries as $entry ) {
	if ( empty( $entry['loc'] || empty( $entry['videos'] ) ) ) {
		continue;
	}
	?>
	<url>
		<loc><?php aioseo()->sitemap->output->escapeAndEcho( $entry['loc'] ); ?></loc><?php
	if ( ! empty( $entry['lastmod'] ) ) {
			?>

		<lastmod><?php aioseo()->sitemap->output->escapeAndEcho( $entry['lastmod'] ); ?></lastmod><?php
		}
		foreach ( $entry['videos'] as $video ) {
			?>

		<video:video>
			<video:title><?php aioseo()->sitemap->output->escapeAndEcho( $video->title ); ?></video:title>
			<video:description><?php aioseo()->sitemap->output->escapeAndEcho( aioseo()->helpers->substring( $video->description, 0, 2048 ) ); ?></video:description>
			<video:thumbnail_loc><?php aioseo()->sitemap->output->escapeAndEcho( $video->thumbnailLoc ); ?></video:thumbnail_loc>
			<video:player_loc><?php aioseo()->sitemap->output->escapeAndEcho( $video->playerLoc ); ?></video:player_loc><?php

			if ( ! empty( $video->contentLoc ) ) {
			?>

			<video:content_loc><?php aioseo()->sitemap->output->escapeAndEcho( $video->contentLoc ); ?></video:content_loc><?php
			}

			if ( ! empty( $video->duration ) ) {
			?>

			<video:duration><?php aioseo()->sitemap->output->escapeAndEcho( $video->duration ); ?></video:duration><?php
			}

			if ( ! empty( $video->publicationDate ) ) {
			?>
	
			<video:publication_date><?php aioseo()->sitemap->output->escapeAndEcho( $video->publicationDate ); ?></video:publication_date><?php
			}

			if ( ! empty( $video->uploader ) ) {
			?>
	
			<video:uploader><?php aioseo()->sitemap->output->escapeAndEcho( aioseo()->helpers->substring( $video->uploader, 0, 255 ) ); ?></video:uploader><?php
			}
			?>

		</video:video><?php
		}
	?>

	</url>
<?php } ?>
</urlset>