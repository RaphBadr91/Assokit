<?php
/**
 * XSL stylesheet for the sitemap.
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
	xmlns="http://www.w3.org/1999/xhtml"
	xmlns:html="http://www.w3.org/TR/html40"
	xmlns:video="http://www.google.com/schemas/sitemap-video/1.1"
	xmlns:sitemap="http://www.sitemaps.org/schemas/sitemap/0.9"
	xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
>
	<xsl:output method="html" version="1.0" encoding="UTF-8" indent="yes"/>

	<xsl:template match="/">
	<xsl:variable name="fileType">
		<xsl:choose>
			<xsl:when test="//sitemap:url">Sitemap</xsl:when>
			<xsl:otherwise>SitemapIndex</xsl:otherwise>
		</xsl:choose>
	</xsl:variable>
	<html xmlns="http://www.w3.org/1999/xhtml">
		<head>
			<title>
				<xsl:choose>
					<xsl:when test="$fileType='Sitemap'"><?php echo $title; ?></xsl:when>
					<xsl:otherwise><?php _e( 'Video Sitemap Index', 'aioseo-video-sitemap' ); ?></xsl:otherwise>
				</xsl:choose>
			</title>
			<meta name="viewport" content="width=device-width, initial-scale=1" />
			<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
			<?php aioseo()->templates->getTemplate( 'sitemap/xsl/styles.php' ); ?>
			<?php // Styles for modal stuff and video only. ?>
			<style>
				.modal-window {
					position: fixed;
					background-color: rgba(20, 27, 56, 0.3);
					top: 0;
					right: 0;
					bottom: 0;
					left: 0;
					z-index: 999;
					visibility: hidden;
					opacity: 0;
					pointer-events: none;
					transition: all 0.3s;
				}
				.modal-window .title {
					font-weight: bold;
					font-size: 18px;
					line-height: 120%;
					color: #141B38;
					max-width: 80%;
					margin: 0 auto 10px;
				}
				.modal-window .link {
					font-weight: 600;
					font-size: 14px;
					color: #005AE0;
					text-decoration-line: underline;
				}
				.modal-window:target {
					visibility: visible;
					opacity: 1;
					pointer-events: auto;
				}
				.modal-window > div {
					width: 80%;
					max-width: 700px;
					position: absolute;
					top: 50%;
					left: 50%;
					transform: translate(-50%, -50%);
					padding: 24px;
					background: #FFFFFF;
					box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
				}
				.modal-close {
					position: absolute;
					right: 10px;
					top: 10px;
					text-decoration: none;
					padding: 15px;
					transition: opacity .2s ease-in-out;
				}
				.modal-close:hover {
					opacity: 0.7;
				}
				.videos-column {
					display: flex;
					align-items: center;
				}
				.videos-column .open-modal {
					color: #141B38;
					font-size: 13px;
				}
				.videos {
					display: flex;
					align-items: center;
				}
				.videos img {
					max-width: 48px;
					max-height: 100%;
					margin-right: 8px;
					margin-left: 0;
				}
				.modal-window .videos {
					margin-top: 32px;
					display: grid;
					grid-gap: 8px;
					grid-template-columns: repeat(3,minmax(0,1fr));
					overflow-y: auto;
					max-height: 50vh;
				}
				.modal-window .videos img {
					max-width: 100%;
					margin-right: 0;
				}

				@media (min-width: 1024px) {
					.modal-window .title {
						max-width: 100%;
					}
					.modal-close {
						right: 20px;
						top: 20px;
						padding: 20px;
					}
					.modal-window .title {
						font-size: 20px;
					}
					.modal-window .link {
						font-size: 16px;
					}
					.modal-window > div {
						padding: 40px;
					}
					.modal-window .videos {
						grid-gap: 24px;
						grid-template-columns: repeat(5,minmax(0,1fr));
						overflow-y: hidden;
    					max-height: initial;
					}
				}
			</style>
		</head>
		<body>
			<xsl:variable name="amountOfURLs">
				<xsl:choose>
					<xsl:when test="$fileType='Sitemap'">
						<xsl:value-of select="count(sitemap:urlset/sitemap:url)"></xsl:value-of>
					</xsl:when>
					<xsl:otherwise>
						<xsl:value-of select="count(sitemap:sitemapindex/sitemap:sitemap)"></xsl:value-of>
					</xsl:otherwise>
				</xsl:choose>
			</xsl:variable>

			<xsl:call-template name="Header">
				<xsl:with-param name="title"><?php _e( 'Video Sitemap', 'aioseo-video-sitemap' ); ?></xsl:with-param>
				<xsl:with-param name="amountOfURLs" select="$amountOfURLs"/>
				<xsl:with-param name="fileType" select="$fileType"/>
			</xsl:call-template>

			<div class="content">
				<div class="container">
					<xsl:choose>
						<xsl:when test="$amountOfURLs = 0"><xsl:call-template name="emptySitemap"/></xsl:when>
						<xsl:when test="$fileType='Sitemap'"><xsl:call-template name="sitemapTable"/></xsl:when>
						<xsl:otherwise><xsl:call-template name="siteindexTable"/></xsl:otherwise>
					</xsl:choose>
				</div>
			</div>
		</body>
	</html>
	</xsl:template>

	<xsl:template name="siteindexTable">
		<?php
		$videoIndex = aioseo()->sitemap->helpers->filename( 'video' );
		$videoIndex = $videoIndex ? $videoIndex : 'video-sitemap';
		aioseo()->templates->getTemplate(
			'sitemap/xsl/partials/breadcrumb.php',
			[
				'items' => [
					[ 'title' => __( 'Video Sitemap Index', 'aioseo-video-sitemap' ), 'url' => $sitemapUrl ]
				]
			]
		);
		?>
		<div class="table-wrapper">
			<table cellpadding="3">
				<thead>
				<tr>
					<th class="left">
						<a href="<?php echo strtok( $sitemapUrl, '?' ); ?>">
							<?php _e( 'URL', 'aioseo-video-sitemap' ); ?>
						</a>
					</th>
					<th><?php _e( 'URL Count', 'aioseo-video-sitemap' ); ?></th>
					<th>
						<?php
							aioseo()->templates->getTemplate(
								'sitemap/xsl/partials/sortable-column.php',
								[
									'parameters' => $sitemapParams,
									'sitemapUrl' => $sitemapUrl,
									'column'     => 'date',
									'title'      => __( 'Last Updated', 'aioseo-video-sitemap' )
								]
							);
						?>
					</th>
				</tr>
				</thead>
				<tbody>
				<xsl:variable name="lower" select="'abcdefghijklmnopqrstuvwxyz'"/>
				<xsl:variable name="upper" select="'ABCDEFGHIJKLMNOPQRSTUVWXYZ'"/>
				<xsl:for-each select="sitemap:sitemapindex/sitemap:sitemap">
					<?php
						aioseo()->templates->getTemplate(
							'sitemap/xsl/partials/xsl-sort.php',
							[
								'parameters' => $sitemapParams,
								'node'       => 'sitemap:lastmod',
							]
						);
					?>
					<tr>
						<xsl:if test="position() mod 2 != 1">
							<xsl:attribute name="class">stripe</xsl:attribute>
						</xsl:if>
						<td class="left">
							<a>
								<xsl:attribute name="href">
									<xsl:value-of select="sitemap:loc" />
								</xsl:attribute>
								<xsl:value-of select="sitemap:loc"/>
							</a>
						</td>
						<td>
							<?php if ( ! empty( $xslParams['counts'] ) ) : ?>
							<div class="item-count">
							<xsl:choose>
								<?php foreach ( $xslParams['counts'] as $slug => $count ) : ?>
									<xsl:when test="contains(sitemap:loc, '<?php echo $slug; ?>')"><?php echo $count; ?></xsl:when>
								<?php endforeach; ?>
								<xsl:otherwise><?php echo $linksPerIndex; ?></xsl:otherwise>
							</xsl:choose>
							</div>
							<?php endif; ?>
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
	</xsl:template>

	<xsl:template name="sitemapTable">
		<xsl:variable name="sitemapType">
			<xsl:for-each select="/*/namespace::*">
				<xsl:if test="name()='video'">
					<xsl:choose>
						<xsl:when test="name()='video'">video</xsl:when>
					</xsl:choose>
				</xsl:if>
			</xsl:for-each>
		</xsl:variable>

		<?php
		$videoIndex = aioseo()->sitemap->helpers->filename( 'video' );
		$videoIndex = $videoIndex ? $videoIndex : 'video-sitemap';
		aioseo()->templates->getTemplate(
			'sitemap/xsl/partials/breadcrumb.php',
			[
				'items' => [
					[ 'title' => __( 'Video Sitemap Index', 'aioseo-video-sitemap' ), 'url' => home_url( "/$videoIndex.xml" ) ],
					[ 'title' => $title, 'url' => $sitemapUrl ],
				]
			]
		);
		?>
		<div class="table-wrapper">
			<table cellpadding="3">
				<thead>
				<tr>
					<th class="left">
						<a href="<?php echo strtok( $sitemapUrl, '?' ); ?>">
							<?php _e( 'URL', 'aioseo-video-sitemap' ); ?>
						</a>
					</th>
					<th><?php _e( 'Video', 'aioseo-video-sitemap' ); ?></th>
					<th class="left"><?php _e( 'Video Thumbnail', 'aioseo-video-sitemap' ); ?></th>
					<th>
						<?php
							aioseo()->templates->getTemplate(
								'sitemap/xsl/partials/sortable-column.php',
								[
									'parameters' => $sitemapParams,
									'sitemapUrl' => $sitemapUrl,
									'column'     => 'date',
									'title'      => __( 'Last Updated', 'aioseo-video-sitemap' )
								]
							);
						?>
					</th>
				</tr>
				</thead>
				<tbody>
				<xsl:variable name="lower" select="'abcdefghijklmnopqrstuvwxyz'"/>
				<xsl:variable name="upper" select="'ABCDEFGHIJKLMNOPQRSTUVWXYZ'"/>
				<xsl:for-each select="sitemap:urlset/sitemap:url">
					<?php
						aioseo()->templates->getTemplate(
							'sitemap/xsl/partials/xsl-sort.php',
							[
								'parameters' => $sitemapParams,
								'node'       => 'sitemap:lastmod',
							]
						);
					?>
					<tr>
						<xsl:if test="position() mod 2 != 1">
							<xsl:attribute name="class">stripe</xsl:attribute>
						</xsl:if>
						<td class="left">
							<a>
								<xsl:attribute name="href">
									<xsl:value-of select="sitemap:loc" />
								</xsl:attribute>
								<xsl:value-of select="sitemap:loc"/>
							</a>
						</td>
						<xsl:if test="$sitemapType='video'">
							<td>
								<div class="item-count">
									<xsl:value-of select="count(video:video)"/>
								</div>
							</td>
							<td>
								<xsl:if test="$sitemapType='video'">
									<div class="videos-column">
										<div class="videos">
											<xsl:for-each select="video:video">
												<xsl:if test="position() &lt;= 2">
													<xsl:variable name="thumbURL">
														<xsl:value-of select="video:thumbnail_loc"/>
													</xsl:variable>
													<xsl:variable name="playURL">
														<xsl:value-of select="video:player_loc"/>
													</xsl:variable>
													<xsl:if test="$thumbURL != ''">
														<a href="{$playURL}" target="_new"><img src="{$thumbURL}"/></a>
													</xsl:if>
												</xsl:if>
											</xsl:for-each>
										</div>
										<xsl:if test="count(video:video) &gt; 2">
											<a class="open-modal">
												<xsl:attribute name="href">
													<xsl:value-of select="concat('#modal-', position())" />
												</xsl:attribute>
												+<xsl:value-of select="count(video:video)-2" />
											</a>
											<div class="modal-window">
												<xsl:attribute name="id">
													<xsl:value-of select="concat('modal-', position())" />
												</xsl:attribute>
												<div>
													<a href="#" title="Close" class="modal-close">
														<svg width="15" height="15" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M14.667 1.91L13.257.5l-5.59 5.59L2.077.5.667 1.91l5.59 5.59-5.59 5.59 1.41 1.41 5.59-5.59 5.59 5.59 1.41-1.41-5.59-5.59 5.59-5.59z" fill="#8C8F9A"/></svg>
													</a>
													<div class="title">
														<?php
														// Translators: 1 - The amount of videos.
														echo sprintf( esc_html__( 'There are %s videos at the following URL:', 'aioseo-video-sitemap' ), '<xsl:value-of select="count(video:video)"/>' );
														?>
													</div>
													<a class="link">
														<xsl:attribute name="href">
															<xsl:value-of select="sitemap:loc" />
														</xsl:attribute>
														<xsl:value-of select="sitemap:loc"/>
													</a>
													<div class="videos">
														<xsl:for-each select="video:video">
															<xsl:variable name="thumbURL">
																<xsl:value-of select="video:thumbnail_loc"/>
															</xsl:variable>
															<xsl:variable name="playURL">
																<xsl:value-of select="video:player_loc"/>
															</xsl:variable>
															<xsl:if test="$thumbURL != ''">
																<a href="{$playURL}" target="_new"><img src="{$thumbURL}"/></a>
															</xsl:if>
														</xsl:for-each>
													</div>
												</div>
											</div>
										</xsl:if>
									</div>
								</xsl:if>
							</td>
						</xsl:if>
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
					'currentPage'   => $currentPage,
					'linksPerIndex' => $linksPerIndex,
					'total'         => $xslParams['pagination']['total'],
					'showing'       => $xslParams['pagination']['showing']
				]
			);
		}
		?>
	</xsl:template>

	<?php aioseo()->templates->getTemplate( 'sitemap/xsl/templates/header.php', [ 'utmMedium' => 'video-sitemap' ] ); ?>
	<?php
	aioseo()->templates->getTemplate( 'sitemap/xsl/templates/empty-sitemap.php', [
		'utmMedium' => 'video-sitemap',
		'items'     => [
			[ 'title' => __( 'Video Sitemap Index', 'aioseo-video-sitemap' ), 'url' => $sitemapUrl ]
		]
	] );
	?>
</xsl:stylesheet>