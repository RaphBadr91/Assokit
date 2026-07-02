<?php
/**
 * XML template for the News Sitemap.
 *
 * @since 4.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">
<?php foreach ( $entries as $entry ) {
	if ( ! is_array( $entry ) ) {
		continue;
	}
	?>
	<url>
		<loc><?php aioseo()->sitemap->output->escapeAndEcho( $entry['loc'] ); ?></loc>
		<news:news>
			<news:publication>
				<news:name><?php aioseo()->sitemap->output->escapeAndEcho( $entry['publication']['name'] ); ?></news:name>
				<news:language><?php aioseo()->sitemap->output->escapeAndEcho( $entry['publication']['language'] ); ?></news:language>
			</news:publication>
			<news:publication_date><?php aioseo()->sitemap->output->escapeAndEcho( $entry['publicationDate'] ); ?></news:publication_date>
			<news:title><?php aioseo()->sitemap->output->escapeAndEcho( $entry['title'] ); ?></news:title><?php
			if ( array_key_exists( 'genres', $entry ) && $entry['genres'] ) {
				?>
		
			<news:genres><?php aioseo()->sitemap->output->escapeAndEcho( $entry['genres'] ); ?></news:genres><?php
			}

			if ( array_key_exists( 'stockTicker', $entry ) && $entry['stockTicker'] ) {
				?>
		
			<news:stock_picker><?php aioseo()->sitemap->output->escapeAndEcho( $entry['stockTicker'] ); ?></news:stock_picker><?php
			}?>

		</news:news>
	</url>
<?php } ?>
</urlset>