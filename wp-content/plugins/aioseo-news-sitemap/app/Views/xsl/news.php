<?php
/**
 * XSL stylesheet for the News Sitemap.
 *
 * @since 4.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable
?>
<xsl:stylesheet
	version="2.0"
	xmlns:html="http://www.w3.org/TR/html40"
	xmlns:sitemap="http://www.sitemaps.org/schemas/sitemap/0.9"
	xmlns:news="http://www.google.com/schemas/sitemap-news/0.9"
	xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
>
	<xsl:output method="html" version="1.0" encoding="UTF-8" indent="yes"/>

	<xsl:template match="/">
		<html xmlns="http://www.w3.org/1999/xhtml">
			<head>
				<title><?php _e( 'News Sitemap', 'aioseo-news-sitemap' ); ?></title>
				<meta name="viewport" content="width=device-width, initial-scale=1" />
				<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
				<?php aioseo()->templates->getTemplate( 'sitemap/xsl/styles.php' ); ?>
			</head>
			<body>
			<xsl:variable name="amountOfURLs">
					<xsl:value-of select="count(sitemap:urlset/sitemap:url)"/>
				</xsl:variable>
				<xsl:variable name="fileType">Sitemap</xsl:variable>

				<xsl:call-template name="Header">
					<xsl:with-param name="title"><?php _e( 'News Sitemap', 'aioseo-news-sitemap' ); ?></xsl:with-param>
					<xsl:with-param name="amountOfURLs" select="$amountOfURLs"/>
					<xsl:with-param name="fileType" select="$fileType"/>
				</xsl:call-template>

				<div class="content">
					<div class="container">
						<xsl:choose>
							<xsl:when test="$amountOfURLs = 0"><xsl:call-template name="emptySitemap"/></xsl:when>
							<xsl:otherwise><xsl:call-template name="sitemapTable"/></xsl:otherwise>
						</xsl:choose>
					</div>
				</div>
			</body>
		</html>
	</xsl:template>

	<xsl:template name="sitemapTable">
		<?php
			aioseo()->templates->getTemplate(
				'sitemap/xsl/partials/breadcrumb.php',
				[
					'items' => [
						[ 'title' => __( 'News Sitemap', 'aioseo-news-sitemap' ), 'url' => $sitemapUrl ]
					]
				]
			);
		?>
		<div class="table-wrapper">
			<table cellpadding="3">
				<thead>
					<tr>
						<th class="left"><?php _e( 'Title', 'aioseo-news-sitemap' ); ?></th>
						<th class="left">
							<?php _e( 'URL', 'aioseo-news-sitemap' ); ?>
						</th>
						<th>
							<?php
								aioseo()->templates->getTemplate(
									'sitemap/xsl/partials/sortable-column.php',
									[
										'parameters' => $parameters,
										'sitemapUrl' => $sitemapUrl,
										'column'     => 'date',
										'title'      => __( 'Publish Date', 'aioseo-news-sitemap' )
									]
								);
							?>
						</th>
					</tr>
				</thead>
				<tbody>
				<xsl:for-each select="sitemap:urlset/sitemap:url">
					<?php
						aioseo()->templates->getTemplate(
							'sitemap/xsl/partials/xsl-sort.php',
							[
								'parameters' => $parameters,
								'node'       => 'news:news/news:publication_date',
							]
						);
					?>
					<tr>
						<xsl:if test="position() mod 2 != 1">
							<xsl:attribute name="class">stripe</xsl:attribute>
						</xsl:if>
						<td class="left">
							<xsl:value-of select="news:news/news:title" disable-output-escaping="yes"/>
						</td>
						<td class="left">
							<a>
								<xsl:attribute name="href">
									<xsl:value-of select="sitemap:loc" />
								</xsl:attribute>
								<xsl:value-of select="sitemap:loc"/>
							</a>
						</td>
						<td class="datetime">
							<?php 
							if ( ! empty( $xslParams['datetime'] ) ) {
								aioseo()->templates->getTemplate(
									'sitemap/xsl/partials/date-time.php',
									[
										'datetime' => $xslParams['datetime'],
										'node'     => 'sitemap:loc'
									]
								);
							}
							?>
						</td>
					</tr>
				</xsl:for-each>
				</tbody>
			</table>
		</div>
		<?php
		if ( ! empty( $xslParams['pagination'] ) ) {
			aioseo()->templates->getTemplate(
				'sitemap/xsl/partials/pagination.php',
				[ 
					'sitemapUrl'    => $sitemapUrl,
					'linksPerIndex' => $linksPerIndex,
					'total'         => $xslParams['pagination']['total'],
					'showing'       => $xslParams['pagination']['showing']
				]
			);
		}
		?>
	</xsl:template>

	<?php aioseo()->templates->getTemplate( 'sitemap/xsl/templates/header.php', [ 'utmMedium' => 'news-sitemap' ] ); ?>
	<?php
	aioseo()->templates->getTemplate( 'sitemap/xsl/templates/empty-sitemap.php', [
		'utmMedium' => 'news-sitemap',
		'items'     => [
			[ 'title' => __( 'News Sitemap', 'aioseo-news-sitemap' ), 'url' => $sitemapUrl ]
		]
	] );
	?>
</xsl:stylesheet>