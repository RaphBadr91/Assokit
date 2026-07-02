<?php
namespace AIOSEO\Plugin\Addon\NewsSitemap\NewsSitemap;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetrches the posts for the News Sitemap from the DB.
 *
 * @since 1.0.8
 */
class Query {
	/**
	 * Returns all eligble sitemap entries.
	 *
	 * @since 1.0.8
	 *
	 * @param  mixed $postTypes      The post type(s). Either a singular string or an array of strings.
	 * @param  array $additionalArgs Any additional arguments for the post query.
	 * @return array|int             The post objects or the post count.
	 */
	public function posts( $postTypes, $additionalArgs = [] ) {
		$includedPostTypes = $postTypes;
		if ( is_array( $postTypes ) ) {
			$includedPostTypes = implode( "', '", $postTypes );
		}

		if ( empty( $includedPostTypes ) ) {
			return [];
		}

		// Set defaults.
		$fields = 'p.ID, p.post_title, p.post_type, p.post_password, p.post_date_gmt';

		// Override defaults if passed as additional arg.
		foreach ( $additionalArgs as $name => $value ) {
			// Attachments need to be fetched with all their fields because we need to get their post parent further down the line.
			$$name = esc_sql( $value );
			if ( 'count' === $name && $value ) {
				$fields = 'count(p.ID) as total';
			}
		}

		$query = aioseo()->core->db
			->start( aioseo()->core->db->db->posts . ' as p', true )
			->select( $fields )
			->leftJoin( 'aioseo_posts as ap', 'ap.post_id = p.ID' )
			->where( 'p.post_status', 'attachment' === $includedPostTypes ? 'inherit' : 'publish' )
			->whereRaw( "p.post_type IN ( '$includedPostTypes' )" );

		if ( ! is_array( $postTypes ) ) {
			if ( ! aioseo()->helpers->isPostTypeNoindexed( $includedPostTypes ) ) {
				$query->whereRaw( '( `ap`.`robots_noindex` IS NULL OR `ap`.`robots_default` = 1 OR `ap`.`robots_noindex` = 0 )' );
			} else {
				$query->whereRaw( '( `ap`.`robots_default` = 0 AND `ap`.`robots_noindex` = 0 )' );
			}
		} else {
			$robotsMetaSql = [];
			foreach ( $postTypes as $postType ) {
				if ( ! aioseo()->helpers->isPostTypeNoindexed( $postType ) ) {
					$robotsMetaSql[] = "( `p`.`post_type` = '$postType' AND ( `ap`.`robots_noindex` IS NULL OR `ap`.`robots_default` = 1 OR `ap`.`robots_noindex` = 0 ) )";
				} else {
					$robotsMetaSql[] = "( `p`.`post_type` = '$postType' AND ( `ap`.`robots_default` = 0 AND `ap`.`robots_noindex` = 0 ) )";
				}
			}
			$query->whereRaw( '( ' . implode( ' OR ', $robotsMetaSql ) . ' )' );
		}

		$excludedPosts = aioseo()->sitemap->helpers->excludedPosts();
		if ( $excludedPosts ) {
			$query->whereRaw( "( `p`.`ID` NOT IN ( $excludedPosts ) )" );
		}

		$maxAge = gmdate( 'Y-m-d H:i:s', strtotime( '-48 hours' ) );
		$query->whereRaw( "( `p`.`post_date_gmt` >= '$maxAge' )" );

		if (
			aioseo()->sitemap->indexes &&
			empty( $additionalArgs['root'] ) &&
			( empty( $additionalArgs['count'] ) || ! $additionalArgs['count'] )
		) {
			$query->limit( aioseo()->sitemap->linksPerIndex, aioseo()->sitemap->offset );
		}

		$query->orderBy( 'p.post_date_gmt DESC' );

		// Return the total if we are just counting the posts.
		if ( ! empty( $additionalArgs['count'] ) && $additionalArgs['count'] ) {
			return (int) $query->run( true, 'var' )
				->result();
		}

		$posts = $query->run()
			->result();

		// Convert ID from string to int.
		foreach ( $posts as $post ) {
			$post->ID = (int) $post->ID;
		}

		return $posts;
	}
}